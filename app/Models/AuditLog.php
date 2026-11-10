<?php

namespace App\Models;

use PDO;

/**
 * Append-only AND tamper-evident audit trail. Each row stores the SHA-256
 * of its own content chained to the previous row's hash, so editing or
 * deleting any historical row breaks every hash after it - verifyChain()
 * finds the first broken link. (Still enforce INSERT-only DB grants in
 * production; the chain is how you PROVE nobody got around them.)
 *
 * `details` is for small non-PII context (decision id, score, codes) -
 * never names, ID numbers or biometric data.
 */
class AuditLog
{
    public static function record(
        PDO $pdo,
        string $actor,
        string $action,
        ?string $subjectTable = null,
        ?int $subjectId = null,
        ?string $ipAddress = null,
        ?array $details = null
    ): void {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->exec($mysql ? 'START TRANSACTION' : 'BEGIN IMMEDIATE');
        }
        try {
            $lock = $mysql ? ' FOR UPDATE' : '';
            $prev = $pdo->query('SELECT entry_hash FROM audit_log WHERE entry_hash IS NOT NULL ORDER BY id DESC LIMIT 1' . $lock)->fetchColumn();
            $prev = $prev ?: str_repeat('0', 64);
            $createdAt = gmdate('Y-m-d H:i:s');
            $detailsJson = $details ? json_encode($details, JSON_UNESCAPED_SLASHES) : null;
            $hash = self::hash($prev, $actor, $action, $subjectTable, $subjectId, $ipAddress, $createdAt, $detailsJson);

            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (actor, action, subject_table, subject_id, ip_address, created_at, details, prev_hash, entry_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$actor, $action, $subjectTable, $subjectId, $ipAddress, $createdAt, $detailsJson, $prev, $hash]);
            if ($ownsTx) {
                $pdo->exec('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    public static function all(PDO $pdo, int $limit = 100): array
    {
        $stmt = $pdo->prepare('SELECT id, actor, action, subject_table, subject_id, ip_address, created_at, details, entry_hash FROM audit_log ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{valid:bool, checked:int, legacy_unhashed:int, broken_at_id:?int} */
    public static function verifyChain(PDO $pdo): array
    {
        $legacy = (int) $pdo->query('SELECT COUNT(*) FROM audit_log WHERE entry_hash IS NULL')->fetchColumn();
        $prev = str_repeat('0', 64);
        $checked = 0;
        $rows = $pdo->query('SELECT * FROM audit_log WHERE entry_hash IS NOT NULL ORDER BY id ASC');
        foreach ($rows as $r) {
            $expected = self::hash($prev, $r['actor'], $r['action'], $r['subject_table'], $r['subject_id'] !== null ? (int) $r['subject_id'] : null, $r['ip_address'], $r['created_at'], $r['details']);
            if ($r['prev_hash'] !== $prev || !hash_equals($expected, $r['entry_hash'])) {
                return ['valid' => false, 'checked' => $checked, 'legacy_unhashed' => $legacy, 'broken_at_id' => (int) $r['id']];
            }
            $prev = $r['entry_hash'];
            $checked++;
        }
        return ['valid' => true, 'checked' => $checked, 'legacy_unhashed' => $legacy, 'broken_at_id' => null];
    }

    private static function hash(string $prev, string $actor, string $action, ?string $table, ?int $subjectId, ?string $ip, string $createdAt, ?string $details): string
    {
        return hash('sha256', implode('|', [$prev, $actor, $action, $table ?? '', $subjectId ?? '', $ip ?? '', $createdAt, $details ?? '']));
    }
}
