<?php

declare(strict_types=1);

namespace Bootes\Src;

/**
 * Utilitaires de réponse HTTP : en-têtes de sécurité, redirections, messages flash et envoi de fichiers.
 */
final class Http
{
    private const FLASH_SESSION_KEY = 'bootes_flash';

    private const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    private function __construct()
    {
    }

    /**
     * En-têtes de sécurité communs à toutes les réponses dynamiques.
     */
    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('Content-Security-Policy: ' . self::CONTENT_SECURITY_POLICY);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    /**
     * Interdit la mise en cache des pages contenant des données personnelles.
     */
    public static function sendNoStore(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('Pragma: no-cache');
        }
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }

    /**
     * Redirige vers un chemin interne en abandonnant la sortie déjà produite (motif Post/Redirect/Get).
     */
    public static function redirect(string $path, ?string $flashMessage = null): never
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            $path = '/home';
        }

        if ($flashMessage !== null) {
            self::flash($flashMessage);
        }

        self::discardOutputBuffers();
        header('Location: ' . $path, true, 302);
        exit;
    }

    /**
     * Mémorise un message de confirmation affiché à la prochaine page.
     */
    public static function flash(string $message): void
    {
        Auth::initSession();
        $_SESSION[self::FLASH_SESSION_KEY] = $message;
    }

    public static function consumeFlash(): ?string
    {
        $message = $_SESSION[self::FLASH_SESSION_KEY] ?? null;
        unset($_SESSION[self::FLASH_SESSION_KEY]);

        return is_string($message) ? $message : null;
    }

    /**
     * Transmet un fichier du stockage privé en pièce jointe, en flux (sans le charger en mémoire).
     */
    public static function sendFile(string $absolutePath, string $downloadName, string $mimeType = 'application/octet-stream'): never
    {
        self::discardOutputBuffers();

        $asciiName = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName), '._');
        if ($asciiName === '') {
            $asciiName = 'telechargement';
        }

        header('Content-Type: ' . $mimeType);
        header('X-Content-Type-Options: nosniff');
        header(sprintf('Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s', $asciiName, rawurlencode($downloadName)));
        header('Content-Length: ' . (string) filesize($absolutePath));
        header('Cache-Control: private, no-store');

        readfile($absolutePath);
        exit;
    }

    private static function discardOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
