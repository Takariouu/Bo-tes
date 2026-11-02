<?php

declare(strict_types=1);

namespace Bootes\Src;

use Bootes\Config\AppConfig;

/**
 * Limiteur de débit à fenêtre fixe, persistant dans le stockage privé.
 * Protège la connexion (brute force) et les formulaires sensibles au spam.
 * En cas d'indisponibilité du disque, il échoue en mode ouvert (journalisé) pour ne bloquer personne.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $directory
    ) {
    }

    public static function default(): self
    {
        return new self(AppConfig::storagePath('cache/ratelimit'));
    }

    /**
     * Indique si la clé a atteint son quota sur la fenêtre en cours.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->read($key)['count'] >= $maxAttempts;
    }

    /**
     * Enregistre une tentative et retourne le total de la fenêtre en cours.
     */
    public function hit(string $key, int $decaySeconds): int
    {
        if (!AppConfig::ensureDirectory($this->directory)) {
            error_log('[Boötes][RateLimiter] Répertoire de stockage indisponible : ' . $this->directory);
            return 0;
        }

        $handle = @fopen($this->pathFor($key), 'c+');
        if ($handle === false) {
            error_log('[Boötes][RateLimiter] Écriture impossible pour la clé de limitation.');
            return 0;
        }

        try {
            flock($handle, LOCK_EX);
            $state = self::decode((string) stream_get_contents($handle));
            $now   = time();

            if ($state['reset_at'] <= $now) {
                $state = ['count' => 0, 'reset_at' => $now + $decaySeconds];
            }
            $state['count']++;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (random_int(1, 100) === 1) {
            $this->purgeExpired();
        }

        return $state['count'];
    }

    public function clear(string $key): void
    {
        $path = $this->pathFor($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Secondes restantes avant la réouverture de la fenêtre.
     */
    public function availableIn(string $key): int
    {
        return max(0, $this->read($key)['reset_at'] - time());
    }

    /**
     * @return array{count: int, reset_at: int}
     */
    private function read(string $key): array
    {
        $path  = $this->pathFor($key);
        $state = is_file($path) ? self::decode((string) @file_get_contents($path)) : ['count' => 0, 'reset_at' => 0];

        return $state['reset_at'] > time() ? $state : ['count' => 0, 'reset_at' => 0];
    }

    /**
     * @return array{count: int, reset_at: int}
     */
    private static function decode(string $raw): array
    {
        $data = json_decode($raw, true);

        return [
            'count'    => is_array($data) ? (int) ($data['count'] ?? 0) : 0,
            'reset_at' => is_array($data) ? (int) ($data['reset_at'] ?? 0) : 0,
        ];
    }

    private function pathFor(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }

    private function purgeExpired(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            if (self::decode((string) @file_get_contents($file))['reset_at'] <= time()) {
                @unlink($file);
            }
        }
    }
}
