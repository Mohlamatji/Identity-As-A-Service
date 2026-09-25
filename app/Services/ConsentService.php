<?php

namespace App\Services;

use App\Models\Consent;
use App\Models\AuditLog;
use PDO;

/**
 * VerifyNow's integration guide requires "explicit consent from the data
 * subject... with a prescribed reason and length of validity" before
 * running identity checks. This gates the DHA verification step on that
 * consent existing and still being valid - not just recording it for
 * show.
 */
class ConsentService
{
    public function grant(PDO $pdo, int $userId, string $reason, int $validityDays): array
    {
        $id = Consent::grant($pdo, $userId, $reason, $validityDays);
        AuditLog::record($pdo, 'system', 'consent_granted', 'consents', $id, $_SERVER['REMOTE_ADDR'] ?? null);

        return $this->status($pdo, $userId);
    }

    public function revoke(PDO $pdo, int $userId): bool
    {
        $revoked = Consent::revoke($pdo, $userId);
        if ($revoked) {
            AuditLog::record($pdo, 'system', 'consent_revoked', 'users', $userId, $_SERVER['REMOTE_ADDR'] ?? null);
        }
        return $revoked;
    }

    public function hasValidConsent(PDO $pdo, int $userId): bool
    {
        return Consent::hasValidConsent($pdo, $userId);
    }

    public function status(PDO $pdo, int $userId): array
    {
        $latest = Consent::latestForUser($pdo, $userId);
        if ($latest === null) {
            return ['has_consent' => false, 'valid' => false, 'reason' => null, 'granted_at' => null, 'expires_at' => null, 'revoked' => false];
        }

        return [
            'has_consent' => true,
            'valid' => $this->hasValidConsent($pdo, $userId),
            'reason' => $latest['reason'],
            'granted_at' => $latest['granted_at'],
            'expires_at' => $latest['expires_at'],
            'revoked' => $latest['revoked_at'] !== null,
        ];
    }
}
