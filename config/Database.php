<?php

declare(strict_types=1);

/**
 * Classe Singleton gérant la connexion PDO à MariaDB pour Boötes.
 */
final class Database
{
    private const DB_HOST = '127.0.0.1';
    private const DB_PORT = 3306;
    private const DB_NAME = 'HEBERGEUR_MC';
    private const DB_USER = 'bootes_user';
    private const DB_PASS = 'votre_mot_de_passe_solide';
    private const DB_CHARSET = 'utf8mb4';

    private static ?PDO $instance = null;

    /**
     * Constructeur privé pour empêcher l'instanciation directe via "new Database()".
     */
    private function __construct()
    {
    }

    /**
     * Empêche le clonage de l'instance.
     */
    private function __clone()
    {
    }

    /**
     * Empêche la désérialisation de l'instance.
     *
     * @throws LogicException
     */
    public function __wakeup(): void
    {
        throw new LogicException('Impossible de désérialiser un Singleton.');
    }

    /**
     * Retourne l'instance unique de connexion PDO.
     *
     * @return PDO
     * @throws RuntimeException En cas d'échec de connexion à MariaDB.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('BOOTES_DB_HOST') ?: self::DB_HOST;
            $port = (int) (getenv('BOOTES_DB_PORT') ?: self::DB_PORT);
            $dbName = getenv('BOOTES_DB_NAME') ?: self::DB_NAME;
            $user = getenv('BOOTES_DB_USER') ?: self::DB_USER;
            $pass = getenv('BOOTES_DB_PASS') ?: self::DB_PASS;

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                $port,
                $dbName,
                self::DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                error_log('[Boötes DB Error] ' . $e->getMessage());
                throw new RuntimeException('Erreur critique : connexion à la base de données indisponible.');
            }
        }

        return self::$instance;
    }
}