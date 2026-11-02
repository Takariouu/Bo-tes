<?php

declare(strict_types=1);

namespace Bootes\Tests;

use PDO;
use PDOStatement;

/**
 * Connexion SQLite émulant les quelques extensions MariaDB utilisées par l'application
 * (NOW(), ON DUPLICATE KEY UPDATE, TIMESTAMPDIFF, DATE_SUB, DATE_FORMAT, GET_LOCK).
 * Réservé aux tests : le code applicatif ne dépend jamais de cette classe.
 */
final class TestPdo extends PDO
{
    /** Fragment SQL déclenchant une panne simulée (test du gestionnaire d'erreurs global). */
    public ?string $faultMarker = null;

    public static function open(string $databaseFile): self
    {
        $pdo = new self('sqlite:' . $databaseFile, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
        $pdo->sqliteCreateFunction('GET_LOCK', static fn (mixed $name, mixed $timeout): int => 1, 2);
        $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn (mixed $name): int => 1, 1);
        $pdo->sqliteCreateFunction(
            'TSDIFF_MINUTE',
            static fn (?string $from, ?string $to): int => (int) floor((strtotime((string) $to) - strtotime((string) $from)) / 60),
            2
        );
        $pdo->sqliteCreateFunction(
            'DATE_FORMAT',
            static fn (?string $date, string $format): string => date(
                strtr($format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's']),
                strtotime((string) $date) ?: time()
            ),
            2
        );
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    public static function translate(string $sql): string
    {
        $rules = [
            '/ON\s+DUPLICATE\s+KEY\s+UPDATE/i'                                => 'ON CONFLICT DO UPDATE SET',
            '/\bVALUES\((\w+)\)/i'                                             => 'excluded.$1',
            '/TIMESTAMPDIFF\(\s*MINUTE\s*,/i'                                  => 'TSDIFF_MINUTE(',
            '/DATE_SUB\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+MONTH\s*\)/i'      => "datetime('now', '-$1 months')",
            '/DATE_SUB\(\s*NOW\(\)\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i'        => "datetime('now', '-$1 days')",
            '/\s+FOR\s+UPDATE\b/i'                                             => '',
        ];

        return (string) preg_replace(array_keys($rules), array_values($rules), $sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->simulateFault($query);

        return parent::prepare(self::translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->simulateFault($query);
        $translated = self::translate($query);

        return $fetchMode === null
            ? parent::query($translated)
            : parent::query($translated, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(self::translate($statement));
    }

    private function simulateFault(string $sql): void
    {
        if ($this->faultMarker !== null && str_contains($sql, $this->faultMarker)) {
            throw new \RuntimeException('Panne simulée : SQLSTATE[HY000] /var/www/secret/path.php:42');
        }
    }
}
