<?php
/** Unit tests (no network, no server): php tests/run.php */
putenv('APP_ENCRYPTION_KEY=unit-test-key-0123456789abcdef0123456789');
putenv('DB_SQLITE_PATH=' . sys_get_temp_dir() . '/iv-unit-' . getmypid() . '.sqlite');
require __DIR__ . '/../vendor_autoload.php';

use App\Services\{DecisionEngine, EncryptionService, PayPalService, RiskAnalystService, WebhookService, LivenessService, BehaviorService};
use App\Services\VerificationAdapters\VerificationResult;
use App\Support\{SaId, Tokens, Config, Vec};

$pass = 0; $fail = 0;
function t(string $n, bool $ok, string $x = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  PASS  ' : '  FAIL  ') . $n . ($ok ? '' : "  <-- $x") . "\n"; }

$match = new VerificationResult(true, 'match', 0.94, 'realtime', 1000);
$live = ['passed' => true, 'mode' => 'real', 'reason' => 'ok'];
$vaultOk = ['is_first_enrollment' => false, 'matched' => true, 'confidence' => 0.95, 'distance' => 0.05, 'reason' => 'ok'];
$noRisk = ['factors' => []];
$e = new DecisionEngine();

echo "== Decision engine ==\n";
$d = $e->decide($match, $live, $noRisk, $vaultOk, 50000);
t('clean small payment approved', $d['decision'] === 'approved' && $d['score'] >= 70);
$d = $e->decide($match, ['passed' => false, 'mode' => 'real', 'reason' => 'no blink'], $noRisk, $vaultOk, 50000);
t('FAILED liveness can no longer be rescued by DHA+photo score (was approvable in v1)', $d['decision'] === 'rejected' && $d['gates'][0]['code'] === 'liveness_failed');
$d = $e->decide($match, $live, $noRisk, ['is_first_enrollment' => false, 'matched' => false, 'confidence' => 0.0, 'reason' => 'x'], 50000);
t('vault mismatch is a hard gate', $d['decision'] === 'rejected');
$d = $e->decide(new VerificationResult(true, 'no_match', null, 'realtime', 0), $live, $noRisk, $vaultOk, 50000);
t('DHA no_match is a hard gate', $d['decision'] === 'rejected');
$d = $e->decide(new VerificationResult(false, 'error', null, 'realtime', 0, 'x', 'insufficient_credits'), $live, $noRisk, $vaultOk, 50000);
t('bureau outage can never auto-approve (fails closed to manual review)', $d['decision'] === 'flagged' && $d['required_action'] === 'manual_review');
$high = $e->decide(new VerificationResult(true, 'match', null, 'realtime', 0), $live, $noRisk, $vaultOk, 3000000);
t('R30k payment with no selfie match (score 80) needs step-up, not approval', $high['decision'] === 'flagged' && $high['required_action'] === 'step_up' && $high['threshold'] === 88, json_encode([$high['decision'], $high['score'], $high['threshold']]));
$high = $e->decide($match, $live, $noRisk, $vaultOk, 3000000);
t('R30k payment with full evidence is approved', $high['decision'] === 'approved', json_encode([$high['score']]));
$d = $e->decide($match, ['passed' => true, 'mode' => 'simulated', 'reason' => 'demo'], $noRisk, ['is_first_enrollment' => true, 'matched' => true, 'confidence' => 1.0, 'reason' => ''], 3000000);
t('demo simulated liveness cannot clear the high-value tier', $d['decision'] !== 'approved');
$d = $e->decide($match, $live, ['factors' => [['code' => 'new_device', 'label' => 'New device', 'points' => 20, 'detail' => 'x'], ['code' => 'country_mismatch', 'label' => 'Location', 'points' => 25, 'detail' => 'y']]], $vaultOk, 50000);
t('behavioural penalties flow through as structured factors', count(array_filter($d['factors'], fn ($f) => str_starts_with($f['code'], 'behavior.'))) === 2);
$d = $e->decide($match, $live, $noRisk, $vaultOk, 50000, ['valid' => false, 'reason' => 'bad checksum']);
t('invalid ID number is a gate', $d['decision'] === 'rejected' && $d['gates'][0]['code'] === 'id_invalid');

echo "\n== SA ID ==\n";
t('valid ID accepted', SaId::inspect('8001015009087', '1980-01-01')['valid']);
t('bad checksum rejected', !SaId::inspect('8001015009088')['valid']);
t('impossible date rejected', !SaId::inspect('8013015009087')['valid']);
t('non-numeric rejected', !SaId::inspect('80010150090AB')['valid']);

echo "\n== Encryption ==\n";
$c = EncryptionService::encrypt('secret-template');
t('roundtrip', EncryptionService::decrypt($c) === 'secret-template' && str_starts_with($c, 'v2:'));
$raw = base64_decode(substr($c, 3)); $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
$threw = false; try { EncryptionService::decrypt('v2:' . base64_encode($raw)); } catch (\Throwable) { $threw = true; }
t('GCM detects ciphertext tampering (CBC would silently return garbage)', $threw);
$iv = random_bytes(16); $legacy = base64_encode($iv . openssl_encrypt('old-data', 'AES-256-CBC', hash('sha256', getenv('APP_ENCRYPTION_KEY'), true), OPENSSL_RAW_DATA, $iv));
t('legacy CBC rows still decrypt (no data migration needed)', EncryptionService::decrypt($legacy) === 'old-data' && EncryptionService::isLegacy($legacy));
t('blind index is keyed (not plain sha256)', EncryptionService::blindIndex('8001015009087') !== hash('sha256', '8001015009087'));
putenv('APP_ENV=production'); putenv('APP_ENCRYPTION_KEY=change-me-short'); Config::reset();
$threw = false; try { EncryptionService::encrypt('x'); } catch (\RuntimeException) { $threw = true; }
t('production refuses a weak/default encryption key', $threw);
putenv('APP_ENV=development'); putenv('APP_ENCRYPTION_KEY=unit-test-key-0123456789abcdef0123456789'); Config::reset();

echo "\n== Tokens / webhooks ==\n";
$tok = Tokens::sign('x', ['a' => 1], 60);
t('signed token verifies', Tokens::verify('x', $tok)['a'] === 1);
t('token is purpose-bound', Tokens::verify('y', $tok) === null);
t('expired token rejected', Tokens::verify('x', Tokens::sign('x', ['a' => 1], -5)) === null);
t('tampered token rejected', Tokens::verify('x', 'e30.' . explode('.', $tok)[1]) === null);
$sig = WebhookService::sign('s3cret', time(), '{"a":1}');
t('webhook signature verifies', WebhookService::verify('s3cret', $sig, '{"a":1}'));
t('webhook body tamper detected', !WebhookService::verify('s3cret', $sig, '{"a":2}'));
t('stale webhook (replay) rejected', !WebhookService::verify('s3cret', WebhookService::sign('s3cret', time() - 4000, 'b'), 'b'));

echo "\n== Liveness (server-side) ==\n";
$lv = new LivenessService();
$base = array_map(fn () => mt_rand(-150, 150) / 1000, range(1, 128));
$jit = fn ($n) => array_map(fn ($x) => $x + mt_rand(-1000, 1000) / 1000 * $n, $base);
$ch = ['actions' => ['blink', 'turn_left', 'open_mouth'], 'issued_at' => time() - 3];
$ev = fn (array $o = []) => array_merge(['mode' => 'real', 'duration_ms' => 2000, 'frames_analyzed' => 30,
    'actions' => [['action' => 'blink', 'completed' => true, 't_ms' => 500], ['action' => 'turn_left', 'completed' => true, 't_ms' => 1000], ['action' => 'open_mouth', 'completed' => true, 't_ms' => 1500]],
    'descriptors' => [$jit(0.01), $jit(0.01), $jit(0.01)]], $o);
t('valid evidence passes', $lv->evaluate($ch, $ev())['passed']);
t('actions too fast (<250ms apart) fail', !$lv->evaluate($ch, $ev(['actions' => [['action' => 'blink', 'completed' => true, 't_ms' => 500], ['action' => 'turn_left', 'completed' => true, 't_ms' => 600], ['action' => 'open_mouth', 'completed' => true, 't_ms' => 700]]]))['passed']);
t('claiming 10s of capture within 3s of challenge fails (server clock)', !$lv->evaluate($ch, $ev(['duration_ms' => 10000]))['passed']);
t('face swap mid-session fails (descriptors disagree)', !$lv->evaluate($ch, $ev(['descriptors' => [$jit(0.01), $jit(0.01), array_map(fn () => mt_rand(-150, 150) / 1000, range(1, 128))]]))['passed']);
t('identical descriptors (static photo) fail', !$lv->evaluate($ch, $ev(['descriptors' => [$base, $base, $base]]))['passed']);
t('malformed descriptors ignored', !$lv->evaluate($ch, $ev(['descriptors' => [[1, 2], [3], ['a']]]))['passed']);
t('simulated mode blocked outside demo', !(function () use ($lv, $ch) { putenv('ALLOW_SIMULATED_LIVENESS=0'); Config::reset(); return $lv->evaluate($ch, ['mode' => 'simulated'])['passed']; })());

echo "\n== Behavioural scoring ==\n";
require __DIR__ . '/../config/database.php'; $pdo = get_pdo();
$pdo->exec("INSERT INTO users (id_number_hash, full_name, dob, phone_encrypted, created_at) VALUES ('h','T','1980-01-01','x','2020-01-01 00:00:00')");
foreach ([40000, 45000, 50000, 42000, 48000] as $a) {
    $pdo->prepare("INSERT INTO transactions (user_id, bank_partner_id, amount_cents, risk_score, decision, created_at) VALUES (1,'b',?,90,'approved',?)")->execute([$a, gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
}
$bs = new BehaviorService();
$noon = strtotime('2026-11-03 10:00:00 UTC');
$r = $bs->score($pdo, 1, [], 45000, null, $noon);
t('typical amount raises nothing', $r['risk_contribution'] === 0, json_encode($r['factors']));
$r = $bs->score($pdo, 1, [], 900000, null, $noon);
t('20x typical amount flagged as anomaly (z-score)', in_array('amount_anomaly', array_column($r['factors'], 'code')));
$r = $bs->score($pdo, 1, [], 45000, ['key_count' => 12, 'key_interval_mean_ms' => 100, 'key_interval_std_ms' => 0.5, 'interaction_events' => 12, 'completion_time_ms' => 4000], $noon);
t('machine-uniform typing flagged as scripted input', in_array('scripted_input', array_column($r['factors'], 'code')));
$r = $bs->score($pdo, 1, [], 45000, ['key_count' => 0, 'interaction_events' => 0, 'completion_time_ms' => 800], $noon);
t('zero human input events flagged', in_array('scripted_input', array_column($r['factors'], 'code')));
$r = $bs->score($pdo, 1, [], 45000, null, strtotime('2026-11-03 01:30:00 UTC')); // 03:30 SAST
t('small-hours activity noted (local timezone)', in_array('odd_hours', array_column($r['factors'], 'code')));
putenv('DEMO_MODE=0'); Config::reset();
$r = $bs->score($pdo, 1, ['new_device' => false, 'ip_country_mismatch' => false], 45000, null, $noon);
t('demo override flags are ignored outside demo mode', $r['anomaly']['source'] === 'baseline_comparison');
putenv('DEMO_MODE=1'); Config::reset();

echo "\n== PayPal request construction (stubbed transport) ==\n";
putenv('PAYPAL_MODE=sandbox'); putenv('PAYPAL_CLIENT_ID=cid'); putenv('PAYPAL_CLIENT_SECRET=sec'); putenv('PAYPAL_CURRENCY=USD'); Config::reset();
@unlink(__DIR__ . '/../storage/paypal_token_sandbox.json');
$calls = [];
$pp = new PayPalService(function ($m, $url, $h, $b) use (&$calls) {
    $calls[] = [$m, $url, $h, $b];
    if (str_contains($url, '/oauth2/token')) return [200, ['access_token' => 'TOK', 'expires_in' => 300]];
    if (str_ends_with($url, '/v2/checkout/orders')) return [201, ['id' => 'ORD123', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://sandbox.paypal.com/checkoutnow?token=ORD123']]]];
    if (str_ends_with($url, '/capture')) return [201, ['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP9']]]]]]];
    return [404, []];
});
$o = $pp->createOrder(['reference_id' => 'tx_1', 'decision_id' => 'dec_abc', 'description' => 'd', 'amount_cents' => 185000, 'return_url' => 'http://x/checkout', 'cancel_url' => 'http://x/checkout?cancelled=1', 'request_id' => 'iv-create-dec_abc']);
$create = array_values(array_filter($calls, fn ($c) => str_ends_with($c[1], '/v2/checkout/orders')))[0];
$body = json_decode($create[3], true);
t('OAuth uses client-credentials with Basic auth', str_contains(json_encode($calls[0][2]), base64_encode('cid:sec')) && $calls[0][3] === 'grant_type=client_credentials');
t('create order hits sandbox Orders v2 with Bearer + PayPal-Request-Id', str_starts_with($create[1], 'https://api-m.sandbox.paypal.com') && in_array('PayPal-Request-Id: iv-create-dec_abc', $create[2]) && in_array('Authorization: Bearer TOK', $create[2]));
t('order is intent CAPTURE; decision id carried in custom_id; ZAR->USD conversion', $body['intent'] === 'CAPTURE' && $body['purchase_units'][0]['custom_id'] === 'dec_abc' && $body['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '100.00'], json_encode($body['purchase_units'][0]['amount']));
t('payer-action link returned as approve_url', $o['approve_url'] === 'https://sandbox.paypal.com/checkoutnow?token=ORD123');
$cap = $pp->captureOrder('ORD123', 'iv-capture-dec_abc');
t('capture returns capture id', $cap['capture_id'] === 'CAP9');
t('webhooks are never trusted without a configured webhook id', !$pp->verifyWebhook([], ['event_type' => 'X']));
@unlink(__DIR__ . '/../storage/paypal_token_sandbox.json');
putenv('PAYPAL_MODE=mock'); Config::reset();

echo "\n== AI risk analyst ==\n";
$tx = ['decision' => 'flagged', 'risk_score' => 65, 'required_action' => 'step_up', 'amount_cents' => 3000000, 'factors_json' => json_encode(['threshold' => 88, 'gates' => [], 'factors' => [['code' => 'dha', 'label' => 'DHA', 'points' => 50, 'max' => 50, 'detail' => 'Name: Jane Test ID 8001015009087']]])];
$sent = null;
putenv('ANTHROPIC_API_KEY=test'); Config::reset();
$ai = new RiskAnalystService(function ($payload) use (&$sent) { $sent = $payload; return ['status' => 200, 'body' => ['content' => [['type' => 'text', 'text' => "```json\n" . json_encode(['analyst_summary' => 'Strong identity evidence but amount needs more.', 'recommended_action' => 'step_up_verification', 'customer_message' => 'One more quick check needed.', 'confidence_note' => 'n/a']) . "\n```"]]]]; });
$r = $ai->analyse($tx);
t('model output parsed (fenced JSON tolerated)', $r['source'] === 'claude' && $r['recommended_action'] === 'step_up_verification');
t('request actually sent to the model contains no names/ID numbers', !str_contains(json_encode($sent), 'Jane') && !str_contains(json_encode($sent), '8001015009087'));
$facts = (new RiskAnalystService())->facts($tx);
t('fact sheet exposes codes/points only - the free-text "detail" field is stripped', !array_key_exists('detail', $facts['factors'][0]) && !str_contains(json_encode($facts), 'Jane'));
$bad = new RiskAnalystService(fn () => ['status' => 200, 'body' => ['content' => [['type' => 'text', 'text' => '{"analyst_summary":"x","recommended_action":"WIRE_ALL_FUNDS","customer_message":"y"}']]]]);
t('out-of-schema model action is rejected -> deterministic fallback', $bad->analyse($tx)['source'] === 'template');
$down = new RiskAnalystService(fn () => ['status' => 529, 'body' => []]);
t('API failure falls back to template analyst', $down->analyse($tx)['source'] === 'template');
putenv('ANTHROPIC_API_KEY='); Config::reset();

echo "\nResult: $pass passed, $fail failed\n";
@unlink(getenv('DB_SQLITE_PATH'));
exit($fail ? 1 : 0);
