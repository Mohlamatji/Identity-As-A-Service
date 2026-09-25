<?php

namespace App\Models;

use App\Services\EncryptionService;
use PDO;

class BiometricTemplate
{
    /**
     * Stores a template vector, never the raw image/audio, encrypted at rest.
     * $templateVector should already be an extracted embedding (binary string).
     */
    public static function create(PDO $pdo, int $userId, string $type, string $templateVector, string $algoVersion): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO biometric_templates (user_id, template_type, template_vector, algorithm_version) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, EncryptionService::encryptBinary($templateVector), $algoVersion]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Returns the row with template_vector already decrypted, ready for
     * direct comparison by IdentityVaultService.
     */
    public static function latestForUser(PDO $pdo, int $userId, string $type): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM biometric_templates WHERE user_id = ? AND template_type = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['template_vector'] = EncryptionService::decryptBinary($row['template_vector']);
        }
        return $row ?: null;
    }
}
