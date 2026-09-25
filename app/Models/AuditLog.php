<?php

namespace App\Models;

use PDO;

class AuditLog
{
    // Append-only by convention here; in production, enforce this at the
    // DB-permission level too (grant INSERT only, no UPDATE/DELETE).
    public static function record(
        PDO $pdo,
        string $actor,
        string $action,
        ?string $subjectTable = null,
        ?int $subjectId = null,
        ?string $ipAddress = null
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (actor, action, subject_table, subject_id, ip_address) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$actor, $action, $subjectTable, $subjectId, $ipAddress]);
    }

    public static function all(PDO $pdo, int $limit = 100): array
    {
        $stmt = $pdo->prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
