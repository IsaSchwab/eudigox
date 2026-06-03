<?php
/**
 * Request — leitura unificada do request HTTP.
 */

class Request
{
    private static ?array $bodyCache = null;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isMethod(string $method): bool
    {
        return self::method() === strtoupper($method);
    }

    /**
     * Lê e cacheia o body JSON. Devolve [] se vazio/inválido.
     */
    public static function body(): array
    {
        if (self::$bodyCache !== null) {
            return self::$bodyCache;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return self::$bodyCache = [];
        }
        $decoded = json_decode($raw, true);
        return self::$bodyCache = is_array($decoded) ? $decoded : [];
    }

    public static function input(string $key, $default = null)
    {
        $body = self::body();
        return $body[$key] ?? $default;
    }

    public static function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public static function bearerToken(): ?string
    {
        $auth = self::header('Authorization') ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function userAgent(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    }
}
