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

AppConfig::load();
if (AppConfig::isProduction()) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

Http::sendSecurityHeaders();
Auth::initSession();

/**
 * Définition de la table de routage et des politiques de contrôle d'accès (RBAC).
 * Basé sur l'arborescence des vues dans /pages/.
 */
$routes = [
    // Routes publiques
    'home'                      => ['file' => '/pages/public/home.php',                  'access' => 'PUBLIC',      'title' => 'Accueil — Boötes'],
    'auth'                      => ['file' => '/pages/public/auth.php',                  'access' => 'PUBLIC',      'title' => 'Authentification — Boötes'],
    'catalogue'                 => ['file' => '/pages/public/catalogue.php',             'access' => 'PUBLIC',      'title' => 'Catalogue — Boötes'],
    'cgu'                       => ['file' => '/pages/public/cgu.php',                   'access' => 'PUBLIC',      'title' => 'Conditions Générales — Boötes'],
    'offres'                    => ['file' => '/pages/public/offres.php',                'access' => 'PUBLIC',      'title' => 'Offres d\'hébergement — Boötes'],
    'ressource-detail'          => ['file' => '/pages/public/ressource-detail.php',      'access' => 'PUBLIC',      'title' => 'Détail de la ressource — Boötes'],
    'serveur-public'            => ['file' => '/pages/public/serveur-public.php',        'access' => 'PUBLIC',      'title' => 'Serveurs Publics — Boötes'],

    // Routes espace utilisateur & créateur
    'user/compte'               => ['file' => '/pages/user/compte.php',                  'access' => 'USER',        'title' => 'Mon Compte — Boötes'],
    'user/createur-edition'     => ['file' => '/pages/user/createur-edition.php',        'access' => 'USER',        'title' => 'Atelier Créateur — Boötes'],
    'user/createur-statistiques'=> ['file' => '/pages/user/createur-statistiques.php',   'access' => 'USER',        'title' => 'Statistiques Créateur — Boötes'],

    // Routes de gestion d'un serveur Minecraft spécifique
    'serveur/dashboard'         => ['file' => '/pages/serveur/dashboard.php',            'access' => 'SERVER',      'title' => 'Dashboard Serveur — Boötes'],
    'serveur/console'           => ['file' => '/pages/serveur/console.php',              'access' => 'SERVER',      'title' => 'Console Serveur — Boötes'],
    'serveur/logs'              => ['file' => '/pages/serveur/logs.php',                 'access' => 'SERVER',      'title' => 'Logs Serveur — Boötes'],
    'serveur/map'               => ['file' => '/pages/serveur/map.php',                  'access' => 'SERVER',      'title' => 'Map 3D du Serveur — Boötes'],
    'serveur/backups'           => ['file' => '/pages/serveur/backups.php',              'access' => 'SERVER',      'title' => 'Backups Serveur — Boötes'],
    'serveur/joueurs'           => ['file' => '/pages/serveur/joueurs.php',              'access' => 'SERVER',      'title' => 'Joueurs & Whitelist — Boötes'],
    'serveur/acces-admin'       => ['file' => '/pages/serveur/acces-admin.php',          'access' => 'SERVER',      'title' => 'Accès & Co-Admins — Boötes'],
    'serveur/statistiques'      => ['file' => '/pages/serveur/statistiques.php',         'access' => 'SERVER',      'title' => 'Statistiques Serveur — Boötes'],

    // Routes d'administration et de supervision
    'admin/dashboard'           => ['file' => '/pages/admin/dashboard.php',              'access' => 'ADMIN',       'title' => 'Administration — Boötes'],
    'admin/hardware'            => ['file' => '/pages/admin/hardware.php',               'access' => 'ADMIN',       'title' => 'Supervision Hardware — Boötes'],
    'admin/utilisateurs'        => ['file' => '/pages/admin/utilisateurs.php',           'access' => 'ADMIN',       'title' => 'Gestion Utilisateurs — Boötes'],
    'admin/signalements'        => ['file' => '/pages/admin/signalements.php',           'access' => 'ADMIN',       'title' => 'Modération Signalements — Boötes'],
    'admin/super-admin'         => ['file' => '/pages/admin/super-admin.php',            'access' => 'SUPER_ADMIN', 'title' => 'Super-Admin & Logs Système — Boötes'],
];

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

    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/sidebar.php';
    ?>
    <main class="main main--error" role="main">
        <article class="error-panel">
            <header class="error-panel__header">
                <p class="error-panel__status-code">Code HTTP <?= (int) $statusCode ?></p>
                <h1 class="error-panel__title"><?= Security::e($heading) ?></h1>
            </header>
            <div class="error-panel__body">
                <p class="error-panel__message"><?= Security::e($message) ?></p>
            </div>
            <footer class="error-panel__footer">
                <a href="/home" class="error-panel__link">Retourner à l'accueil</a>
                <?php if (!Auth::isLogged() && $statusCode === 403): ?>
                    <a href="/auth?mode=login" class="error-panel__link error-panel__link--primary">Se connecter</a>
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
$pageTitle         = $routeConfig['title'];
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

// 8. Assemblage de la réponse HTML5 sémantique
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<main class="main" role="main">
    <?php if ($flashMessage !== null): ?>
        <div class="alert alert--success" role="status">
            <p><?= Security::e($flashMessage) ?></p>
        </div>
    <?php endif; ?>
    <?= $pageContent ?>
</main>
<?php
require __DIR__ . '/includes/footer.php';
