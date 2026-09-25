<?php

namespace App\Models;

use App\Services\EncryptionService;
use PDO;

/**
 * This table existed in the schema since the project's first build but was
 * never actually written to - DHA verification results were only ever
 * passed around in memory, never persisted. This model closes that gap:
 * every bureau call now leaves an audit record, including the full
 * response payload (encrypted at rest, same as biometric templates and
 * phone numbers) for compliance/investigation purposes.
 */
class DhaVerification
{
    public static function create(
        PDO $pdo,
        int $userId,
        string $provider,
        string $queryType,
        string $matchResult,
        ?string $errorCode,
        ?array $rawResponse,
        int $costCents
    ): int {
        $encryptedResponse = $rawResponse !== null
            ? EncryptionService::encrypt(json_encode($rawResponse))
            : null;

        $stmt = $pdo->prepare(
            'INSERT INTO dha_verifications (user_id, provider, query_type, match_result, error_code, raw_response, cost_cents)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $provider, $queryType, $matchResult, $errorCode, $encryptedResponse, $costCents]);
        return (int) $pdo->lastInsertId();
    }

    public static function recentForUser(PDO $pdo, int $userId, int $limit = 20): array
    {
        $stmt = $pdo->prepare('SELECT * FROM dha_verifications WHERE user_id = ? ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'decryptRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM dha_verifications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::decryptRow($row) : null;
    }

    private static function decryptRow(array $row): array
    {
        if ($row['raw_response'] !== null) {
            $row['raw_response'] = json_decode(EncryptionService::decrypt($row['raw_response']), true);
        }
        return $row;
    }
}
