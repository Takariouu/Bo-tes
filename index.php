<?php

declare(strict_types=1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Bootes\\Src\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use Bootes\Config\AppConfig;
use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Http;
use Bootes\Src\Security;
use Bootes\Src\ServerManager;
use Bootes\Src\View;

AppConfig::load();
if (AppConfig::isProduction()) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

Http::sendSecurityHeaders();
Auth::initSession();

/**
 * Définition de la table de routage et des politiques de contrôle d'accès (RBAC).
 * Basé sur l'arborescence des vues dans /pages/. Le libellé court sert au titre de la page et au fil d'Ariane.
 */
$routes = [
    // Routes publiques
    'home'                      => ['file' => '/pages/public/home.php',                  'access' => 'PUBLIC',      'label' => 'Accueil'],
    'auth'                      => ['file' => '/pages/public/auth.php',                  'access' => 'PUBLIC',      'label' => 'Connexion'],
    'catalogue'                 => ['file' => '/pages/public/catalogue.php',             'access' => 'PUBLIC',      'label' => 'Catalogue'],
    'cgu'                       => ['file' => '/pages/public/cgu.php',                   'access' => 'PUBLIC',      'label' => 'Conditions générales'],
    'offres'                    => ['file' => '/pages/public/offres.php',                'access' => 'PUBLIC',      'label' => 'Offres d\'hébergement'],
    'ressource-detail'          => ['file' => '/pages/public/ressource-detail.php',      'access' => 'PUBLIC',      'label' => 'Fiche ressource'],
    'serveur-public'            => ['file' => '/pages/public/serveur-public.php',        'access' => 'PUBLIC',      'label' => 'Fiche serveur'],

    // Routes espace utilisateur & créateur
    'user/compte'               => ['file' => '/pages/user/compte.php',                  'access' => 'USER',        'label' => 'Mon compte'],
    'user/createur-edition'     => ['file' => '/pages/user/createur-edition.php',        'access' => 'USER',        'label' => 'Atelier créateur'],
    'user/createur-statistiques'=> ['file' => '/pages/user/createur-statistiques.php',   'access' => 'USER',        'label' => 'Statistiques créateur'],

    // Routes de gestion d'un serveur Minecraft spécifique
    'serveur/dashboard'         => ['file' => '/pages/serveur/dashboard.php',            'access' => 'SERVER',      'label' => 'Tableau de bord'],
    'serveur/console'           => ['file' => '/pages/serveur/console.php',              'access' => 'SERVER',      'label' => 'Console'],
    'serveur/logs'              => ['file' => '/pages/serveur/logs.php',                 'access' => 'SERVER',      'label' => 'Journaux'],
    'serveur/map'               => ['file' => '/pages/serveur/map.php',                  'access' => 'SERVER',      'label' => 'Carte'],
    'serveur/backups'           => ['file' => '/pages/serveur/backups.php',              'access' => 'SERVER',      'label' => 'Sauvegardes'],
    'serveur/joueurs'           => ['file' => '/pages/serveur/joueurs.php',              'access' => 'SERVER',      'label' => 'Joueurs'],
    'serveur/acces-admin'       => ['file' => '/pages/serveur/acces-admin.php',          'access' => 'SERVER',      'label' => 'Accès et co-admins'],
    'serveur/statistiques'      => ['file' => '/pages/serveur/statistiques.php',         'access' => 'SERVER',      'label' => 'Statistiques'],

    // Routes d'administration et de supervision
    'admin/dashboard'           => ['file' => '/pages/admin/dashboard.php',              'access' => 'ADMIN',       'label' => 'Vue d\'ensemble'],
    'admin/hardware'            => ['file' => '/pages/admin/hardware.php',               'access' => 'ADMIN',       'label' => 'Supervision matérielle'],
    'admin/utilisateurs'        => ['file' => '/pages/admin/utilisateurs.php',           'access' => 'ADMIN',       'label' => 'Utilisateurs et contenus'],
    'admin/signalements'        => ['file' => '/pages/admin/signalements.php',           'access' => 'ADMIN',       'label' => 'Modération'],
    'admin/super-admin'         => ['file' => '/pages/admin/super-admin.php',            'access' => 'SUPER_ADMIN', 'label' => 'Super-admin et journaux'],
];

/**
 * Fil d'Ariane par défaut d'une route (une vue peut le remplacer en définissant $pageBreadcrumbs).
 *
 * @param array<string, mixed>|null $server Serveur affiché pour les routes serveur/*
 * @return array<int, array{label: string, url: ?string}>
 */
function defaultBreadcrumbs(string $route, string $label, ?array $server): array
{
    $trail = [['label' => 'Accueil', 'url' => '/home']];

    if (str_starts_with($route, 'serveur/')) {
        $trail[] = ['label' => 'Mes serveurs', 'url' => '/user/compte#section-serveurs'];
        if ($server !== null) {
            $trail[] = ['label' => (string) $server['nom_serveur'], 'url' => '/serveur/dashboard?id=' . (int) $server['id_serveur']];
        }
    } elseif (str_starts_with($route, 'admin/') && $route !== 'admin/dashboard') {
        $trail[] = ['label' => 'Administration', 'url' => '/admin/dashboard'];
    } elseif (str_starts_with($route, 'user/') && $route !== 'user/compte') {
        $trail[] = ['label' => 'Mon compte', 'url' => '/user/compte'];
    } elseif ($route === 'ressource-detail') {
        $trail[] = ['label' => 'Catalogue', 'url' => '/catalogue'];
    } elseif ($route === 'serveur-public') {
        $trail[] = ['label' => 'Serveurs', 'url' => '/catalogue?type=serveur'];
    }

    $trail[] = ['label' => $label, 'url' => null];

    return $trail;
}

/**
 * Rendu sémantique des pages d'erreur HTTP (403, 404, 500, 503).
 * Toute sortie partielle de la page en cours est abandonnée.
 */
function renderHttpError(int $statusCode, string $heading, string $message, string $currentRoute): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    $pageTitle = sprintf('Erreur %d — Boötes', $statusCode);

    // Illustration : le « 0 » central du code est remplacé par le symbole Boötes
    $digits   = str_split((string) $statusCode);
    $hint     = match ($statusCode) {
        403     => ['lock', 'Accès protégé'],
        404     => ['telescope', 'Perdu dans le vide'],
        503     => ['wifi-off', 'Liaison interrompue'],
        default => ['satellite', 'Incident technique'],
    };

    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/sidebar.php';
    ?>
    <main id="contenu" class="main main--error" role="main" tabindex="-1">
        <article class="error-panel">
            <p class="error-panel__code" aria-hidden="true">
                <span><?= Security::e($digits[0] ?? '') ?></span>
                <?php if (($digits[1] ?? '') === '0'): ?>
                    <span class="error-panel__orb"></span>
                <?php else: ?>
                    <span><?= Security::e($digits[1] ?? '') ?></span>
                <?php endif; ?>
                <span><?= Security::e($digits[2] ?? '') ?></span>
            </p>
            <header class="error-panel__header">
                <p class="error-panel__hint">
                    <?= View::icon($hint[0]) ?>
                    <?= Security::e($hint[1]) ?> &middot; Code HTTP <?= (int) $statusCode ?>
                </p>
                <h1 class="error-panel__title"><?= Security::e($heading) ?></h1>
            </header>
            <div class="error-panel__body">
                <p class="error-panel__message"><?= Security::e($message) ?></p>
            </div>
            <footer class="error-panel__footer">
                <a href="/home" class="error-panel__link"><?= View::icon('house') ?> Retourner à l'accueil</a>
                <?php if ($statusCode === 403 && !Auth::isLogged()): ?>
                    <a href="/auth?mode=login" class="error-panel__link error-panel__link--primary"><?= View::icon('log-in') ?> Se connecter</a>
                <?php elseif ($statusCode === 403): ?>
                    <a href="/user/compte" class="error-panel__link error-panel__link--primary"><?= View::icon('user-round') ?> Mon compte</a>
                <?php elseif ($statusCode === 404): ?>
                    <a href="/catalogue" class="error-panel__link error-panel__link--primary"><?= View::icon('blocks') ?> Explorer le catalogue</a>
                <?php endif; ?>
            </footer>
        </article>
    </main>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Erreurs non gérées : journalisation complète côté serveur, page générique côté client (aucune trace).
set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        "[Boötes][Exception] %s: %s in %s:%d\n%s",
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    try {
        renderHttpError(500, 'Erreur interne', 'Une erreur inattendue est survenue. L\'incident a été journalisé, veuillez réessayer plus tard.', 'home');
    } catch (Throwable) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'Erreur interne du serveur.';
    }
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Erreur 500 — Boötes</title></head>'
        . '<body><h1>Erreur interne</h1><p>Le service est momentanément indisponible.</p></body></html>';
});

