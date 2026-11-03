<?php

declare(strict_types=1);

/**
 * Routeur du serveur PHP intégré pour le développement local (voir dev/start.sh).
 * Reproduit les règles du .htaccess : fichiers cachés interdits, seuls les fichiers
 * statiques de /assets sont servis directement, tout le reste passe par index.php.
 */

$root = dirname(__DIR__);
$path = ltrim(rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)), '/');

// Fichiers et dossiers cachés (.git, .env, .devdata...)
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
    // Tout autre fichier ou dossier existant est interdit (config, src, pages, storage...)
    if ($path !== '' && file_exists($root . '/' . $path)) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
    $_GET['page'] = $path;
}

chdir($root);
require $root . '/index.php';

return true;
