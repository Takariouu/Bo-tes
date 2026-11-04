<?php

declare(strict_types=1);

namespace Bootes\Src;

/**
 * Interroge un serveur Minecraft Java (protocole « Server List Ping ») pour l'annuaire : statut,
 * joueurs connectés, maximum de joueurs et version annoncée.
 *
 * Protections contre la falsification de requêtes côté serveur (SSRF) : seules des adresses IPv4
 * publiques sont contactées (réseaux privés, boucle locale, liens locaux, CGNAT, multicast et plages
 * réservées refusés) ; la connexion vise l'adresse IP déjà vérifiée, jamais le nom d'hôte, ce qui
 * neutralise un second résolveur DNS malveillant (DNS rebinding) ; délais courts, réponse plafonnée.
 */
final class ServerPing
{
    private const TIMEOUT_SECONDS    = 3;
    private const MAX_RESPONSE_BYTES = 65536;
    private const PROTOCOL_VERSION   = 767; // 1.21 : un serveur répond au statut quelle que soit la version

    /**
     * @return array{online: bool, players: int, max: int, version: ?string}
     */
    public static function query(string $host, int $port): array
    {
        $offline = ['online' => false, 'players' => 0, 'max' => 0, 'version' => null];

        [$targetHost, $targetPort] = self::resolveSrv($host, $port);
        $ip = self::resolvePublicIpv4($targetHost);
        if ($ip === null) {
            return $offline;
        }

        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $ip, $targetPort), $errno, $errstr, self::TIMEOUT_SECONDS);
        if ($socket === false) {
            return $offline;
        }
        stream_set_timeout($socket, self::TIMEOUT_SECONDS);

        try {
            $handshake = self::varInt(0x00) . self::varInt(self::PROTOCOL_VERSION) . self::string($host) . pack('n', $port) . self::varInt(1);
            fwrite($socket, self::varInt(strlen($handshake)) . $handshake);
            fwrite($socket, self::varInt(1) . self::varInt(0x00));

            $length     = self::readVarInt($socket);
            $packetId   = $length !== null && $length > 0 && $length <= self::MAX_RESPONSE_BYTES ? self::readVarInt($socket) : null;
            $jsonLength = $packetId === 0x00 ? self::readVarInt($socket) : null;
            $json       = $jsonLength !== null && $jsonLength > 0 && $jsonLength <= self::MAX_RESPONSE_BYTES
                ? self::readBytes($socket, $jsonLength)
                : null;
        } finally {
            fclose($socket);
        }

        $data = $json !== null ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return $offline;
        }

        $version = $data['version']['name'] ?? null;

        return [
            'online'  => true,
            'players' => max(0, min(1_000_000, (int) ($data['players']['online'] ?? 0))),
            'max'     => max(0, min(1_000_000, (int) ($data['players']['max'] ?? 0))),
            'version' => is_string($version) ? mb_substr(trim((string) preg_replace('/§./u', '', $version)), 0, 40) : null,
        ];
    }

    /** Vrai si l'adresse IPv4 est routable sur Internet (refuse tout réseau interne ou réservé). */
    public static function isPublicIpv4(string $ip): bool
    {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
            $flags |= FILTER_FLAG_GLOBAL_RANGE;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }

        $long = ip2long($ip);
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 4]] as [$network, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /** Enregistrement SRV _minecraft._tcp, utilisé par le jeu quand le port n'est pas précisé. */
    private static function resolveSrv(string $host, int $port): array
    {
        if ($port !== 25565 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host, $port];
        }

        $records = @dns_get_record('_minecraft._tcp.' . $host, DNS_SRV);
        $record  = is_array($records) ? ($records[0] ?? null) : null;
        if (is_array($record) && isset($record['target'], $record['port'])
            && preg_match('/^[a-z0-9.-]{1,253}$/i', (string) $record['target']) === 1) {
            return [rtrim((string) $record['target'], '.'), (int) $record['port']];
        }

        return [$host, $port];
    }

    private static function resolvePublicIpv4(string $host): ?string
    {
        $candidates = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? [$host] : (@gethostbynamel($host) ?: []);
        foreach ($candidates as $ip) {
            if (self::isPublicIpv4($ip)) {
                return $ip;
            }
        }

        return null;
    }

    private static function varInt(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $bytes = '';
        do {
            $byte  = $value & 0x7F;
            $value >>= 7;
            $bytes .= chr($value !== 0 ? $byte | 0x80 : $byte);
        } while ($value !== 0);

        return $bytes;
    }

    private static function string(string $value): string
    {
        $value = substr($value, 0, 255);

        return self::varInt(strlen($value)) . $value;
    }

    /** @param resource $socket */
    private static function readVarInt($socket): ?int
    {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            $byte = fgetc($socket);
            if ($byte === false) {
                return null;
            }
            $ord    = ord($byte);
            $value |= ($ord & 0x7F) << (7 * $i);
            if (($ord & 0x80) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @param resource $socket */
    private static function readBytes($socket, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($socket, min(8192, $length - strlen($data)));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
