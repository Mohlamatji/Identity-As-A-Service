<?php

namespace App\Services;

use App\Models\User;
use App\Models\BiometricTemplate;
use App\Models\Consent;
use App\Models\Transaction;
use App\Models\DhaVerification;
use App\Models\AuditLog;
use PDO;

/**
 * Implements POPIA sections 23-25: a data subject's right to know what's
 * held about them, and to request its deletion.
 *
 * The deletion side is deliberately NOT a blanket wipe. Transaction and DHA
 * verification records are likely subject to an independent FICA/AML
 * record-keeping duty (see config/retention.php's reasoning) - POPIA
 * section 14 permits retention where another law requires it. So an
 * erasure request deletes what's deletable now (biometric templates,
 * behavioral baseline, revokes consent) and anonymizes direct identifiers
 * on the user record, but explicitly reports what it could NOT delete and
 * why, rather than silently keeping data the request seemed to cover.
 */
class DataSubjectService
{
    public function export(PDO $pdo, int $userId): ?array
    {
        $user = User::find($pdo, $userId);
        if (!$user) {
            return null;
        }

        $template = BiometricTemplate::latestForUser($pdo, $userId, 'face');

        return [
            'user' => [
                'id' => $user['id'],
                'full_name' => $user['full_name'],
                'dob' => $user['dob'],
                'phone' => $user['phone_encrypted'] !== null ? User::decryptPhone($user['phone_encrypted']) : null,
                'dha_id_mock' => $user['dha_id_mock'],
                'created_at' => $user['created_at'],
                'deleted_at' => $user['deleted_at'] ?? null,
            ],
            // Metadata only, not the raw template vector - the vector
            // itself isn't meaningful to the data subject and returning it
            // only increases exposure of what is otherwise encrypted at rest.
            'biometric_template' => $template ? [
                'enrolled_at' => $template['created_at'],
                'algorithm' => $template['algorithm_version'],
            ] : null,
            'consent' => (new ConsentService())->status($pdo, $userId),
            'transactions' => Transaction::recentForUser($pdo, $userId, 100),
            'dha_verifications' => DhaVerification::recentForUser($pdo, $userId, 100),
        ];
    }

    public function eraseUser(PDO $pdo, int $userId): ?array
    {
        $user = User::find($pdo, $userId);
        if (!$user) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM biometric_templates WHERE user_id = ?');
        $stmt->execute([$userId]);
        $templatesDeleted = (int) $stmt->fetchColumn();
        $pdo->prepare('DELETE FROM biometric_templates WHERE user_id = ?')->execute([$userId]);

        $pdo->prepare('DELETE FROM behavior_profiles WHERE user_id = ?')->execute([$userId]);

        $consentRevoked = Consent::revoke($pdo, $userId);

        // Anonymize direct identifiers. The ID-number blind index is replaced
        // with a random tombstone: even a keyed pseudonymous identifier is
        // still linkable personal information, and this also lets the person
        // legitimately re-register later without a UNIQUE collision.
        $stmt = $pdo->prepare("UPDATE users SET full_name = '[deleted]', phone_encrypted = ?, id_number_hash = ?, deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([EncryptionService::encrypt(''), 'erased:' . bin2hex(random_bytes(16)), $userId]);

        // Kill pending verification state and the behavioural features it holds.
        $pdo->prepare('UPDATE verification_sessions SET behavior_json = NULL, device_fingerprint = NULL, consumed_at = COALESCE(consumed_at, ?) WHERE user_id = ?')->execute([time(), $userId]);

        AuditLog::record($pdo, 'system', 'data_subject_erasure_processed', 'users', $userId, $_SERVER['REMOTE_ADDR'] ?? null);

        return [
            'deleted' => [
                'biometric_templates' => $templatesDeleted,
                'behavior_profile' => true,
                'consent_revoked' => $consentRevoked,
            ],
            'anonymized' => ['full_name', 'phone', 'id_number_index'],
            'retained' => [
                'transactions' => 'Retained - likely subject to an independent FICA/AML record-keeping duty (POPIA s14 permits retention where another law requires it). Confirm the exact retention period with counsel.',
                'dha_verifications' => 'Row retained for the same reason; the raw bureau response payload is still subject to the normal retention-policy redaction schedule separately.',
                'audit_log' => 'Retained - this is the accountability record itself.',
            ],
        ];
    }
}
