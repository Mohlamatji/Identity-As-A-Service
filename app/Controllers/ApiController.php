<?php

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\BehaviorService;
use App\Services\BiometricService;
use App\Services\ChallengeService;
use App\Services\ClientAuthService;
use App\Services\ConsentService;
use App\Services\DataSubjectService;
use App\Services\RateLimitService;
use App\Services\TransactionService;
use App\Services\VerificationSessionService;
use App\Support\AuthContext;
use App\Support\Config;
use App\Support\Http;
use App\Support\SaId;
use PDO;

/**
 * Every handler returns [httpStatus, body] (the router does the I/O), which
 * is what lets the router add idempotency, rate limiting and uniform error
 * handling in one place.
 */
class ApiController
{
    public function __construct(
        private readonly BiometricService $biometric = new BiometricService(),
        private readonly BehaviorService $behavior = new BehaviorService(),
        private readonly ConsentService $consent = new ConsentService(),
        private readonly DataSubjectService $dataSubject = new DataSubjectService(),
        private readonly ChallengeService $challenges = new ChallengeService(),
        private readonly VerificationSessionService $sessions = new VerificationSessionService(),
        private readonly TransactionService $transactions = new TransactionService(),
        private readonly ClientAuthService $clientAuth = new ClientAuthService()
    ) {
    }

    /** POST /api/client-token - mint a short-lived, browser-safe token (secret keys only). */
    public function clientToken(PDO $pdo, AuthContext $auth): array
    {
        if ($auth->type === 'client_token') {
            return [403, ['status' => 'error', 'message' => 'Client tokens cannot mint further tokens.']];
        }
        $in = Http::input();
        $userId = isset($in['user_id']) ? (int) $in['user_id'] : null;
        if ($userId !== null && !User::find($pdo, $userId)) {
            return [404, ['status' => 'error', 'message' => 'User not found.']];
        }
        $scopes = is_array($in['scopes'] ?? null) ? $in['scopes'] : ClientAuthService::BROWSER_SAFE_SCOPES;
        return [200, $this->clientAuth->mintClientToken($auth, $scopes, $userId)];
    }

    /**
     * POST /api/verify/challenge
     * Step 1 of verification: the server picks a random liveness action
     * sequence. Optionally bind the eventual verification to an amount and
     * payee so the token can't be reused for a different payment.
     */
    public function challenge(PDO $pdo, AuthContext $auth): array
    {
        $in = Http::input();
        $userId = isset($in['user_id']) ? (int) $in['user_id'] : $auth->boundUserId;
        if ($auth->boundUserId !== null && $userId !== $auth->boundUserId) {
            return [403, ['status' => 'error', 'message' => 'This client token is bound to a different user.']];
        }
        if ($userId !== null && !User::find($pdo, $userId)) {
            return [404, ['status' => 'error', 'message' => 'User not found.']];
        }
        $amount = isset($in['amount_cents']) ? (int) $in['amount_cents'] : null;
        if ($amount !== null && $amount < 1) {
            return [422, ['status' => 'error', 'message' => 'amount_cents must be positive.']];
        }
        $payee = isset($in['payee']) ? substr((string) $in['payee'], 0, 120) : null;

        return [200, $this->challenges->issue($pdo, $userId, $amount, $payee, $auth->clientId, Http::ip())];
    }

