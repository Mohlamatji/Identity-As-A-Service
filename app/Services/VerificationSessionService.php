<?php

namespace App\Services;

use App\Support\Config;
use PDO;

/**
 * A verification session is the server's own record of "this user just
 * passed liveness + vault match". Its token is the ONLY thing
 * /api/transaction/approve will accept as proof of biometric verification.
 *
 * Before: the approve endpoint accepted `liveness` and `vault_match`
 * objects straight from the request body - so anyone holding an API key
 * could simply send {"liveness":{"passed":true},"vault_match":{"matched":true}}
 * and skip biometrics entirely. Now the client never supplies those results.
 *
 * Tokens are 256-bit random, stored only as a SHA-256 hash, expire in
 * minutes, are single-use, bound to one user and - if the challenge was
 * created with them - bound to one amount and payee.
 */
class VerificationSessionService
{
    public function create(PDO $pdo, int $userId, array $challenge, array $capture, ?array $behavior, ?string $deviceFingerprint, ?string $ip): array
    {
        $token = 'ivs_' . EncryptionService::randomToken(32);
        $now = time();
        $ttl = (int) Config::get('tokens.session_ttl', 300);
        $l = $capture['liveness'];
        $v = $capture['vault_match'];

        $pdo->prepare(
            'INSERT INTO verification_sessions
             (token_hash, user_id, challenge_id, liveness_passed, liveness_mode, liveness_score, liveness_reason,
              vault_matched, vault_first, vault_confidence, vault_distance, vault_reason, signals, behavior_json,
              device_fingerprint, amount_cents, payee, ip, created_at, expires_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            hash('sha256', $token), $userId, $challenge['id'],
            (int) $l['passed'], $l['mode'], $l['score'], $l['reason'],
            (int) $v['matched'], (int) $v['is_first_enrollment'], $v['confidence'], $v['distance'] ?? null, $v['reason'],
            json_encode($l['signals']), $behavior ? json_encode($behavior) : null,
            $deviceFingerprint, $challenge['amount_cents'], $challenge['payee'], $ip, $now, $now + $ttl,
        ]);

        return ['token' => $token, 'expires_in' => $ttl, 'session_id' => (int) $pdo->lastInsertId()];
    }

    /**
     * Atomically spends a token. Returns [session|null, errorMessage|null].
     * Binding checks run BEFORE the token is consumed so a mismatched
     * request (wrong amount/payee/user) doesn't burn a valid token.
     */
    public function consume(PDO $pdo, string $token, int $userId, ?int $amountCents, ?string $payee): array
    {
        $stmt = $pdo->prepare('SELECT * FROM verification_sessions WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$s) {
            return [null, 'Unknown verification_token.'];
        }
        if ((int) $s['user_id'] !== $userId) {
            return [null, 'verification_token was issued to a different user.'];
        }
        if ($s['consumed_at'] !== null) {
            return [null, 'verification_token has already been used (tokens are single-use).'];
        }
        if ((int) $s['expires_at'] < time()) {
            return [null, 'verification_token has expired - run a new verification.'];
        }
        if ($s['amount_cents'] !== null && $amountCents !== null && (int) $s['amount_cents'] !== $amountCents) {
            return [null, 'verification_token was issued for a different amount.'];
        }
        if ($s['payee'] !== null && $payee !== null && $s['payee'] !== $payee) {
            return [null, 'verification_token was issued for a different payee.'];
        }

        $claim = $pdo->prepare('UPDATE verification_sessions SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL');
        $claim->execute([time(), $s['id']]);
        if ($claim->rowCount() !== 1) {
            return [null, 'verification_token has already been used (tokens are single-use).'];
        }

        return [$s, null];
    }

    /** Turns a stored session row back into the arrays DecisionEngine expects. */
    public static function toEngineInputs(array $s): array
    {
        return [
            'liveness' => [
                'passed' => (bool) $s['liveness_passed'],
                'mode' => $s['liveness_mode'],
                'score' => (float) $s['liveness_score'],
                'reason' => $s['liveness_reason'],
                'signals' => json_decode($s['signals'] ?? '[]', true) ?: [],
            ],
            'vault_match' => [
                'is_first_enrollment' => (bool) $s['vault_first'],
                'matched' => (bool) $s['vault_matched'],
                'confidence' => $s['vault_confidence'] !== null ? (float) $s['vault_confidence'] : null,
                'distance' => $s['vault_distance'] !== null ? (float) $s['vault_distance'] : null,
                'reason' => $s['vault_reason'],
            ],
            'behavior' => $s['behavior_json'] ? json_decode($s['behavior_json'], true) : null,
            'device_fingerprint' => $s['device_fingerprint'],
        ];
    }

    /** Failed verification attempts for a user in the lockout window (anti hill-climbing). */
    public function recentFailures(PDO $pdo, int $userId, bool $sinceLastSuccess = false): int
    {
        $since = time() - (int) Config::get('vault.lockout_window_seconds', 900);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM verification_sessions
             WHERE user_id = ? AND created_at >= ? AND (liveness_passed = 0 OR (vault_matched = 0 AND vault_first = 0))'
        );
        $stmt->execute([$userId, $since]);
        $count = (int) $stmt->fetchColumn();
        if (!$sinceLastSuccess) {
            return $count;
        }
        // Behavioural scoring only cares about failures AFTER the person's most
        // recent success - a user who fumbled lighting twice then passed is not suspicious.
        $last = $pdo->prepare('SELECT MAX(created_at) FROM verification_sessions WHERE user_id = ? AND liveness_passed = 1 AND vault_matched = 1');
        $last->execute([$userId]);
        $lastOk = (int) $last->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM verification_sessions
             WHERE user_id = ? AND created_at >= ? AND created_at >= ? AND (liveness_passed = 0 OR (vault_matched = 0 AND vault_first = 0))'
        );
        $stmt->execute([$userId, $since, $lastOk]);
        return (int) $stmt->fetchColumn();
    }
}
