<?php

namespace App\Services;

use App\Models\BehaviorProfile;
use App\Models\Transaction;
use App\Support\Config;
use PDO;

/**
 * Behavioral risk scoring. Every signal is a named factor with explicit
 * points and a human-readable detail, so a risk team can see exactly why a
 * score moved (and the Decision Engine passes them straight through).
 *
 * Signals (v2):
 *  Transaction behaviour
 *   - velocity_count      many transactions in the last hour
 *   - velocity_value      rolling 24h value over the configured ceiling
 *   - amount_anomaly      z-score vs the user's own approved history (or an
 *                         absolute check when there's no history yet)
 *   - new_account_large   large payment from a brand-new account
 *   - odd_hours           activity in the user's local small hours
 *   - recent_failures     repeated rejected transactions / failed liveness
 *  Context
 *   - new_device / country_mismatch vs the stored baseline
 *  Behavioural biometrics (collected in-browser by identity-vault.js - only
 *  numeric timing features, never key contents)
 *   - scripted_input      machine-uniform keystroke cadence, or a "session"
 *                         with no human input events at all
 *   - pasted_input        sensitive fields pasted instead of typed
 *   - typing_deviation    cadence far from the user's own learned baseline
 */
class BehaviorService
{
    public function score(PDO $pdo, int $userId, array $sessionMeta, int $amountCents = 0, ?array $behavior = null, ?int $nowTs = null): array
    {
        $cfg = Config::get('behavior');
        $nowTs ??= time();
        $factors = [];
        $add = static function (string $code, string $label, int $points, string $detail) use (&$factors): void {
            $factors[] = ['code' => $code, 'label' => $label, 'points' => $points, 'detail' => $detail];
        };

        // --- Velocity -------------------------------------------------
        $lastHour = Transaction::countSince($pdo, $userId, $nowTs - 3600);
        if ($lastHour >= $cfg['velocity_hour_count']) {
            $add('velocity_count', 'Transaction velocity', 30, "{$lastHour} transactions in the last hour");
        }
        $dayValue = Transaction::valueSince($pdo, $userId, $nowTs - 86400) + $amountCents;
        if ($dayValue > $cfg['velocity_day_amount_cents']) {
            $add('velocity_value', '24h value ceiling', 20, 'Rolling 24h value ' . Transaction::formatZar($dayValue) . ' exceeds ' . Transaction::formatZar($cfg['velocity_day_amount_cents']));
        }

        // --- Amount anomaly -------------------------------------------
        $history = Transaction::approvedAmounts($pdo, $userId, 30);
        if (count($history) >= 3 && $amountCents > 0) {
            $mean = array_sum($history) / count($history);
            $var = array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $history)) / count($history);
            $std = max(sqrt($var), 0.1 * $mean, 100);
            $z = ($amountCents - $mean) / $std;
            if ($z >= 5) {
                $add('amount_anomaly', 'Unusual amount', 30, sprintf('%.1f standard deviations above this user\'s typical %s', $z, Transaction::formatZar((int) $mean)));
            } elseif ($z >= $cfg['amount_zscore_flag']) {
                $add('amount_anomaly', 'Unusual amount', 20, sprintf('%.1f standard deviations above this user\'s typical %s', $z, Transaction::formatZar((int) $mean)));
            }
        } elseif ($amountCents >= $cfg['new_account_large_amount_cents']) {
            $add('amount_anomaly', 'Large first-time value', 15, 'No approved history to compare against and amount is ' . Transaction::formatZar($amountCents));
        }

        // --- Account age ----------------------------------------------
        $stmt = $pdo->prepare('SELECT created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $createdAt = $stmt->fetchColumn();
        if ($createdAt && ($nowTs - strtotime($createdAt . ' UTC')) < $cfg['new_account_hours'] * 3600 && $amountCents >= $cfg['new_account_large_amount_cents']) {
            $add('new_account_large', 'New account, large payment', 15, 'Account is under ' . $cfg['new_account_hours'] . 'h old');
        }

        // --- Time of day (user's local timezone) ----------------------
        $hour = (int) (new \DateTime('@' . $nowTs))->setTimezone(new \DateTimeZone(Config::get('timezone', 'UTC')))->format('G');
        if ($hour >= $cfg['odd_hours'][0] && $hour < $cfg['odd_hours'][1]) {
            $add('odd_hours', 'Unusual hour', 5, "Initiated at {$hour}:00 local time");
        }

        // --- Recent failures ------------------------------------------
        $since = $nowTs - $cfg['recent_failure_window_seconds'];
        $rejected = Transaction::countSince($pdo, $userId, $since, 'rejected');
        $failedSessions = (new VerificationSessionService())->recentFailures($pdo, $userId, true);
        if ($rejected >= 2 || $failedSessions >= 2) {
            $add('recent_failures', 'Recent failed attempts', 15, "{$rejected} rejected transactions and {$failedSessions} failed verifications in the last " . intdiv($cfg['recent_failure_window_seconds'], 60) . ' minutes');
        }

        // --- Device / location vs baseline ----------------------------
        $anomaly = $this->resolveAnomalyFlags($pdo, $userId, $sessionMeta);
        if ($anomaly['new_device']) {
            $add('new_device', 'New device', 20, 'Device does not match the stored baseline');
        }
        if ($anomaly['ip_country_mismatch']) {
            $add('country_mismatch', 'Location mismatch', 25, "IP country does not match the user's baseline country");
        }

        // --- Behavioural biometrics -----------------------------------
        $bb = self::sanitizeBehavior($behavior);
        if ($bb !== null) {
            $profile = BehaviorProfile::find($pdo, $userId);
            if ($bb['key_count'] >= 6 && $bb['key_interval_std_ms'] < 5) {
                $add('scripted_input', 'Scripted input', 25, sprintf('Keystroke timing is machine-uniform (σ %.1fms over %d keys)', $bb['key_interval_std_ms'], $bb['key_count']));
            } elseif ($bb['interaction_events'] === 0 && $bb['completion_time_ms'] < 2500) {
                $add('scripted_input', 'Scripted input', 20, 'Session completed with no human input events');
            }
            if ($bb['paste_count'] >= 1) {
                $add('pasted_input', 'Pasted input', 10, $bb['paste_count'] . ' paste event(s) in sensitive fields');
            }
            if ($profile && (int) ($profile['typing_samples'] ?? 0) >= 3 && $bb['key_count'] >= 6 && $profile['typing_mean_ms'] !== null) {
                $z = abs($bb['key_interval_mean_ms'] - $profile['typing_mean_ms']) / max((float) $profile['typing_std_ms'], 25.0);
                if ($z >= 3) {
                    $add('typing_deviation', 'Typing cadence deviation', 15, sprintf('Cadence %.0fms vs user baseline %.0fms (%.1fσ)', $bb['key_interval_mean_ms'], $profile['typing_mean_ms'], $z));
                }
            }
        }

        $risk = min(100, array_sum(array_column($factors, 'points')));

        return [
            'risk_contribution' => $risk,
            'reasons' => array_map(fn ($f) => $f['label'] . ': ' . $f['detail'], $factors),
            'factors' => $factors,
            'anomaly' => $anomaly,
            'velocity' => ['transactions_last_hour' => $lastHour, 'value_24h_cents' => $dayValue],
        ];
    }

    /** Standalone anomaly check, no scoring - backs GET /api/fraud/check. */
    public function checkFraud(PDO $pdo, int $userId, ?string $deviceFingerprint, ?string $ipCountry): array
    {
        $lastHour = Transaction::countSince($pdo, $userId, time() - 3600);
        return [
            'anomaly' => BehaviorProfile::checkAnomaly($pdo, $userId, $deviceFingerprint, $ipCountry),
            'velocity' => [
                'transactions_last_hour' => $lastHour,
                'flagged' => $lastHour >= Config::get('behavior.velocity_hour_count'),
            ],
        ];
    }

    /** Backs POST /api/behavior/update - sets/refreshes a user's baseline. */
    public function updateBaseline(PDO $pdo, int $userId, ?string $deviceFingerprint, ?string $ipCountry, ?array $behavior = null): void
    {
        BehaviorProfile::upsert($pdo, $userId, $deviceFingerprint, $ipCountry);
        $bb = self::sanitizeBehavior($behavior);
        if ($bb !== null && $bb['key_count'] >= 6) {
            BehaviorProfile::updateTyping($pdo, $userId, $bb['key_interval_mean_ms'], $bb['key_interval_std_ms']);
        }
    }

    /** Clamps client-supplied behavioural features to sane numeric ranges (never trust raw client JSON). */
    public static function sanitizeBehavior(?array $b): ?array
    {
        if (!$b) {
            return null;
        }
        $num = static fn ($k, $max) => max(0.0, min((float) $max, (float) ($b[$k] ?? 0)));
        return [
            'key_count' => (int) $num('key_count', 10000),
            'key_interval_mean_ms' => $num('key_interval_mean_ms', 10000),
            'key_interval_std_ms' => $num('key_interval_std_ms', 10000),
            'pointer_events' => (int) $num('pointer_events', 100000),
            'paste_count' => (int) $num('paste_count', 100),
            'interaction_events' => (int) $num('interaction_events', 100000),
            'completion_time_ms' => (int) $num('completion_time_ms', 3600000),
        ];
    }

    private function resolveAnomalyFlags(PDO $pdo, int $userId, array $sessionMeta): array
    {
        // Explicit overrides exist for the demo console's "simulate fraud"
        // toggles ONLY. They are ignored outside demo mode - otherwise any
        // caller could simply assert "new_device: false" and dodge the check.
        if (Config::get('demo_mode') && (array_key_exists('new_device', $sessionMeta) || array_key_exists('ip_country_mismatch', $sessionMeta))) {
            return [
                'new_device' => (bool) ($sessionMeta['new_device'] ?? false),
                'ip_country_mismatch' => (bool) ($sessionMeta['ip_country_mismatch'] ?? false),
                'has_baseline' => BehaviorProfile::find($pdo, $userId) !== null,
                'source' => 'demo_override',
            ];
        }

        $anomaly = BehaviorProfile::checkAnomaly($pdo, $userId, $sessionMeta['device_fingerprint'] ?? null, $sessionMeta['ip_country'] ?? null);
        $anomaly['source'] = 'baseline_comparison';
        return $anomaly;
    }
}