    /**
     * POST /api/enroll
     * Creates the user (if needed) and enrolls/re-checks their face template.
     * Requires a server-issued challenge_id plus the liveness evidence.
     */
    public function enroll(PDO $pdo, AuthContext $auth): array
    {
        $in = Http::input();
        $idNumber = preg_replace('/\s+/', '', trim((string) ($in['id_number'] ?? '')));
        $fullName = trim((string) ($in['full_name'] ?? ''));
        $dob = trim((string) ($in['dob'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $image = $in['image_base64'] ?? null;
        $challengeId = (string) ($in['challenge_id'] ?? '');

        if ($idNumber === '' || $fullName === '' || $dob === '' || $phone === '' || !$image) {
            return [422, ['status' => 'error', 'message' => 'id_number, full_name, dob, phone, and image_base64 are required.']];
        }
        if (mb_strlen($fullName) > 120 || !preg_match('/^\+?[0-9 ()\-]{7,20}$/', $phone) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            return [422, ['status' => 'error', 'message' => 'full_name (<=120 chars), phone (7-20 digits) or dob (YYYY-MM-DD) is malformed.']];
        }
        $idCheck = SaId::inspect($idNumber, $dob);
        if (!$idCheck['valid']) {
            return [422, ['status' => 'error', 'message' => $idCheck['reason']]];
        }
        if ($challengeId === '') {
            return [422, ['status' => 'error', 'message' => 'challenge_id is required. Call POST /api/verify/challenge first.']];
        }

        $existing = User::findByIdNumber($pdo, $idNumber);
        if ($existing && $auth->boundUserId !== null && (int) $existing['id'] !== $auth->boundUserId) {
            return [403, ['status' => 'error', 'message' => 'This client token is bound to a different user.']];
        }
        if ($existing && $this->sessions->recentFailures($pdo, (int) $existing['id']) >= Config::get('vault.lockout_failures')) {
            return [429, ['status' => 'error', 'message' => 'Too many failed verification attempts. Try again later.']];
        }

        $challenge = (new ChallengeService())->consume($pdo, $challengeId, $existing ? (int) $existing['id'] : null);
        if (!$challenge) {
            return [422, ['status' => 'error', 'message' => 'challenge_id is unknown, expired, or already used. Request a new challenge.']];
        }

        $userId = $existing['id'] ?? User::create($pdo, $idNumber, $fullName, $dob, $phone);
        $userId = (int) $userId;
        if (!$existing) {
            AuditLog::record($pdo, 'client:' . $auth->clientId, 'user_onboarded', 'users', $userId, Http::ip());
        }

        return $this->completeCapture($pdo, $auth, $userId, $challenge, $in, !$existing);
    }

    /** POST /api/authenticate  (alias: POST /api/verify/session) */
    public function authenticate(PDO $pdo, AuthContext $auth): array
    {
        $in = Http::input();
        $userId = (int) ($in['user_id'] ?? 0);
        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user || empty($in['image_base64']) || empty($in['challenge_id'])) {
            return [422, ['status' => 'error', 'confidence' => 0.0, 'message' => 'user_id, challenge_id and image_base64 are required, and the user must be enrolled.']];
        }
        if ($auth->boundUserId !== null && $auth->boundUserId !== $userId) {
            return [403, ['status' => 'error', 'confidence' => 0.0, 'message' => 'This client token is bound to a different user.']];
        }
        if ($this->sessions->recentFailures($pdo, $userId) >= Config::get('vault.lockout_failures')) {
            return [429, ['status' => 'error', 'confidence' => 0.0, 'message' => 'Too many failed verification attempts. Try again later.']];
        }
        $challenge = $this->challenges->consume($pdo, (string) $in['challenge_id'], $userId);
        if (!$challenge) {
            return [422, ['status' => 'error', 'confidence' => 0.0, 'message' => 'challenge_id is unknown, expired, or already used. Request a new challenge.']];
        }
        return $this->completeCapture($pdo, $auth, $userId, $challenge, $in, false);
    }

    private function completeCapture(PDO $pdo, AuthContext $auth, int $userId, array $challenge, array $in, bool $isEnroll): array
    {
        $evidence = [
            'mode' => $in['mode'] ?? 'real',
            'actions' => $in['actions'] ?? [],
            'descriptors' => $in['descriptors'] ?? [],
            'descriptor' => $in['descriptor'] ?? null,
            'duration_ms' => $in['duration_ms'] ?? 0,
            'frames_analyzed' => $in['frames_analyzed'] ?? 0,
        ];
        $capture = $this->biometric->capture($pdo, $userId, (string) $in['image_base64'], $challenge, $evidence);
        $liveness = $capture['liveness'];
        $vault = $capture['vault_match'];

        $behavior = BehaviorService::sanitizeBehavior(is_array($in['behavior'] ?? null) ? $in['behavior'] : null);
        $device = isset($in['device_fingerprint']) ? substr((string) $in['device_fingerprint'], 0, 255) : null;
        $session = $this->sessions->create($pdo, $userId, $challenge, $capture, $behavior, $device, Http::ip());

        $ok = $liveness['passed'] && $vault['matched'];
        AuditLog::record($pdo, 'client:' . $auth->clientId, $ok ? 'verification_passed' : 'verification_failed', 'users', $userId, Http::ip(), [
            'liveness' => $liveness['passed'], 'liveness_mode' => $liveness['mode'], 'vault_matched' => $vault['matched'],
        ]);

        $message = match (true) {
            !$liveness['passed'] => 'Liveness check failed: ' . $liveness['reason'],
            !$vault['matched'] => 'Biometric match failed: ' . $vault['reason'],
            $isEnroll || $vault['is_first_enrollment'] => 'Biometric template enrolled successfully',
            default => 'Biometric match successful',
        };

        $body = [
            'status' => $ok ? ($isEnroll || $vault['is_first_enrollment'] ? 'enrolled' : 'approved') : 'rejected',
            'user_id' => $userId,
            'confidence' => $vault['confidence'],
            'message' => $message,
            'liveness' => ['passed' => $liveness['passed'], 'mode' => $liveness['mode'], 'score' => $liveness['score'], 'reason' => $liveness['reason'], 'signals' => $liveness['signals']],
            'vault_match' => ['matched' => $vault['matched'], 'is_first_enrollment' => $vault['is_first_enrollment'], 'confidence' => $vault['confidence'], 'distance' => $vault['distance'] ?? null, 'reason' => $vault['reason']],
        ];
        if ($ok) {
            $body['verification_token'] = $session['token'];
            $body['expires_in'] = $session['expires_in'];
        }
        return [200, $body];
    }

    /** POST /api/transaction/approve */
    public function transactionApprove(PDO $pdo, AuthContext $auth): array
    {
        return $this->transactions->approve($pdo, $auth, Http::input());
    }

    /** POST /api/consent/grant */
    public function consentGrant(PDO $pdo, AuthContext $auth): array
    {
        $in = Http::input();
        $userId = (int) ($in['user_id'] ?? 0);
        $reason = trim((string) ($in['reason'] ?? ''));
        $validityDays = (int) ($in['validity_days'] ?? 365);
        if ($bad = $this->boundUserViolation($auth, $userId)) {
            return $bad;
        }
        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user || $reason === '') {
            return [422, ['status' => 'error', 'message' => 'user_id and reason are required.']];
        }
        if ($validityDays < 1 || $validityDays > 3650) {
            return [422, ['status' => 'error', 'message' => 'validity_days must be between 1 and 3650.']];
        }
        return [200, array_merge(['status' => 'granted'], $this->consent->grant($pdo, $userId, substr($reason, 0, 200), $validityDays))];
    }

    /** GET /api/consent/status?user_id= */
    public function consentStatus(PDO $pdo, AuthContext $auth): array
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        if ($bad = $this->boundUserViolation($auth, $userId)) {
            return $bad;
        }
        return User::find($pdo, $userId)
            ? [200, $this->consent->status($pdo, $userId)]
            : [404, ['status' => 'error', 'message' => 'User not found.']];
    }

