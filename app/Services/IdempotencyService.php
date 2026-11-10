<?php

namespace App\Services;

use PDO;

/**
 * Idempotency-Key support for money-moving endpoints. A network retry
 * (or an impatient double-click) with the same key returns the ORIGINAL
 * response instead of running a second DHA check, a second transaction, or
 * a second PayPal order. Reusing a key with a different body is a 409.
 */
class IdempotencyService
{
    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-:.]{8,128}$/', $key);
    }

    /** @return array{state:string, status?:int, body?:string} state: new|replay|conflict|in_flight */
    public function begin(PDO $pdo, string $clientId, string $key, string $requestHash): array
    {
        $pdo->prepare('DELETE FROM idempotency_keys WHERE created_at < ?')->execute([time() - 86400]);
        try {
            $pdo->prepare('INSERT INTO idempotency_keys (client_id, idem_key, request_hash, status_code, created_at) VALUES (?, ?, ?, 0, ?)')
                ->execute([$clientId, $key, $requestHash, time()]);
            return ['state' => 'new'];
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        $stmt = $pdo->prepare('SELECT request_hash, status_code, response_json FROM idempotency_keys WHERE client_id = ? AND idem_key = ?');
        $stmt->execute([$clientId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals($row['request_hash'], $requestHash)) {
            return ['state' => 'conflict'];
        }
        if ((int) $row['status_code'] === 0) {
            return ['state' => 'in_flight'];
        }
        return ['state' => 'replay', 'status' => (int) $row['status_code'], 'body' => $row['response_json']];
    }

    public function finish(PDO $pdo, string $clientId, string $key, int $status, string $body): void
    {
        $pdo->prepare('UPDATE idempotency_keys SET status_code = ?, response_json = ? WHERE client_id = ? AND idem_key = ?')
            ->execute([$status, $body, $clientId, $key]);
    }

    /** Releases a key whose request failed before producing a stored result so the client can retry. */
    public function abandon(PDO $pdo, string $clientId, string $key): void
    {
        $pdo->prepare('DELETE FROM idempotency_keys WHERE client_id = ? AND idem_key = ? AND status_code = 0')->execute([$clientId, $key]);
    }
}
