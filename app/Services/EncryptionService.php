<?php

namespace App\Services;

/**
 * Shared symmetric encryption for data at rest (phone numbers, biometric
 * templates). Uses a random IV per call, stored alongside the ciphertext -
 * this is standard practice and does not weaken security, since AES-CBC
 * requires a unique IV per encryption to be safe.
 *
 * MVP NOTE: the key comes from an environment variable. In production this
 * should come from a proper KMS (AWS KMS, GCP KMS, HashiCorp Vault) with
 * key rotation, not a static env var - swap getKey() for a KMS client call
 * when you move past the pilot stage.
 */
class EncryptionService
{
    private const CIPHER = 'AES-256-CBC';

    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        // Prepend IV so decrypt() can recover it - IVs are not secret.
        return base64_encode($iv . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $key = self::getKey();
        $raw = base64_decode($encoded);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);
        return openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Encrypts raw binary (e.g. a biometric template vector) and returns a
     * base64 string safe for a TEXT/VARBINARY column.
     */
    public static function encryptBinary(string $rawBytes): string
    {
        return self::encrypt($rawBytes);
    }

    public static function decryptBinary(string $encoded): string
    {
        return self::decrypt($encoded);
    }

    private static function getKey(): string
    {
        $key = getenv('APP_ENCRYPTION_KEY') ?: 'local-test-key-change-me';
        // Derive a proper 32-byte key from whatever string is configured,
        // rather than requiring the operator to hand-craft exactly 32 bytes.
        return hash('sha256', $key, true);
    }
}
