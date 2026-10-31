<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/Auth.php';

use Bootes\Config\Database;
use Bootes\Src\Auth;
use Bootes\Src\Security;

Auth::initSession();

/**
 * Définition de la table de routage et des politiques de contrôle d'accès (RBAC).
 *Basé sur l'arborescence des vues dans /pages/.
 */
$routes = [
    // Routes publiques
    'home'                      => ['file' => '/pages/public/home.php',                  'access' => 'PUBLIC',      'title' => 'Accueil — Boötes'],
    'auth'                      => ['file' => '/pages/public/auth.php',                  'access' => 'PUBLIC',      'title' => 'Authentification — Boötes'],
    'catalogue'                 => ['file' => '/pages/public/catalogue.php',             'access' => 'PUBLIC',      'title' => 'Catalogue — Boötes'],
    'cgu'                       => ['file' => '/pages/public/cgu.php',                   'access' => 'PUBLIC',      'title' => 'Conditions Générales — Boötes'],
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
 * Rendu sémantique des pages d'erreur HTTP (403, 404, 500).
 */
function renderHttpError(int $statusCode, string $heading, string $message, string $currentRoute): void
{
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

$routeConfig    = $routes[$currentRoute];
$requiredAccess = $routeConfig['access'];
$pageTitle      = $routeConfig['title'];
$targetFile     = __DIR__ . $routeConfig['file'];
$activeServerId = null;

// 3. Connexion à la base de données MariaDB
try {
    $db = Database::getInstance();
} catch (RuntimeException $e) {
    renderHttpError(
        500,
        'Service temporairement indisponible',
        'Impossible d\'établir la liaison avec la base de données HEBERGEUR_MC.',
        $currentRoute
    );
}

// 4. Contrôle strict des permissions via Auth selon le niveau requis par la route
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

        if (!Auth::canManageServer($currentUserId, $activeServerId, $db)) {
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

// 5. Vérification physique du fichier de vue avant inclusion
if (!is_file($targetFile) || !is_readable($targetFile)) {
    renderHttpError(
        404,
        'Vue introuvable',
        'Le fichier de vue associé à cette route est manquant sur le serveur.',
        $currentRoute
    );
}

// 6. Assemblage de la réponse HTML5 sémantique
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<main class="main" role="main">
    <?php require $targetFile; ?>
</main>
<?php
require __DIR__ . '/includes/footer.php';