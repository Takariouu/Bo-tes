<?php

declare(strict_types=1);

/**
 * Tâche planifiée : actualise le statut (en ligne, joueurs, version) des serveurs externes de l'annuaire.
 * Les plus anciennement vérifiés passent en premier ; 50 serveurs au plus par exécution.
 *
 * Exemple de crontab (toutes les 5 minutes) :
 *   0-59/5 * * * * php /var/www/bootes/cron/verifier-annuaire.php >/dev/null 2>&1
 *
 * Accessible en ligne de commande uniquement (le dossier est refusé par .htaccess et dev/router.php).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Bootes\\Src\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use Bootes\Config\AppConfig;
use Bootes\Config\Database;
use Bootes\Src\ServerDirectory;

AppConfig::load();

$directory = new ServerDirectory(Database::getInstance());
$checked   = 0;
foreach ($directory->dueForCheck() as $server) {
    if ($directory->refreshStatus($server)) {
        $checked++;
    }
}

fwrite(STDOUT, sprintf("[Boötes][Annuaire] %d serveur(s) vérifié(s).\n", $checked));
