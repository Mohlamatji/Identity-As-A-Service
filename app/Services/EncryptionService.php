<?php

namespace App\Services;

use App\Support\Config;
use RuntimeException;

/**
 * Data-at-rest encryption + keyed hashing + signing, all derived from one
 * master secret (APP_ENCRYPTION_KEY) via HKDF so each purpose gets an
 * independent subkey.
 *
 * Upgrades over the original AES-256-CBC helper:
 *  - AES-256-GCM (authenticated): tampering with a stored ciphertext is now
 *    DETECTED instead of silently decrypting to garbage. Format "v2:<b64>".
 *  - Legacy CBC ciphertexts (no prefix) are still decrypted, so existing
 *    databases keep working and re-encrypt lazily on next write.
 *  - Production refuses to run with a missing or default key. The old code
 *    silently fell back to a hard-coded key.
 *  - hmac()/sign() give a pepper for ID-number lookups and signing for
 *    tokens/receipts without introducing more secrets to manage.
 *
 * Still an env var, not a KMS - swap masterKey() for a KMS call to rotate.
 */
class EncryptionService
{
    private const LEGACY_CIPHER = 'AES-256-CBC';
    private const DEV_FALLBACK_KEY = 'local-test-key-change-me';
    private const PREFIX = 'v2:';

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', self::subkey('enc-v2'), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        if (str_starts_with($encoded, self::PREFIX)) {
            $raw = base64_decode(substr($encoded, strlen(self::PREFIX)), true);
            if ($raw === false || strlen($raw) < 28) {
                throw new RuntimeException('Malformed ciphertext.');
            }
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::subkey('enc-v2'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            if ($plain === false) {
                throw new RuntimeException('Ciphertext failed authentication (tampered or wrong key).');
            }
            return $plain;
        }
        return self::decryptLegacyCbc($encoded);
    }

    public static function encryptBinary(string $rawBytes): string
    {
        return self::encrypt($rawBytes);
    }

    public static function decryptBinary(string $encoded): string
    {
        return self::decrypt($encoded);
    }

    /** True if $encoded predates GCM and should be re-encrypted on next write. */
    public static function isLegacy(string $encoded): bool
    {
        return !str_starts_with($encoded, self::PREFIX);
    }

    /** Keyed hash (HMAC-SHA256) under a purpose-specific subkey. */
    public static function hmac(string $purpose, string $data): string
    {
        return hash_hmac('sha256', $data, self::subkey($purpose));
    }

    /**
     * Blind index for SA ID numbers. A plain SHA-256 of a 13-digit ID is
     * brute-forceable in minutes (the keyspace is tiny and structured), so
     * lookups use a keyed HMAC instead.
     */
    public static function blindIndex(string $idNumber): string
    {
        return self::hmac('id-pepper-v1', preg_replace('/\D/', '', $idNumber));
    }

    public static function legacyIdHash(string $idNumber): string
    {
        return hash('sha256', $idNumber);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private static function decryptLegacyCbc(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        $ivLength = openssl_cipher_iv_length(self::LEGACY_CIPHER);
        if ($raw === false || strlen($raw) <= $ivLength) {
            throw new RuntimeException('Malformed legacy ciphertext.');
        }
        $plain = openssl_decrypt(substr($raw, $ivLength), self::LEGACY_CIPHER, hash('sha256', self::masterKey(), true), OPENSSL_RAW_DATA, substr($raw, 0, $ivLength));
        if ($plain === false) {
            throw new RuntimeException('Legacy ciphertext could not be decrypted (wrong key?).');
        }
        return $plain;
    }

    private static function subkey(string $purpose): string
    {
        return hash_hkdf('sha256', self::masterKey(), 32, $purpose);
    }

    private static function masterKey(): string
    {
        $key = getenv('APP_ENCRYPTION_KEY') ?: '';
        if (Config::isProduction()) {
            if ($key === '' || $key === self::DEV_FALLBACK_KEY || str_starts_with($key, 'change-me') || strlen($key) < 32) {
                throw new RuntimeException('APP_ENCRYPTION_KEY must be set to a random value of 32+ characters in production.');
            }
            return $key;
        }
        return $key !== '' ? $key : self::DEV_FALLBACK_KEY;
    }
}
