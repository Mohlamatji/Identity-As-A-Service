<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DhaVerification;
use App\Models\Transaction;
use App\Models\User;
use App\Services\VerificationAdapters\AdapterFactory;
use App\Services\VerificationAdapters\VerificationResult;
use App\Support\AuthContext;
use App\Support\Config;
use App\Support\Http;
use App\Support\SaId;
use PDO;

/**
 * The full approval pipeline, shared by POST /api/transaction/approve and
 * POST /api/paypal/orders so both enforce exactly the same rules:
 *
 *   validate -> consent -> spend verification token (server-held liveness +
 *   vault result) -> ID sanity check -> [skip paid DHA call if a hard gate
 *   will reject anyway] -> behavioural scoring -> decision -> persist ->
 *   signed receipt -> audit.
 *
 * Returns [httpStatus, body]. Never trusts liveness/vault results from the
 * request; they come from the verification session the server created.
 */
class TransactionService
{
    public function __construct(
        private readonly ConsentService $consent = new ConsentService(),
        private readonly BehaviorService $behavior = new BehaviorService(),
        private readonly VerificationSessionService $sessions = new VerificationSessionService(),
        private readonly ReceiptService $receipts = new ReceiptService(),
        private readonly DecisionEngine $engine = new DecisionEngine()
    ) {
    }

    /** @return array{0:int,1:array} */
    public function approve(PDO $pdo, AuthContext $auth, array $input): array
    {
        $ip = Http::ip();
        $userId = (int) ($input['user_id'] ?? 0);
        if ($auth->boundUserId !== null && $auth->boundUserId !== $userId) {
            return [403, ['status' => 'error', 'message' => 'This client token is bound to a different user.']];
        }
        $user = $userId ? User::find($pdo, $userId) : null;
        if (!$user) {
            return [404, ['status' => 'error', 'message' => 'User not found. Call /api/enroll first.']];
        }

        $amountCents = $input['amount_cents'] ?? null;
        if (!is_int($amountCents) || $amountCents < 1 || $amountCents > 10_000_000_000) {
            return [422, ['status' => 'error', 'message' => 'amount_cents must be a positive integer (cents).']];
        }
        $currency = strtoupper((string) ($input['currency'] ?? 'ZAR'));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            return [422, ['status' => 'error', 'message' => 'currency must be a 3-letter ISO code.']];
        }
        $token = (string) ($input['verification_token'] ?? '');
        if ($token === '') {
            return [422, ['status' => 'error', 'message' => 'verification_token is required. Run /api/verify/challenge then /api/verify/session first - biometric results are never accepted from the request body.']];
        }
        $idNumber = preg_replace('/\s+/', '', (string) ($input['id_number'] ?? ''));
        $payee = isset($input['payee']) ? substr((string) $input['payee'], 0, 120) : (isset($input['bank_partner_id']) ? substr((string) $input['bank_partner_id'], 0, 120) : null);
        $bankPartnerId = (string) ($input['bank_partner_id'] ?? $payee ?? 'demo-bank');

        // The supplied ID must actually belong to this enrolled user.
        $owner = $idNumber !== '' ? User::findByIdNumber($pdo, $idNumber) : null;
        if (!$owner || (int) $owner['id'] !== $userId) {
            return [422, ['status' => 'error', 'message' => 'id_number does not match the enrolled user.']];
        }

        if (!$this->consent->hasValidConsent($pdo, $userId)) {
            AuditLog::record($pdo, 'system', 'transaction_blocked_no_consent', 'users', $userId, $ip);
            return [403, ['status' => 'error', 'message' => 'No valid consent on file for DHA verification. Call POST /api/consent/grant first.']];
        }

        [$session, $error] = $this->sessions->consume($pdo, $token, $userId, $amountCents, $payee);
        if ($error !== null) {
            AuditLog::record($pdo, 'system', 'transaction_blocked_bad_token', 'users', $userId, $ip, ['reason' => $error]);
            return [403, ['status' => 'error', 'message' => $error]];
        }
        $verified = VerificationSessionService::toEngineInputs($session);

