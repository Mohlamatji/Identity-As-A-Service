<?php

namespace App\Support;

use App\Services\EncryptionService;

/**
 * Stateless signed tokens (compact "<b64url payload>.<b64url hmac>") used
 * for short-lived browser client tokens and signed decision receipts.
 * HMAC-SHA256 under an HKDF subkey of the master secret; constant-time
 * verification; mandatory expiry.
 */
final class Tokens
{
    public static function sign(string $purpose, array $payload, int $ttlSeconds): string
    {
        $payload['exp'] = time() + $ttlSeconds;
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $body . '.' . self::b64(hex2bin(EncryptionService::hmac('sign-' . $purpose, $body)));
    }

    /** @return array|null payload if the signature is valid and unexpired */
    public static function verify(string $purpose, string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        $expected = self::b64(hex2bin(EncryptionService::hmac('sign-' . $purpose, $body)));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode(self::unb64($body), true);
        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        return $payload;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $b64): string
    {
        return (string) base64_decode(strtr($b64, '-_', '+/'));
    }
}
