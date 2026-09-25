<?php

namespace App\Services;

use App\Models\AuditLog;
use PDO;

/**
 * Implements the retention periods in config/retention.php. Two different
 * strategies depending on the table:
 *  - biometric_templates: DELETE the row entirely once expired - there's no
 *    partial/redacted form of a face template that's still useful.
 *  - dha_verifications: REDACT just the raw_response column (the PII-heavy
 *    bureau payload) while keeping the row itself (match result, cost,
 *    timestamp) for longer - see config/retention.php for why.
 * Every purge/redaction is itself audit-logged, so the retention process is
 * traceable the same way everything else in this app is.
 */
class RetentionService
{
    public function purgeExpiredBiometricTemplates(PDO $pdo): int
    {
        $config = require __DIR__ . '/../../config/retention.php';
        $days = $config['biometric_template_days'];
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

        $ids = $pdo->prepare('SELECT id, user_id FROM biometric_templates WHERE created_at < ?');
        $ids->execute([$cutoff]);
        $rows = $ids->fetchAll(PDO::FETCH_ASSOC);

        $delete = $pdo->prepare('DELETE FROM biometric_templates WHERE id = ?');
        foreach ($rows as $row) {
            $delete->execute([$row['id']]);
            AuditLog::record($pdo, 'system', 'retention_purged_biometric_template', 'biometric_templates', (int) $row['user_id'], null);
        }

        return count($rows);
    }

    public function redactExpiredDhaResponses(PDO $pdo): int
    {
        $config = require __DIR__ . '/../../config/retention.php';
        $days = $config['dha_raw_response_days'];
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

        $ids = $pdo->prepare("SELECT id, user_id FROM dha_verifications WHERE created_at < ? AND raw_response IS NOT NULL");
        $ids->execute([$cutoff]);
        $rows = $ids->fetchAll(PDO::FETCH_ASSOC);

        $redact = $pdo->prepare('UPDATE dha_verifications SET raw_response = NULL WHERE id = ?');
        foreach ($rows as $row) {
            $redact->execute([$row['id']]);
            AuditLog::record($pdo, 'system', 'retention_redacted_dha_response', 'dha_verifications', (int) $row['user_id'], null);
        }

        return count($rows);
    }

    /** Runs every retention action and returns a summary, for the CLI script and the admin-triggered endpoint. */
    public function runAll(PDO $pdo): array
    {
        return [
            'biometric_templates_purged' => $this->purgeExpiredBiometricTemplates($pdo),
            'dha_responses_redacted' => $this->redactExpiredDhaResponses($pdo),
        ];
    }
}
