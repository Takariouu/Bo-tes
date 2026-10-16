<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/MinecraftServerManager.php';

Auth::initSession();

/**
 * Ajoute une notification dans la file d'attente de session pour Notification.js.
 *
 * @param string $type 'success' | 'error' | 'info'
 * @param string $message Contenu du message.
 */
function addFlashNotification(string $type, string $message): void
{
    if (!isset($_SESSION['flash_notifications']) || !is_array($_SESSION['flash_notifications'])) {
        $_SESSION['flash_notifications'] = [];
    }
    $_SESSION['flash_notifications'][] = [
        'type'    => $type,
        'message' => $message,
    ];
}

// Lecture et nettoyage de la route demandée
$rawPage = isset($_GET['page']) ? (string) $_GET['page'] : 'home';
$page    = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawPage) ?: 'home';

// Liste blanche des routes autorisées dans l'application
$allowedPages = [
    'home',
    'auth',
    'dashboard',
    'server-control',
    'server-console',
    'server-mods',
    'server-backups',
    'admin-dashboard',
];

if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'home';
    addFlashNotification('error', 'La page demandée est introuvable.');
}

// Pages nécessitant une authentification obligatoire
$privatePages = [
    'dashboard',
    'server-control',
    'server-console',
    'server-mods',
    'server-backups',
    'admin-dashboard',
];

if (in_array($page, $privatePages, true) && !Auth::isLogged()) {
    addFlashNotification('info', 'Veuillez vous connecter pour accéder à votre espace Boötes.');
    header('Location: /auth');
    exit;
}

// Protection stricte de l'espace Administrateur
if ($page === 'admin-dashboard' && !Auth::isAdmin()) {
    addFlashNotification('error', 'Accès refusé : privilèges administrateur requis.');
    header('Location: /dashboard');
    exit;
}

// Redirection automatique d'un administrateur arrivant sur auth vers son dashboard de supervision
if ($page === 'auth' && (!isset($_GET['action']) || $_GET['action'] !== 'logout') && Auth::isLogged()) {
    if (Auth::isAdmin()) {
        header('Location: /admin-dashboard');
        exit;
    }
    header('Location: /dashboard');
    exit;
}

// Inclusion de l'en-tête global
require_once __DIR__ . '/includes/header.php';

// Chargement de la vue demandée
$viewFile = __DIR__ . '/pages/' . $page . '.php';
if (is_file($viewFile)) {
    require_once $viewFile;
} else {
    echo '<main class="app-main"><div class="bento-card"><h2 class="card-title">Erreur 404</h2><p>Vue introuvable.</p></div></main>';
}

// Inclusion du pied de page global
require_once __DIR__ . '/includes/footer.php';

ob_end_flush();