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

    /**
     * Folds a session's keystroke-cadence features into the user's typing
     * baseline (running average, then a slow EMA once enough samples exist).
     * Only call after a TRUSTED (approved) transaction, same reasoning as
     * upsert(): a fraudster's first attempt must not become "normal".
     */
    public static function updateTyping(PDO $pdo, int $userId, float $meanMs, float $stdMs): void
    {
        $p = self::find($pdo, $userId);
        if (!$p) {
            return;
        }
        $n = (int) ($p['typing_samples'] ?? 0);
        $alpha = $n < 20 ? 1 / ($n + 1) : 0.05;
        $newMean = $p['typing_mean_ms'] === null ? $meanMs : $p['typing_mean_ms'] + $alpha * ($meanMs - $p['typing_mean_ms']);
        $newStd = $p['typing_std_ms'] === null ? $stdMs : $p['typing_std_ms'] + $alpha * ($stdMs - $p['typing_std_ms']);
        $pdo->prepare('UPDATE behavior_profiles SET typing_mean_ms = ?, typing_std_ms = ?, typing_samples = ? WHERE user_id = ?')
            ->execute([$newMean, $newStd, $n + 1, $userId]);
    }
}