// 1. Récupération et nettoyage de la route demandée
$rawPage      = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'home';
$currentRoute = Security::sanitizeRoute($rawPage);

// Compatibilité avec les appels courts (ex: /public/home -> home)
if (str_starts_with($currentRoute, 'public/')) {
    $currentRoute = substr($currentRoute, strlen('public/'));
}

// 2. Vérification de l'existence de la route dans la table de routage
if ($currentRoute === '404' || !isset($routes[$currentRoute])) {
    renderHttpError(
        404,
        'Page introuvable',
        'La ressource ou la page que vous cherchez à atteindre n\'existe pas sur le panel Boötes.',
        $currentRoute
    );
}

$routeConfig       = $routes[$currentRoute];
$requiredAccess    = $routeConfig['access'];
$pageTitle         = $currentRoute === 'home'
    ? 'Boötes — Hébergement de serveurs Minecraft'
    : $routeConfig['label'] . ' — Boötes';
$targetFile        = __DIR__ . $routeConfig['file'];
$activeServerId    = null;
$serverAccessLevel = null;

// 3. Connexion à la base de données MariaDB
try {
    $db = Database::getInstance();
} catch (RuntimeException $e) {
    renderHttpError(
        503,
        'Service temporairement indisponible',
        'Impossible d\'établir la liaison avec la base de données HEBERGEUR_MC.',
        $currentRoute
    );
}

