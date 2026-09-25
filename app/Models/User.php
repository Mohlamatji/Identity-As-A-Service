<?php

namespace App\Models;

use App\Services\EncryptionService;
use PDO;

class User
{
    public static function create(PDO $pdo, string $idNumber, string $fullName, string $dob, string $phone): int
    {
        // DHA_ID is mocked for now - in production this would be the reference
        // returned by the accredited bureau's verification response, not
        // generated client-side.
        $dhaIdMock = 'DHA-MOCK-' . strtoupper(substr(hash('sha256', $idNumber . 'dha-salt'), 0, 10));

        $stmt = $pdo->prepare(
            'INSERT INTO users (id_number_hash, dha_id_mock, full_name, dob, phone_encrypted) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            hash('sha256', $idNumber),
            $dhaIdMock,
            $fullName,
            $dob,
            self::encryptPhone($phone),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByIdNumber(PDO $pdo, string $idNumber): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id_number_hash = ?');
        $stmt->execute([hash('sha256', $idNumber)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function encryptPhone(string $phone): string
    {
        return EncryptionService::encrypt($phone);
    }

    public static function decryptPhone(string $encryptedPhone): string
    {
        return EncryptionService::decrypt($encryptedPhone);
    }
}
