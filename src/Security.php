<?php

declare(strict_types=1);

namespace Bootes\Src;

final class Security
{
    private const CSRF_SESSION_KEY = 'bootes_csrf_token';
    private const CSRF_TOKEN_TTL   = 3600;
    private const MIN_MC_PORT      = 1024;
    private const MAX_MC_PORT      = 65535;
    private const MAX_TOTAL_RAM_MB = 6144;
    private const MAX_STD_RAM_MB   = 2048;

    private function __construct()
    {
    }

    /**
     * Échappement strict contre les failles XSS.
     */
    public static function e(?string $val): string
    {
        if ($val === null || $val === '') {
            return '';
        }

        return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Génère ou récupère un jeton CSRF cryptographiquement sûr.
     * Aucun repli prévisible : si le CSPRNG est indisponible, l'exception interrompt la requête.
     */
    public static function generateCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Auth::initSession();
        }

        $now = time();
        if (
            empty($_SESSION[self::CSRF_SESSION_KEY]['token']) ||
            empty($_SESSION[self::CSRF_SESSION_KEY]['expires_at']) ||
            $now >= (int) $_SESSION[self::CSRF_SESSION_KEY]['expires_at']
        ) {
            $_SESSION[self::CSRF_SESSION_KEY] = [
                'token'      => bin2hex(random_bytes(32)),
                'expires_at' => $now + self::CSRF_TOKEN_TTL,
            ];
        }

        return (string) $_SESSION[self::CSRF_SESSION_KEY]['token'];
    }

    /**
     * Invalide le jeton CSRF courant (changement de privilèges : connexion, déconnexion).
     */
    public static function rotateCsrfToken(): void
    {
        unset($_SESSION[self::CSRF_SESSION_KEY]);
    }

    /**
     * Vérifie la validité temporelle et la correspondance constante du jeton CSRF.
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Auth::initSession();
        }

        if (
            $token === null ||
            $token === '' ||
            empty($_SESSION[self::CSRF_SESSION_KEY]['token']) ||
            empty($_SESSION[self::CSRF_SESSION_KEY]['expires_at'])
        ) {
            return false;
        }

        if (time() >= (int) $_SESSION[self::CSRF_SESSION_KEY]['expires_at']) {
            unset($_SESSION[self::CSRF_SESSION_KEY]);
            return false;
        }

        return hash_equals((string) $_SESSION[self::CSRF_SESSION_KEY]['token'], $token);
    }

    /**
     * Valide un entier strictement positif (identifiants, pagination, etc.).
     */
    public static function validatePositiveInt(mixed $value): ?int
    {
        $filtered = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $filtered !== false ? (int) $filtered : null;
    }

    /**
     * Valide un port réseau destiné à un serveur Minecraft (entier strict entre 1024 et 65535).
     */
    public static function validatePort(mixed $port): ?int
    {
        $filtered = filter_var($port, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => self::MIN_MC_PORT,
                'max_range' => self::MAX_MC_PORT,
            ],
        ]);

        return $filtered !== false ? (int) $filtered : null;
    }

    /**
     * Valide l'allocation de RAM selon le profil du compte et le plafond physique de 6 Go (6144 Mo).
     */
    public static function validateRamAllocation(mixed $ramMb, bool $isPremium = false): ?int
    {
        $maxAllowed = $isPremium ? self::MAX_TOTAL_RAM_MB : self::MAX_STD_RAM_MB;

        $filtered = filter_var($ramMb, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 512,
                'max_range' => $maxAllowed,
            ],
        ]);

        return $filtered !== false ? (int) $filtered : null;
    }

    /**
     * Nettoie et valide une chaîne de caractères selon une longueur minimale et maximale.
     */
    public static function validateString(mixed $input, int $minLength = 1, int $maxLength = 255): ?string
    {
        if (!is_string($input)) {
            return null;
        }

        $cleaned = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $input) ?? '');
        $length  = mb_strlen($cleaned, 'UTF-8');

        if ($length < $minLength || $length > $maxLength) {
            return null;
        }

        return $cleaned;
    }

    /**
     * Construit un motif LIKE « contient » dont les jokers % et _ saisis par l'utilisateur sont neutralisés.
     * À utiliser avec la clause ESCAPE '!' (portable MariaDB / SQLite).
     */
    public static function likeContains(string $term): string
    {
        return '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /**
     * Valide le format d'une route interne afin d'empêcher toute attaque par Path Traversal (LFI).
     */
    public static function sanitizeRoute(?string $route): string
    {
        if ($route === null || $route === '') {
            return 'home';
        }

        $route = trim($route, '/');
        if (!preg_match('/^[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)?$/', $route)) {
            return '404';
        }

        return strtolower($route);
    }
}