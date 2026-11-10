<?php

namespace App\Models;

use App\Services\EncryptionService;
use PDO;

class User
{
    public static function create(PDO $pdo, string $idNumber, string $fullName, string $dob, string $phone): int
    {
        // DHA_ID is mocked for now - in production this would be the reference
        // returned by the accredited bureau's verification response.
        $dhaIdMock = 'DHA-MOCK-' . strtoupper(substr(EncryptionService::hmac('dha-mock', $idNumber), 0, 10));

        $stmt = $pdo->prepare(
            'INSERT INTO users (id_number_hash, dha_id_mock, full_name, dob, phone_encrypted) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            // Keyed HMAC blind index - NOT a bare SHA-256, which is
            // brute-forceable for 13-digit structured ID numbers.
            EncryptionService::blindIndex($idNumber),
            $dhaIdMock,
            $fullName,
            $dob,
            EncryptionService::encrypt($phone),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Looks up by keyed blind index first, then by the legacy unkeyed
     * SHA-256 (rows created before this upgrade) and transparently
     * re-keys the row so the weak hash disappears after first use.
     */
    public static function findByIdNumber(PDO $pdo, string $idNumber): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id_number_hash = ?');
        $stmt->execute([EncryptionService::blindIndex($idNumber)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        $stmt->execute([EncryptionService::legacyIdHash($idNumber)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $upgrade = $pdo->prepare('UPDATE users SET id_number_hash = ? WHERE id = ?');
            $upgrade->execute([EncryptionService::blindIndex($idNumber), $row['id']]);
        }
        return $row ?: null;
    }

    public static function decryptPhone(string $encryptedPhone): string
    {
        return EncryptionService::decrypt($encryptedPhone);
    }
}
