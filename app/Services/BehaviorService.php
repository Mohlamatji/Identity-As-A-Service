<?php

namespace App\Services;

use PDO;
use App\Models\Transaction;
use App\Models\BehaviorProfile;

/**
 * Renamed from BehavioralAnalyticsService to match the BehaviorService.php
 * naming in the technical build plan. Two responsibilities:
 *  - score(): MVP heuristic risk scoring fed into the Decision Engine
 *  - baseline tracking: remembers a user's "normal" device/location so
 *    future attempts can be compared against it, backing
 *    POST /api/behavior/update and GET /api/fraud/check
 */
class BehaviorService
{
    /**
     * $sessionMeta may include:
     *  - device_fingerprint, ip_country: compared against the stored baseline
     *  - new_device, ip_country_mismatch: explicit overrides (e.g. from a demo
     *    UI's fraud-simulation toggle) that take precedence over baseline
     *    comparison when present, so a pilot demo can force the anomaly path
     *    without needing a second real device.
     */
    public function score(PDO $pdo, int $userId, array $sessionMeta): array
    {
        $risk = 0;
        $reasons = [];

        $recent = Transaction::recentForUser($pdo, $userId, 10);
        $lastHourCount = 0;
        $cutoff = time() - 3600;
        foreach ($recent as $tx) {
            if (strtotime($tx['created_at']) >= $cutoff) {
                $lastHourCount++;
            }
        }
        if ($lastHourCount >= 3) {
            $risk += 30;
            $reasons[] = "{$lastHourCount} transactions in the last hour (velocity flag)";
        }

        $anomaly = $this->resolveAnomalyFlags($pdo, $userId, $sessionMeta);

        if ($anomaly['new_device']) {
            $risk += 20;
            $reasons[] = 'Transaction initiated from a device that does not match the stored baseline';
        }

        if ($anomaly['ip_country_mismatch']) {
            $risk += 25;
            $reasons[] = "IP geolocation does not match user's baseline country";
        }

        return [
            'risk_contribution' => min($risk, 100),
            'reasons' => $reasons,
            'anomaly' => $anomaly,
        ];
    }

    /** Standalone anomaly check, no scoring - backs GET /api/fraud/check. */
    public function checkFraud(PDO $pdo, int $userId, ?string $deviceFingerprint, ?string $ipCountry): array
    {
        $anomaly = BehaviorProfile::checkAnomaly($pdo, $userId, $deviceFingerprint, $ipCountry);

        $recent = Transaction::recentForUser($pdo, $userId, 10);
        $lastHourCount = 0;
        $cutoff = time() - 3600;
        foreach ($recent as $tx) {
            if (strtotime($tx['created_at']) >= $cutoff) {
                $lastHourCount++;
            }
        }

        return [
            'anomaly' => $anomaly,
            'velocity' => [
                'transactions_last_hour' => $lastHourCount,
                'flagged' => $lastHourCount >= 3,
            ],
        ];
    }

    /** Backs POST /api/behavior/update - sets/refreshes a user's baseline. */
    public function updateBaseline(PDO $pdo, int $userId, ?string $deviceFingerprint, ?string $ipCountry): void
    {
        BehaviorProfile::upsert($pdo, $userId, $deviceFingerprint, $ipCountry);
    }

    private function resolveAnomalyFlags(PDO $pdo, int $userId, array $sessionMeta): array
    {
        // Explicit overrides win (used by the demo UI's fraud-simulation toggle).
        if (array_key_exists('new_device', $sessionMeta) || array_key_exists('ip_country_mismatch', $sessionMeta)) {
            return [
                'new_device' => (bool) ($sessionMeta['new_device'] ?? false),
                'ip_country_mismatch' => (bool) ($sessionMeta['ip_country_mismatch'] ?? false),
                'has_baseline' => BehaviorProfile::find($pdo, $userId) !== null,
                'source' => 'explicit_override',
            ];
        }

        $anomaly = BehaviorProfile::checkAnomaly(
            $pdo,
            $userId,
            $sessionMeta['device_fingerprint'] ?? null,
            $sessionMeta['ip_country'] ?? null
        );
        $anomaly['source'] = 'baseline_comparison';
        return $anomaly;
    }
}
