<?php

namespace App\Models;

use PDO;

class BehaviorProfile
{
    public static function find(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM behavior_profiles WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Creates or overwrites the baseline for a user. Call this after a
     * transaction is trusted (e.g. approved) to keep the baseline current,
     * not on every attempt - otherwise a fraudster's device would just
     * become the new "normal" on their very first try.
     */
    public static function upsert(PDO $pdo, int $userId, ?string $deviceFingerprint, ?string $ipCountry): void
    {
        $existing = self::find($pdo, $userId);
        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE behavior_profiles SET device_fingerprint = ?, ip_country = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?'
            );
            $stmt->execute([$deviceFingerprint, $ipCountry, $userId]);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO behavior_profiles (user_id, device_fingerprint, ip_country) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $deviceFingerprint, $ipCountry]);
    }

    /**
     * Compares a new attempt's device/location against the stored baseline.
     * Returns flags BehaviorService can turn into risk points. If there is
     * no baseline yet (first-ever transaction), nothing is flagged as
     * anomalous - there's nothing to compare against.
     */
    public static function checkAnomaly(PDO $pdo, int $userId, ?string $deviceFingerprint, ?string $ipCountry): array
    {
        $baseline = self::find($pdo, $userId);

        if ($baseline === null) {
            return ['new_device' => false, 'ip_country_mismatch' => false, 'has_baseline' => false];
        }

        return [
            'new_device' => $deviceFingerprint !== null && $deviceFingerprint !== $baseline['device_fingerprint'],
            'ip_country_mismatch' => $ipCountry !== null && $ipCountry !== $baseline['ip_country'],
            'has_baseline' => true,
        ];
    }
}