// 4. Resynchronisation de la session avec la base (bannissement, suppression, changement de rôle)
Auth::refreshSession($db);
if (Auth::isLogged()) {
    Http::sendNoStore();
}

// 5. Contrôle strict des permissions via Auth selon le niveau requis par la route
switch ($requiredAccess) {
    case 'PUBLIC':
        break;

    case 'USER':
        if (!Auth::isLogged()) {
            renderHttpError(
                403,
                'Authentification requise',
                'Vous devez être connecté à votre compte Boötes pour accéder à cet espace.',
                $currentRoute
            );
        }
        break;

    case 'SERVER':
        if (!Auth::isLogged()) {
            renderHttpError(
                403,
                'Authentification requise',
                'Veuillez vous connecter pour administrer un serveur Minecraft.',
                $currentRoute
            );
        }

        $activeServerId = Security::validatePositiveInt($_GET['id'] ?? $_GET['server_id'] ?? $_POST['server_id'] ?? null);
        $currentUserId  = Auth::getUserId();

        if ($activeServerId === null || $currentUserId === null) {
            renderHttpError(
                404,
                'Serveur non spécifié',
                'Aucun identifiant de serveur valide n\'a été fourni dans la requête.',
                $currentRoute
            );
        }

        $serverAccessLevel = Auth::getServerAccessLevel($currentUserId, $activeServerId, $db);
        if ($serverAccessLevel === null) {
            renderHttpError(
                403,
                'Accès refusé au serveur',
                'Vous n\'êtes ni propriétaire, ni co-administrateur de ce serveur Minecraft, ni administrateur global.',
                $currentRoute
            );
        }
        break;

    case 'ADMIN':
        if (!Auth::isAdmin()) {
            renderHttpError(
                403,
                'Accès réservé aux Administrateurs',
                'Vos privilèges actuels ne permettent pas d\'accéder aux outils de modération et de supervision matérielle.',
                $currentRoute
            );
        }
        break;

    case 'SUPER_ADMIN':
        if (!Auth::isSuperAdmin()) {
            renderHttpError(
                403,
                'Accès réservé au Super-Administrateur',
                'Seul un Super-Administrateur est habilité à gérer les administrateurs et consulter les journaux système.',
                $currentRoute
            );
        }
        break;
}

// 6. Vérification physique du fichier de vue avant inclusion
if (!is_file($targetFile) || !is_readable($targetFile)) {
    renderHttpError(
        404,
        'Vue introuvable',
        'Le fichier de vue associé à cette route est manquant sur le serveur.',
        $currentRoute
    );
}

// 7. Exécution de la vue AVANT tout envoi de HTML : les traitements POST peuvent ainsi rediriger,
//    régénérer la session ou transmettre un fichier, et l'en-tête reflète l'état à jour.
ob_start();
require $targetFile;
$pageContent  = (string) ob_get_clean();
$flashMessage = Http::consumeFlash();

// 8. Contexte de mise en page, calculé après la vue pour refléter son traitement (démarrage d'un serveur...)
$layoutContext = match (true) {
    str_starts_with($currentRoute, 'serveur/') => 'server',
    str_starts_with($currentRoute, 'admin/')   => 'admin',
    str_starts_with($currentRoute, 'user/')    => 'account',
    default                                     => null,
};

$activeServer   = null;
$pendingReports = 0;
if ($layoutContext === 'server' && $activeServerId !== null) {
    $activeServer = (new ServerManager($db))->getServerById($activeServerId);
    if ($activeServer !== null) {
        $pageTitle = sprintf('%s · %s — Boötes', $routeConfig['label'], (string) $activeServer['nom_serveur']);
    }
} elseif ($layoutContext === 'admin') {
    $pendingReports = (int) $db->query("SELECT COUNT(*) FROM SIGNALEMENT WHERE statut = 'EN_ATTENTE'")->fetchColumn();
}

$breadcrumbs = isset($pageBreadcrumbs) && is_array($pageBreadcrumbs)
    ? $pageBreadcrumbs
    : defaultBreadcrumbs($currentRoute, $routeConfig['label'], $activeServer);

// 9. Assemblage de la réponse HTML5 sémantique
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<main id="contenu" class="main" role="main" tabindex="-1">
    <?php require __DIR__ . '/includes/breadcrumb.php'; ?>
    <?php if ($flashMessage !== null): ?>
        <div class="toast-region" role="status" aria-live="polite">
            <div class="toast toast--success" data-toast>
                <?= View::icon('circle-check', 'toast__icon') ?>
                <p class="toast__message"><?= Security::e($flashMessage) ?></p>
                <button type="button" class="toast__close" data-toast-close aria-label="Fermer le message"><?= View::icon('x') ?></button>
            </div>
        </div>
    <?php endif; ?>
    <?= $pageContent ?>
</main>
<?php
require __DIR__ . '/includes/footer.php';
