<?php

declare(strict_types=1);

namespace Bootes\Config;

/**
 * Configuration d'exécution : variables d'environnement (fichier .env) et chemins du stockage privé.
 * Les variables définies par le serveur (SetEnv, pool PHP-FPM) sont prioritaires sur le fichier .env.
 */
final class AppConfig
{
    /** @var array<string, string> */
    private static array $fileValues = [];
    private static bool $loaded = false;

    private function __construct()
    {
    }

    /**
     * Charge le fichier .env de la racine du projet (format CLE=valeur, commentaires #).
     */
    public static function load(?string $envFile = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $envFile ??= dirname(__DIR__) . '/.env';
        if (!is_file($envFile) || !is_readable($envFile)) {
            return;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }

            $quote = $value[0] ?? '';
            if (strlen($value) >= 2 && ($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
            }

            self::$fileValues[$key] = $value;
        }
    }

    /**
     * Lit une variable de configuration (environnement serveur, puis fichier .env).
     */
    public static function env(string $key, ?string $default = null): ?string
    {
        self::load();

        $value = getenv($key);
        if ($value === false) {
            $value = $_SERVER[$key] ?? self::$fileValues[$key] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Mode production par défaut : seules les valeurs explicites de développement le désactivent.
     */
    public static function isProduction(): bool
    {
        $env = strtolower((string) self::env('APP_ENV', 'production'));

        return !in_array($env, ['development', 'dev', 'local', 'test'], true);
    }

    /**
     * Racine du stockage privé (instances, sauvegardes, fichiers téléversés).
     * En production, placez-la hors de la racine web via BOOTES_STORAGE_PATH.
     */
    public static function storagePath(string $subPath = ''): string
    {
        $base = rtrim((string) self::env('BOOTES_STORAGE_PATH', dirname(__DIR__) . '/storage'), '/\\');

        return $subPath === '' ? $base : $base . '/' . trim($subPath, '/\\');
    }

    public static function instancesPath(): string
    {
        return self::storagePath('instances');
    }

    public static function backupsPath(): string
    {
        return self::storagePath('backups');
    }

    public static function uploadsPath(): string
    {
        return self::storagePath('uploads');
    }

    /**
     * Crée un répertoire privé (droits 0750) s'il n'existe pas encore.
     */
    public static function ensureDirectory(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0750, true) || is_dir($path);
    }
}