    /** POST /api/consent/revoke */
    public function consentRevoke(PDO $pdo, AuthContext $auth): array
    {
        $userId = (int) (Http::input()['user_id'] ?? 0);
        if ($bad = $this->boundUserViolation($auth, $userId)) {
            return $bad;
        }
        if (!User::find($pdo, $userId)) {
            return [404, ['status' => 'error', 'message' => 'User not found.']];
        }
        return [200, ['status' => $this->consent->revoke($pdo, $userId) ? 'revoked' : 'no_active_consent_to_revoke']];
    }

    /**
     * GET /api/data-subject/export?user_id= (POPIA s23-24)
     * A bound client token can only export ITS user. A secret key can export
     * any user - the integrator is responsible for authenticating the data
     * subject before calling this.
     */
    public function dataSubjectExport(PDO $pdo, AuthContext $auth): array
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        if (!$userId) {
            return [422, ['status' => 'error', 'message' => 'user_id query param is required.']];
        }
        if ($bad = $this->boundUserViolation($auth, $userId)) {
            return $bad;
        }
        $export = $this->dataSubject->export($pdo, $userId);
        if ($export === null) {
            return [404, ['status' => 'error', 'message' => 'User not found.']];
        }
        AuditLog::record($pdo, 'client:' . $auth->clientId, 'data_subject_export_requested', 'users', $userId, Http::ip());
        return [200, $export];
    }

    /** POST /api/data-subject/delete-request (POPIA s25) */
    public function dataSubjectDeleteRequest(PDO $pdo, AuthContext $auth): array
    {
        $userId = (int) (Http::input()['user_id'] ?? 0);
        if (!$userId) {
            return [422, ['status' => 'error', 'message' => 'user_id is required.']];
        }
        if ($bad = $this->boundUserViolation($auth, $userId)) {
            return $bad;
        }
        $result = $this->dataSubject->eraseUser($pdo, $userId);
        return $result === null ? [404, ['status' => 'error', 'message' => 'User not found.']] : [200, ['status' => 'processed'] + $result];
    }

    /** POST /api/behavior/update - explicitly sets a user's device+location baseline (server-to-server). */
    public function behaviorUpdate(PDO $pdo, AuthContext $auth): array
    {
        $in = Http::input();
        $userId = (int) ($in['user_id'] ?? 0);
        if (!$userId || !User::find($pdo, $userId)) {
            return [404, ['status' => 'error', 'message' => 'User not found.']];
        }
        $device = isset($in['device_fingerprint']) ? substr((string) $in['device_fingerprint'], 0, 255) : null;
        $country = isset($in['ip_country']) && preg_match('/^[A-Za-z]{2}$/', (string) $in['ip_country']) ? strtoupper($in['ip_country']) : null;
        $this->behavior->updateBaseline($pdo, $userId, $device, $country);
        AuditLog::record($pdo, 'client:' . $auth->clientId, 'behavior_baseline_updated', 'users', $userId, Http::ip());
        return [200, ['status' => 'updated', 'user_id' => $userId, 'device_fingerprint' => $device, 'ip_country' => $country]];
    }

    /** GET /api/fraud/check?user_id=&device_fingerprint=&ip_country= */
    public function fraudCheck(PDO $pdo, AuthContext $auth): array
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        if (!$userId || !User::find($pdo, $userId)) {
            return [404, ['status' => 'error', 'message' => 'User not found.']];
        }
        $r = $this->behavior->checkFraud($pdo, $userId, $_GET['device_fingerprint'] ?? null, $_GET['ip_country'] ?? null);
        return [200, ['user_id' => $userId, 'anomaly' => $r['anomaly'], 'velocity' => $r['velocity']]];
    }

    private function boundUserViolation(AuthContext $auth, int $userId): ?array
    {
        return ($auth->boundUserId !== null && $auth->boundUserId !== $userId)
            ? [403, ['status' => 'error', 'message' => 'This client token is bound to a different user.']]
            : null;
    }
}