        // ---- ID sanity + (conditionally) the paid DHA call --------------
        $idCheck = SaId::inspect($idNumber, $user['dob']);
        $gatesWillReject = !$idCheck['valid']
            || !$verified['liveness']['passed']
            || (!$verified['vault_match']['is_first_enrollment'] && !$verified['vault_match']['matched']);

        $providers = require __DIR__ . '/../../config/providers.php';
        if ($gatesWillReject) {
            // Don't burn a paid bureau credit on a request a gate will reject regardless.
            $dha = new VerificationResult(false, 'error', null, 'realtime', 0, 'Skipped: a hard gate already failed', 'skipped_gate_failed');
        } else {
            $realtime = (bool) ($input['realtime'] ?? true);
            $dha = AdapterFactory::make($providers)->verifyIdentity($idNumber, $user['full_name'], $input['selfie_base64'] ?? null, $realtime);
            AuditLog::record($pdo, 'system', 'dha_verification_' . $dha->matchResult, 'users', $userId, $ip);
            DhaVerification::create($pdo, $userId, $providers['active_provider'], $dha->queryType, $dha->matchResult, $dha->errorCode, $dha->rawResponse, $dha->costCents);
        }

        // ---- Behavioural scoring ----------------------------------------
        $device = $verified['device_fingerprint'] ?? (Config::isProduction() ? null : ($input['device_fingerprint'] ?? null));
        $country = Http::trustedCountry() ?? (Config::isProduction() ? null : ($input['ip_country'] ?? null));
        $meta = ['device_fingerprint' => $device, 'ip_country' => $country];
        foreach (['new_device', 'ip_country_mismatch'] as $k) {
            if (array_key_exists($k, $input)) {
                $meta[$k] = $input[$k]; // honoured only in demo mode - see BehaviorService
            }
        }
        $behavioral = $this->behavior->score($pdo, $userId, $meta, $amountCents, $verified['behavior']);

        // ---- Decide, persist, sign --------------------------------------
        $decision = $this->engine->decide($dha, $verified['liveness'], $behavioral, $verified['vault_match'], $amountCents, $idCheck);

        $txId = Transaction::create($pdo, $userId, $bankPartnerId, $amountCents, $decision['score'], $decision['decision'], $decision['reason'], $currency, [
            'decision_id' => $decision['decision_id'],
            'factors_json' => json_encode(['factors' => $decision['factors'], 'gates' => $decision['gates'], 'threshold' => $decision['threshold']]),
            'required_action' => $decision['required_action'],
            'engine_version' => $decision['engine_version'],
            'client_id' => $auth->clientId,
            'verification_session_id' => (int) $session['id'],
            'payee' => $payee,
        ]);
        $receipt = $this->receipts->issue($decision, $txId, $userId, $amountCents, $currency, $payee);
        Transaction::setReceipt($pdo, $txId, $receipt);
        AuditLog::record($pdo, 'client:' . $auth->clientId, 'decision_' . $decision['decision'], 'transactions', $txId, $ip, [
            'decision_id' => $decision['decision_id'], 'score' => $decision['score'], 'required_action' => $decision['required_action'],
        ]);

        // Only a TRUSTED transaction may update the baseline, otherwise a
        // fraudster's first attempt would become the user's "normal".
        if ($decision['decision'] === 'approved' && $device) {
            $this->behavior->updateBaseline($pdo, $userId, $device, $country, $verified['behavior']);
        }

        return [200, [
            'status' => $decision['decision'],
            'confidence' => round($decision['score'] / 100, 2),
            'message' => $decision['reason'],
            'transaction_id' => $txId,
            'decision_id' => $decision['decision_id'],
            'amount' => Transaction::formatZar($amountCents),
            'currency' => $currency,
            'score' => $decision['score'],
            'threshold' => $decision['threshold'],
            'required_action' => $decision['required_action'],
            'factors' => $decision['factors'],
            'gates' => $decision['gates'],
            'receipt' => $receipt,
            'dha' => [
                'match_result' => $dha->matchResult,
                'query_type' => $dha->queryType,
                'cost_cents' => $dha->costCents,
                'error_code' => $dha->errorCode,
            ],
            'behavioral' => $behavioral,
        ]];
    }
}
