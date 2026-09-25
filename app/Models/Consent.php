<?php

namespace App\Models;

use PDO;

/**
 * VerifyNow's integration guide requires enterprise partners to "receive
 * and retain explicit consent from the data subject... with a prescribed
 * reason and length of validity" before running identity checks. This
 * model is that record - a reason, a granted timestamp, an expiry, and an
 * optional revocation.
 */
class Consent
{
    public static function grant(PDO $pdo, int $userId, string $reason, int $validityDays): int
    {
        // SQLite and MySQL have different date-math syntax - branch on
        // driver rather than maintaining a database-agnostic expression.
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'INSERT INTO consents (user_id, reason, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
            : "INSERT INTO consents (user_id, reason, expires_at) VALUES (?, ?, datetime('now', '+' || ? || ' days'))";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $reason, $validityDays]);
        return (int) $pdo->lastInsertId();
    }

    /** Latest consent record for a user, regardless of validity. */
    public static function latestForUser(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM consents WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** True only if the latest consent exists, hasn't expired, and hasn't been revoked. */
    public static function hasValidConsent(PDO $pdo, int $userId): bool
    {
        $latest = self::latestForUser($pdo, $userId);
        if ($latest === null || $latest['revoked_at'] !== null) {
            return false;
        }
        return strtotime($latest['expires_at']) > time();
    }

    public static function revoke(PDO $pdo, int $userId): bool
    {
        $latest = self::latestForUser($pdo, $userId);
        if ($latest === null || $latest['revoked_at'] !== null) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE consents SET revoked_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$latest['id']]);
        return true;
    }
}
