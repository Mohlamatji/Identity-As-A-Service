<?php

namespace App\Support;

/** Request/response helpers shared by the router and controllers. */
final class Http
{
    private const MAX_BODY_BYTES = 6 * 1024 * 1024; // selfie frames are base64 JPEGs

    public static function json(int $status, array $body, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Decoded JSON body, or [] if empty/invalid. Rejects oversized bodies. */
    public static function input(): array
    {
        static $parsed = null;
        if ($parsed !== null) {
            return $parsed;
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1) ?: '';
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            self::json(413, ['status' => 'error', 'message' => 'Request body too large.']);
            exit;
        }
        $data = json_decode($raw, true);
        return $parsed = (is_array($data) ? $data : []);
    }

    public static function rawBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    public static function ip(): string
    {
        if (Config::get('trust_proxy_headers') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Country of the caller as seen by the edge. Only trusted when the app
     * sits behind a proxy/CDN that sets the header; otherwise null (callers
     * then fall back to a client-supplied value in development only).
     */
    public static function trustedCountry(): ?string
    {
        if (!Config::get('trust_proxy_headers')) {
            return null;
        }
        $value = $_SERVER[Config::get('country_header')] ?? null;
        return is_string($value) && preg_match('/^[A-Za-z]{2}$/', $value) ? strtoupper($value) : null;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public static function bearerOrKey(): ?string
    {
        $key = self::header('X-Api-Key');
        if ($key) {
            return $key;
        }
        $auth = self::header('Authorization');
        if ($auth && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        // Camera is needed by the liveness check; nothing else is.
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
        // face-api.js + model weights come from jsDelivr; fonts from Google; Swagger UI from unpkg.
        header("Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data: blob:; media-src 'self' blob:; "
            . "connect-src 'self' https://cdn.jsdelivr.net; "
            . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if (Config::isProduction()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
