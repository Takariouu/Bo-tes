<?php

declare(strict_types=1);

/**
 * Routeur du serveur PHP intégré (tests uniquement).
 * Reproduit les règles du .htaccess puis délègue à index.php
 * en injectant la base SQLite de test dans le singleton Database.
 */

$root = dirname(__DIR__, 2);
$path = ltrim(rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');

// Trace des requêtes (diagnostic) : début et fin de chaque requête
$trace = getenv('BOOTES_TEST_TRACE');
if (is_string($trace) && $trace !== '') {
    $label = sprintf('%s %s %s', date('H:i:s'), $_SERVER['REQUEST_METHOD'] ?? '?', $_SERVER['REQUEST_URI'] ?? '');
    file_put_contents($trace, "BEGIN {$label}\n", FILE_APPEND);
    register_shutdown_function(static function () use ($trace, $label): void {
        file_put_contents($trace, "END   {$label} -> " . http_response_code() . "\n", FILE_APPEND);
    });
}

// Fichiers et dossiers cachés (.git, .env, .htaccess...)
if (preg_match('#(?:^|/)\.#', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Ressources statiques publiques
if (preg_match('#^assets/.+\.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?)$#i', $path) && is_file($root . '/' . $path)) {
    return false;
}

if ($path !== 'index.php') {
    // Tout autre fichier ou dossier existant est interdit
    if ($path !== '' && file_exists($root . '/' . $path)) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
    $_GET['page'] = $path;
}

require_once $root . '/config/database.php';
require_once __DIR__ . '/TestPdo.php';

$pdo = \Bootes\Tests\TestPdo::open((string) getenv('BOOTES_TEST_DB'));
$pdo->faultMarker = isset($_SERVER['HTTP_X_TEST_FAULT']) ? (string) $_SERVER['HTTP_X_TEST_FAULT'] : null;
(new ReflectionProperty(\Bootes\Config\Database::class, 'instance'))->setValue(null, $pdo);

chdir($root);
require $root . '/index.php';

return true;
