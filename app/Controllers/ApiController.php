<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\Transaction;
use App\Models\AuditLog;
use App\Models\DhaVerification;
use App\Services\BiometricService;
use App\Services\BehaviorService;
use App\Services\ConsentService;
use App\Services\DataSubjectService;
use App\Services\DecisionEngine;
use App\Services\RateLimitService;
use App\Services\VerificationAdapters\AdapterFactory;
use PDO;

class ApiController
{
    // Rate limits for the two most abuse-sensitive endpoints - repeated
    // guesses against a biometric match or repeated transaction attempts
    // are exactly what a fraud-prevention API needs to slow down. Numbers
    // are illustrative starting points for a pilot, not load-tested.
    private const AUTH_RATE_LIMIT = 10;
    private const AUTH_RATE_WINDOW_SECONDS = 60;
    private const TRANSACTION_RATE_LIMIT = 20;
    private const TRANSACTION_RATE_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly BiometricService $biometric = new BiometricService(),
        private readonly BehaviorService $behavior = new BehaviorService(),
        private readonly RateLimitService $rateLimit = new RateLimitService(),
        private readonly ConsentService $consent = new ConsentService(),
        private readonly DataSubjectService $dataSubject = new DataSubjectService()
    ) {
    }

    /**
     * POST /api/enroll
     * Body: { id_number, full_name, dob, phone, image_base64, mode, blink_detected,
     *         head_turn_detected, frames_analyzed, gesture_completed, frame_count, duration_ms }
     * Creates the user if needed, then enrolls (or re-checks) their biometric
     * template in the vault. This combines the flowchart's "Onboarding" and
     * "Biometric Capture SDK" stages into one call, matching the spec's
     * single /api/enroll endpoint.
     */
    public function enroll(PDO $pdo): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $idNumber = trim($input['id_number'] ?? '');
        $fullName = trim($input['full_name'] ?? '');
        $dob = trim($input['dob'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $imageBase64 = $input['image_base64'] ?? null;

        if ($idNumber === '' || $fullName === '' || $dob === '' || $phone === '' || !$imageBase64) {
            $this->json(422, ['status' => 'error', 'message' => 'id_number, full_name, dob, phone, and image_base64 are required.']);
            return;
        }

        $existing = User::findByIdNumber($pdo, $idNumber);
        $userId = $existing['id'] ?? User::create($pdo, $idNumber, $fullName, $dob, $phone);
        AuditLog::record($pdo, 'system', 'user_onboarded', 'users', $userId, $this->ip());

        $captureResult = $this->biometric->capture($pdo, $userId, $imageBase64, $this->buildGestureMeta($input));

        $this->json(200, [
            'status' => 'enrolled',
            'user_id' => $userId,
            'confidence' => $captureResult['vault_match']['confidence'],
            'message' => $captureResult['vault_match']['is_first_enrollment']
                ? 'Biometric template enrolled successfully'
                : 'User already enrolled - biometric re-checked against existing vault template',
            'liveness' => $captureResult['liveness'],
            'vault_match' => $captureResult['vault_match'],
        ]);
    }

    /**
     * POST /api/authenticate
     * Body: { user_id, image_base64, mode, blink_detected, head_turn_detected,
     *         frames_analyzed, gesture_completed, frame_count, duration_ms }
     * Pure biometric authentication - vault match + liveness only, no DHA
     * call and no transaction record. Response shape matches the spec:
     * { "status": "approved", "confidence": 0.92, "message": "..." }
     */
    public function authenticate(PDO $pdo): void
    {
        if (!$this->rateLimit->allow($pdo, RateLimitService::bucketKey($this->ip() ?? 'unknown', 'authenticate'), self::AUTH_RATE_LIMIT, self::AUTH_RATE_WINDOW_SECONDS)) {
            $this->json(429, ['status' => 'error', 'confidence' => 0.0, 'message' => 'Too many authentication attempts. Try again shortly.']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int) ($input['user_id'] ?? 0);
        $imageBase64 = $input['image_base64'] ?? null;

        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user || !$imageBase64) {
            $this->json(422, ['status' => 'error', 'confidence' => 0.0, 'message' => 'user_id and image_base64 are required, and user must be enrolled.']);
            return;
        }

        $captureResult = $this->biometric->capture($pdo, $userId, $imageBase64, $this->buildGestureMeta($input));

        $vault = $captureResult['vault_match'];
        $liveness = $captureResult['liveness'];
        $approved = $vault['matched'] && $liveness['passed'];

        $message = match (true) {
            !$liveness['passed'] => 'Liveness check failed: ' . $liveness['reason'],
            !$vault['matched'] => 'Biometric match failed: ' . $vault['reason'],
            default => 'Biometric match successful',
        };

        $this->json(200, [
            'status' => $approved ? 'approved' : 'rejected',
            'confidence' => $vault['confidence'],
            'message' => $message,
        ]);
    }

    /**
     * POST /api/transaction/approve
     * Body: { user_id, id_number, bank_partner_id, amount_cents, currency,
     *         realtime, liveness, vault_match, device_fingerprint, ip_country,
     *         new_device, ip_country_mismatch }
     * Full pipeline: DHA verification + behavioral scoring + Decision Engine,
     * mirroring the flowchart end to end and recording a transaction.
     */
    public function transactionApprove(PDO $pdo): void
    {
        if (!$this->rateLimit->allow($pdo, RateLimitService::bucketKey($this->ip() ?? 'unknown', 'transaction_approve'), self::TRANSACTION_RATE_LIMIT, self::TRANSACTION_RATE_WINDOW_SECONDS)) {
            $this->json(429, ['status' => 'error', 'message' => 'Too many transaction attempts. Try again shortly.']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $userId = (int) ($input['user_id'] ?? 0);
        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user) {
            $this->json(404, ['status' => 'error', 'message' => 'User not found. Call /api/enroll first.']);
            return;
        }

        $bankPartnerId = $input['bank_partner_id'] ?? 'demo-bank';
        $amountCents = (int) ($input['amount_cents'] ?? 0);
        $currency = $input['currency'] ?? 'ZAR';
        $realtime = (bool) ($input['realtime'] ?? true);
        $livenessResult = $input['liveness'] ?? ['passed' => false, 'reason' => 'No liveness result provided'];
        $vaultMatchResult = $input['vault_match'] ?? [
            'is_first_enrollment' => true,
            'matched' => true,
            'confidence' => 1.0,
            'reason' => 'No vault match result provided - defaulting to first-enrollment path',
        ];

        // VerifyNow's integration guide requires explicit, recorded consent
        // (reason + validity period) before running an identity check -
        // this is a condition of using their API, not optional. Gate the
        // DHA call on it actually existing and still being valid, not just
        // recorded for show.
        if (!$this->consent->hasValidConsent($pdo, $userId)) {
            AuditLog::record($pdo, 'system', 'transaction_blocked_no_consent', 'users', $userId, $this->ip());
            $this->json(403, [
                'status' => 'error',
                'message' => 'No valid consent on file for DHA verification. Call POST /api/consent/grant first.',
            ]);
            return;
        }

        $providersConfig = require __DIR__ . '/../../config/providers.php';
        $adapter = AdapterFactory::make($providersConfig);

        // id_number isn't stored in plaintext (only its hash), so the bureau
        // call requires it fresh on each transaction request rather than
        // re-deriving it from storage. In production this would come from a
        // secure re-entry step or a tokenized reference, not raw POST.
        $idNumber = $input['id_number'] ?? '';
        $dhaResult = $adapter->verifyIdentity($idNumber, $user['full_name'], $input['selfie_base64'] ?? null, $realtime);
        AuditLog::record($pdo, 'system', 'dha_verification_' . $dhaResult->matchResult, 'users', $userId, $this->ip());

        // Persists the full response (encrypted) for compliance/investigation -
        // this table existed in the schema since the first build but was
        // never actually written to until now.
        DhaVerification::create(
            $pdo,
            $userId,
            $providersConfig['active_provider'],
            $dhaResult->queryType,
            $dhaResult->matchResult,
            $dhaResult->errorCode,
            $dhaResult->rawResponse,
            $dhaResult->costCents
        );

        $behaviorSessionMeta = ['device_fingerprint' => $input['device_fingerprint'] ?? null, 'ip_country' => $input['ip_country'] ?? null];
        // Demo fraud-simulation toggle: explicit overrides win over baseline comparison when present.
        if (array_key_exists('new_device', $input)) {
            $behaviorSessionMeta['new_device'] = $input['new_device'];
        }
        if (array_key_exists('ip_country_mismatch', $input)) {
            $behaviorSessionMeta['ip_country_mismatch'] = $input['ip_country_mismatch'];
        }
        $behavioralResult = $this->behavior->score($pdo, $userId, $behaviorSessionMeta);

        $engine = new DecisionEngine();
        $decision = $engine->decide($dhaResult, $livenessResult, $behavioralResult, $vaultMatchResult);

        $txId = Transaction::create($pdo, $userId, $bankPartnerId, $amountCents, $decision['score'], $decision['decision'], $decision['reason'], $currency);
        AuditLog::record($pdo, 'system', 'decision_' . $decision['decision'], 'transactions', $txId, $this->ip());

        // Only refresh the baseline on an approved transaction - otherwise a
        // fraudster's very first attempt would become the new "normal".
        if ($decision['decision'] === 'approved' && ($input['device_fingerprint'] ?? null)) {
            $this->behavior->updateBaseline($pdo, $userId, $input['device_fingerprint'] ?? null, $input['ip_country'] ?? null);
        }

        $this->json(200, [
            'status' => $decision['decision'] === 'approved' ? 'approved' : $decision['decision'],
            'confidence' => round($decision['score'] / 100, 2),
            'message' => $decision['reason'],
            'transaction_id' => $txId,
            'amount' => Transaction::formatZar($amountCents),
            'currency' => $currency,
            'score' => $decision['score'],
            'dha' => [
                'match_result' => $dhaResult->matchResult,
                'query_type' => $dhaResult->queryType,
                'cost_cents' => $dhaResult->costCents,
                'error_code' => $dhaResult->errorCode,
            ],
            'behavioral' => $behavioralResult,
        ]);
    }

    /**
     * POST /api/consent/grant
     * Body: { user_id, reason, validity_days }
     * Records explicit consent for DHA verification - required before
     * /api/transaction/approve will call out to a real bureau.
     */
    public function consentGrant(PDO $pdo): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int) ($input['user_id'] ?? 0);
        $reason = trim($input['reason'] ?? '');
        $validityDays = (int) ($input['validity_days'] ?? 365);

        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user || $reason === '') {
            $this->json(422, ['status' => 'error', 'message' => 'user_id and reason are required.']);
            return;
        }
        if ($validityDays < 1 || $validityDays > 3650) {
            $this->json(422, ['status' => 'error', 'message' => 'validity_days must be between 1 and 3650.']);
            return;
        }

        $status = $this->consent->grant($pdo, $userId, $reason, $validityDays);
        $this->json(200, array_merge(['status' => 'granted'], $status));
    }

    /**
     * GET /api/consent/status?user_id=1
     */
    public function consentStatus(PDO $pdo): void
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user) {
            $this->json(404, ['status' => 'error', 'message' => 'User not found.']);
            return;
        }

        $this->json(200, $this->consent->status($pdo, $userId));
    }

    /**
     * POST /api/consent/revoke
     * Body: { user_id }
     */
    public function consentRevoke(PDO $pdo): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int) ($input['user_id'] ?? 0);
        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user) {
            $this->json(404, ['status' => 'error', 'message' => 'User not found.']);
            return;
        }

        $revoked = $this->consent->revoke($pdo, $userId);
        $this->json(200, ['status' => $revoked ? 'revoked' : 'no_active_consent_to_revoke']);
    }

    /**
     * GET /api/data-subject/export?user_id=1
     * POPIA s23-24: a data subject's right to know what's held about them.
     *
     * NOTE: like every other endpoint in this pilot, this isn't gated by
     * per-user authentication - anyone with the API key can request any
     * user's export. That's an acceptable simplification for a pilot demo,
     * but a real deployment needs to verify the requester actually IS the
     * data subject (or an authorized agent) before returning this.
     */
    public function dataSubjectExport(PDO $pdo): void
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        if (!$userId) {
            $this->json(422, ['status' => 'error', 'message' => 'user_id query param is required.']);
            return;
        }

        $export = $this->dataSubject->export($pdo, $userId);
        if ($export === null) {
            $this->json(404, ['status' => 'error', 'message' => 'User not found.']);
            return;
        }

        AuditLog::record($pdo, 'system', 'data_subject_export_requested', 'users', $userId, $this->ip());
        $this->json(200, $export);
    }

    /**
     * POST /api/data-subject/delete-request
     * Body: { user_id }
     * POPIA s25: a data subject's right to request deletion. See
     * DataSubjectService::eraseUser() for what's actually deletable vs
     * retained under an independent legal obligation, and why.
     */
    public function dataSubjectDeleteRequest(PDO $pdo): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int) ($input['user_id'] ?? 0);
        if (!$userId) {
            $this->json(422, ['status' => 'error', 'message' => 'user_id is required.']);
            return;
        }

        $result = $this->dataSubject->eraseUser($pdo, $userId);
        if ($result === null) {
            $this->json(404, ['status' => 'error', 'message' => 'User not found.']);
            return;
        }

        $this->json(200, ['status' => 'processed'] + $result);
    }

    /**
     * POST /api/behavior/update
     * Body: { user_id, device_fingerprint, ip_country }
     * Explicitly sets/refreshes a user's device+location baseline.
     */
    public function behaviorUpdate(PDO $pdo): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int) ($input['user_id'] ?? 0);
        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user) {
            $this->json(404, ['status' => 'error', 'message' => 'User not found.']);
            return;
        }

        $deviceFingerprint = $input['device_fingerprint'] ?? null;
        $ipCountry = $input['ip_country'] ?? null;
        $this->behavior->updateBaseline($pdo, $userId, $deviceFingerprint, $ipCountry);
        AuditLog::record($pdo, 'system', 'behavior_baseline_updated', 'users', $userId, $this->ip());

        $this->json(200, [
            'status' => 'updated',
            'user_id' => $userId,
            'device_fingerprint' => $deviceFingerprint,
            'ip_country' => $ipCountry,
        ]);
    }

    /**
     * GET /api/fraud/check?user_id=1&device_fingerprint=...&ip_country=...
     * Standalone anomaly check against the stored baseline - no transaction
     * or decision is recorded, just a read of current risk signals.
     */
    public function fraudCheck(PDO $pdo): void
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user) {
            $this->json(404, ['status' => 'error', 'message' => 'User not found.']);
            return;
        }

        $result = $this->behavior->checkFraud($pdo, $userId, $_GET['device_fingerprint'] ?? null, $_GET['ip_country'] ?? null);

        $this->json(200, [
            'user_id' => $userId,
            'anomaly' => $result['anomaly'],
            'velocity' => $result['velocity'],
        ]);
    }

    /**
     * Normalizes the client's liveness payload for LivenessService. Real
     * capture (face-api.js available in-browser) sends mode=real plus
     * blink/head-turn signals; the no-camera/no-face-api fallback sends
     * mode=simulated plus the older timing-based fields. Both shapes are
     * accepted here so LivenessService can branch on 'mode'.
     */
    private function buildGestureMeta(array $input): array
    {
        return [
            'mode' => $input['mode'] ?? 'simulated',
            'blink_detected' => $input['blink_detected'] ?? false,
            'head_turn_detected' => $input['head_turn_detected'] ?? false,
            'frames_analyzed' => $input['frames_analyzed'] ?? 0,
            'gesture_completed' => $input['gesture_completed'] ?? false,
            'frame_count' => $input['frame_count'] ?? 0,
            'duration_ms' => $input['duration_ms'] ?? 0,
            'descriptor' => $input['descriptor'] ?? null,
        ];
    }

    private function json(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
    }

    private function ip(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
