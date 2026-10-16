<?php

declare(strict_types=1);

/**
 * Classe utilitaire statique pour la sécurité applicative (CSRF, XSS, Validation système).
 */
final class Security
{
    public const MIN_MC_PORT = 25565;
    public const MAX_MC_PORT = 25585;
    private const CSRF_SESSION_KEY = 'bootes_csrf_token';

    private function __construct()
    {
    }

    /**
     * Génère ou récupère le jeton CSRF de la session courante.
     *
     * @return string
     */
    public static function generateCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('La session PHP doit être démarrée avant de générer un token CSRF.');
        }

        if (empty($_SESSION[self::CSRF_SESSION_KEY]) || !is_string($_SESSION[self::CSRF_SESSION_KEY])) {$_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_SESSION_KEY];
    }

    /**
     * Vérifie la validité d'un jeton CSRF soumis via un formulaire ou une requête AJAX.
     *
     * @param string|null $token Le jeton reçu.
     * @return bool
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        if (empty($token) \vert{}\vert{} empty($_SESSION[self::CSRF_SESSION_KEY])) {
            return false;
        }

        return hash_equals((string) $_SESSION[self::CSRF_SESSION_KEY],$token);
    }

    /**
     * Renouvelle immédiatement le token CSRF après une action critique réussie.
     */
    public static function refreshCsrfToken(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Nettoie une chaîne de caractères pour l'affichage HTML sécurisé (anti-XSS).
     *
     * @param string $input
     * @return string
     */
    public static function sanitizeString(string $input): string
    {
        $cleaned = str_replace("\0", '', trim($input));
        return htmlspecialchars($cleaned, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Valide qu'une chaîne ne contient que des caractères alphanumériques, tirets ou underscores.
     * Idéal pour les noms de dossiers, pseudos et noms techniques de serveurs.
     *
     * @param string $input
     * @param int $minLength
     * @param int $maxLength
     * @return bool
     */
    public static function validateAlphanumericDash(string $input, int $minLength = 3, int$maxLength = 32): bool
    {
        $length = strlen($input);
        if ($length <$minLength || $length >$maxLength) {
            return false;
        }

        return (bool) preg_match('/^[a-zA-Z0-9_-]+$/',$input);
    }

    /**
     * Vérifie qu'une commande console Minecraft ne contient pas de caractères d'injection shell.
     *
     * @param string $command
     * @return string
     * @throws InvalidArgumentException Si la commande est invalide.
     */
    public static function sanitizeConsoleCommand(string $command): string
    {
        $command = trim( ltrim($command, '/') );
        if ($command === '' \vert{}\vert{} strlen($command) > 255) {
            throw new InvalidArgumentException('La commande doit contenir entre 1 et 255 caractères.');
        }

        // Interdire les caractères de contrôle et d'enchaînement shell (; | & ` $ > <)
        if (preg_match('/[;|&`$><\r\n\0]/',$command)) {
            throw new InvalidArgumentException('Caractères interdits détectés dans la commande.');
        }

        return $command;
    }

    /**
     * Valide strictement qu'un port réseau Minecraft est compris entre 25565 et 25585.
     *
     * @param int|string $port
     * @return bool
     */
    public static function validateMinecraftPort(int|string $port): bool
    {
        $validatedPort = filter_var($port, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => self::MIN_MC_PORT,
                'max_range' => self::MAX_MC_PORT,
            ],
        ]);

        return $validatedPort !== false;
    }
}