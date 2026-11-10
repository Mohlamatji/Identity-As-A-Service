<?php

namespace App\Services;

use App\Support\Config;
use PDO;

/**
 * Server-issued, single-use liveness challenges.
 *
 * The original design let the BROWSER decide and report whether liveness
 * passed. Here the SERVER chooses a random sequence of actions, the client
 * must perform exactly that sequence, and the challenge dies on first use
 * and after two minutes. A recorded video of yesterday's session can't
 * satisfy today's random sequence, and a captured request can't be replayed.
 *
 * A challenge can also be bound to a specific amount + payee ("what you see
 * is what you sign"), which the verification token then carries through to
 * transaction approval.
 */
class ChallengeService
{
    public const ACTIONS = ['blink', 'turn_left', 'turn_right', 'open_mouth'];

    public function issue(PDO $pdo, ?int $userId, ?int $amountCents, ?string $payee, ?string $clientId, ?string $ip): array
    {
        $count = (int) Config::get('liveness.actions_per_challenge', 3);
        $pool = self::ACTIONS;
        for ($i = count($pool) - 1; $i > 0; $i--) { // Fisher-Yates with a CSPRNG
            $j = random_int(0, $i);
            [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
        }
        $actions = array_slice($pool, 0, $count);

        $ttl = (int) Config::get('tokens.challenge_ttl', 120);
        $id = EncryptionService::randomToken(16);
        $now = time();
        $pdo->prepare(
            'INSERT INTO challenges (id, user_id, actions, amount_cents, payee, client_id, ip, issued_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $userId, json_encode($actions), $amountCents, $payee, $clientId, $ip, $now, $now + $ttl]);

        return [
            'challenge_id' => $id,
            'actions' => $actions,
            'expires_in' => $ttl,
            'simulated_allowed' => (bool) Config::get('allow_simulated_liveness'),
        ];
    }

    /**
     * Atomically claims a challenge (single use). Returns the row, or null
     * if it doesn't exist, is expired, already used, or belongs to a
     * different user.
     */
    public function consume(PDO $pdo, string $challengeId, ?int $userId): ?array
    {
        $now = time();
        $stmt = $pdo->prepare('SELECT * FROM challenges WHERE id = ?');
        $stmt->execute([$challengeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['consumed_at'] !== null || (int) $row['expires_at'] < $now) {
            return null;
        }
        if ($row['user_id'] !== null && $userId !== null && (int) $row['user_id'] !== $userId) {
            return null;
        }

        $claim = $pdo->prepare('UPDATE challenges SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL');
        $claim->execute([$now, $challengeId]);
        if ($claim->rowCount() !== 1) {
            return null; // lost a race with a concurrent request
        }
        $row['actions'] = json_decode($row['actions'], true);
        return $row;
    }
}
