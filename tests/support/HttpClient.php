<?php

declare(strict_types=1);

namespace Bootes\Tests;

/**
 * Client HTTP minimal (sans extension cURL) avec gestion des cookies,
 * des formulaires urlencoded et multipart.
 */
final class HttpClient
{
    /** Nombre de requêtes restées sans réponse (serveur de test bloqué). */
    public static int $transportFailures = 0;

    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    /**
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, array{name: string, content: string, type?: string}> $files
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public function post(string $path, array $fields, array $files = []): array
    {
        return $this->request('POST', $path, $fields, $files);
    }

    /**
     * Charge une page puis soumet un formulaire POST avec le jeton CSRF de la session.
     *
     * @param array<string, mixed> $fields
     * @param array<string, array{name: string, content: string, type?: string}> $files
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public function submit(string $path, array $fields, array $files = []): array
    {
        $page  = $this->get($path);
        $token = self::extractCsrfToken($page['body']);

        return $this->post($path, ['csrf_token' => (string) $token] + $fields, $files);
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function clearCookies(): void
    {
        $this->cookies = [];
    }

    public static function extractCsrfToken(string $html): ?string
    {
        if (preg_match('/<meta name="csrf-token" content="([a-f0-9]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, array{name: string, content: string, type?: string}> $files
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    private function request(string $method, string $path, array $fields = [], array $files = []): array
    {
        $headers = ['Connection: close', 'User-Agent: BootesTestSuite/1.0'];

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $content = '';
        if ($method === 'POST') {
            if ($files === []) {
                $content   = http_build_query($fields);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $boundary  = '----BootesTest' . bin2hex(random_bytes(8));
                $content   = self::buildMultipart($boundary, $fields, $files);
                $headers[] = 'Content-Type: multipart/form-data; boundary=' . $boundary;
            }
            $headers[] = 'Content-Length: ' . strlen($content);
        }

        $context = stream_context_create([
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'content'         => $content,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'timeout'         => 15,
            ],
        ]);

        $body = @file_get_contents(rtrim($this->baseUrl, '/') . $path, false, $context);
        $raw  = $http_response_header ?? [];
        if ($raw === []) {
            self::$transportFailures++;
        }

        $status  = 0;
        $parsed  = [];
        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $parsed = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $parsed[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }
        }

        foreach ($parsed['set-cookie'] ?? [] as $cookieLine) {
            $this->storeCookie($cookieLine);
        }

        return ['status' => $status, 'headers' => $parsed, 'body' => is_string($body) ? $body : ''];
    }

    private function storeCookie(string $line): void
    {
        $segments = array_map('trim', explode(';', $line));
        $pair     = explode('=', (string) array_shift($segments), 2);
        if (count($pair) !== 2) {
            return;
        }

        [$name, $value] = $pair;
        $expired = $value === '' || $value === 'deleted';
        foreach ($segments as $segment) {
            if (stripos($segment, 'expires=') === 0 && strtotime(substr($segment, 8)) < time()) {
                $expired = true;
            }
            if (stripos($segment, 'max-age=') === 0 && (int) substr($segment, 8) <= 0) {
                $expired = true;
            }
        }

        if ($expired) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, array{name: string, content: string, type?: string}> $files
     */
    private static function buildMultipart(string $boundary, array $fields, array $files): string
    {
        $body = '';
        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $fieldName = is_array($value) ? $name . '[]' : $name;
                $body .= '--' . $boundary . "\r\n"
                    . 'Content-Disposition: form-data; name="' . $fieldName . "\"\r\n\r\n"
                    . $item . "\r\n";
            }
        }
        foreach ($files as $name => $file) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $file['name'] . "\"\r\n"
                . 'Content-Type: ' . ($file['type'] ?? 'application/octet-stream') . "\r\n\r\n"
                . $file['content'] . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }
}
