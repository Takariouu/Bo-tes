<?php

declare(strict_types=1);

namespace Bootes\Config;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $instance = null;

    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 3306;
    private const DEFAULT_DB   = 'HEBERGEUR_MC';
    private const DEFAULT_USER = 'bootes_user';
    private const DEFAULT_PASS = '';

    private function __construct()
    {
    }

    private function __clone(): void
    {
    }

    public function __wakeup(): void
    {
        throw new RuntimeException('La désérialisation du Singleton Database est interdite.');
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: self::DEFAULT_HOST;
            $port = (int) (getenv('DB_PORT') ?: self::DEFAULT_PORT);
            $db   = getenv('DB_NAME') ?: self::DEFAULT_DB;
            $user = getenv('DB_USER') ?: self::DEFAULT_USER;
            $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : self::DEFAULT_PASS;

            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                error_log(sprintf('[Boötes][Database Error] %s', $e->getMessage()));
                throw new RuntimeException('Erreur critique de connexion à la base de données MariaDB.');
            }
        }

        return self::$instance;
    }
}