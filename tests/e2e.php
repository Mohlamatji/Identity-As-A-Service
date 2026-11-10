<?php
/**
 * End-to-end HTTP tests against a real `php -S` server (no mocks of our own
 * code). Plays the role of a browser/SDK: server-issued challenge ->
 * evidence with synthetic face descriptors -> verification token -> approval
 * -> PayPal (mock mode) -> capture, plus the attack cases the old design
 * would have failed.
 *
 *   php tests/e2e.php
 */
$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/iv-e2e-' . getmypid() . '.sqlite';
@unlink($db);
$port = 8700 + random_int(0, 200);

$env = [
    'APP_ENV' => 'development', 'DB_SQLITE_PATH' => $db, 'APP_ENCRYPTION_KEY' => 'e2e-test-key-0123456789abcdef0123456789',
    'PAYPAL_MODE' => 'mock', 'ALLOW_SIMULATED_LIVENESS' => '0', 'VERIFICATION_PROVIDER' => 'mock',
];
$envStr = '';
foreach ($env as $k => $v) { $envStr .= "$k=" . escapeshellarg($v) . ' '; }
$proc = proc_open("$envStr php -S 127.0.0.1:$port -t $root/public $root/public/index.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($proc, $db) { proc_terminate($proc); @unlink($db); @unlink($db . '-wal'); @unlink($db . '-shm'); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . ($ok ? '' : "   <-- $extra") . "\n";
}
function call(string $method, string $path, ?array $body = null, array $headers = []): array {
    global $port;
    $h = array_merge(['Content-Type: application/json'], $headers);
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $body !== null ? json_encode($body) : null, 'ignore_errors' => true]]);
    $raw = file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), json_decode((string) $raw, true) ?? [], $http_response_header ?? []];
}
function descriptor(array $base, float $noise = 0.01): array {
    return array_map(fn ($x) => $x + (mt_rand(-1000, 1000) / 1000) * $noise, $base);
}
function person(int $seed): array { mt_srand($seed); return array_map(fn () => mt_rand(-150, 150) / 1000, range(1, 128)); }
function evidence(array $challenge, array $base, array $over = []): array {
    $t = 300;
    $actions = array_map(function ($a) use (&$t) { $t += 400; return ['action' => $a, 'completed' => true, 't_ms' => $t]; }, $challenge['actions']);
    return array_merge([
        'mode' => 'real', 'actions' => $actions, 'duration_ms' => 1800, 'frames_analyzed' => 30,
        'descriptors' => [descriptor($base), descriptor($base), descriptor($base), descriptor($base)],
        'image_base64' => base64_encode('frame'),
    ], $over);
}
function newChallenge(array $extra = []): array { [$s, $c] = call('POST', '/api/verify/challenge', $extra); sleep(1); return $c; }

$jane = person(1); $mallory = person(2);
$ID = '8001015009087'; $DOB = '1980-01-01';
$profile = ['id_number' => $ID, 'full_name' => 'Jane Test', 'dob' => $DOB, 'phone' => '0821234567'];

echo "\n== Enrollment & liveness ==\n";
[$s, $r] = call('POST', '/api/enroll', $profile + ['image_base64' => 'x']);
check('enroll without challenge is refused', $s === 422);
[$s, $r] = call('POST', '/api/enroll', array_merge($profile, ['id_number' => '8001015009088', 'image_base64' => 'x', 'challenge_id' => 'abc']));
check('invalid SA ID checksum is refused', $s === 422 && str_contains($r['message'], 'checksum'));
[$s, $r] = call('POST', '/api/enroll', array_merge($profile, ['dob' => '1990-01-01', 'image_base64' => 'x', 'challenge_id' => 'abc']));
check('DOB that disagrees with ID number is refused', $s === 422 && str_contains($r['message'], 'date of birth'));

$ch = newChallenge();
check('challenge issues 3 random actions', ($s === 200 || true) && count($ch['actions'] ?? []) === 3);
$bad = evidence($ch, $jane, ['descriptors' => array_fill(0, 4, $jane)]); // perfectly static = photo
[$s, $r] = call('POST', '/api/enroll', $profile + $bad + ['challenge_id' => $ch['challenge_id']]);
check('static-photo evidence fails liveness and enrolls nothing', $r['status'] === 'rejected' && empty($r['verification_token']), json_encode($r));

$ch = newChallenge();
$wrongOrder = evidence($ch, $jane); $wrongOrder['actions'] = array_reverse($wrongOrder['actions']);
[$s, $r] = call('POST', '/api/enroll', $profile + $wrongOrder + ['challenge_id' => $ch['challenge_id']]);
check('wrong action sequence fails liveness', $r['status'] === 'rejected');

$ch = newChallenge();
[$s, $r] = call('POST', '/api/enroll', $profile + ['mode' => 'simulated', 'image_base64' => 'x', 'challenge_id' => $ch['challenge_id']]);
check('simulated liveness refused when disabled', $r['status'] === 'rejected');

$ch = newChallenge();
$ev = evidence($ch, $jane);
[$s, $r] = call('POST', '/api/enroll', $profile + $ev + ['challenge_id' => $ch['challenge_id']]);
check('genuine challenge response enrolls', $r['status'] === 'enrolled' && !empty($r['verification_token']), json_encode($r));
$userId = $r['user_id'];
[$s, $r2] = call('POST', '/api/enroll', $profile + $ev + ['challenge_id' => $ch['challenge_id']]);
check('challenge cannot be replayed', $s === 422);

echo "\n== Biometric match ==\n";
$ch = newChallenge(['user_id' => $userId]);
[$s, $r] = call('POST', '/api/verify/session', ['user_id' => $userId, 'challenge_id' => $ch['challenge_id']] + evidence($ch, $jane));
check('same face is approved and returns a token', $r['status'] === 'approved' && str_starts_with($r['verification_token'] ?? '', 'ivs_'), json_encode($r));
$token = $r['verification_token'];

echo "\n== Transaction approval ==\n";
$tx = ['user_id' => $userId, 'id_number' => $ID, 'amount_cents' => 50000, 'currency' => 'ZAR', 'bank_partner_id' => 'demo-bank'];
[$s, $r] = call('POST', '/api/transaction/approve', $tx + ['liveness' => ['passed' => true], 'vault_match' => ['is_first_enrollment' => false, 'matched' => true, 'confidence' => 1.0]]);
check('FORGED liveness/vault_match in body no longer works (token required)', $s === 422, json_encode($r));
[$s, $r] = call('POST', '/api/transaction/approve', $tx + ['verification_token' => $token]);
check('approve without consent is blocked (403)', $s === 403 && str_contains($r['message'], 'consent'));
[$s, $r] = call('POST', '/api/consent/grant', ['user_id' => $userId, 'reason' => 'Fraud Prevention/Fraud Detection', 'validity_days' => 30]);
check('consent granted', $s === 200);
[$s, $r] = call('POST', '/api/transaction/approve', $tx + ['verification_token' => $token], ['Idempotency-Key: e2e-key-0001']);
check('approve with valid token succeeds', $s === 200 && in_array($r['status'], ['approved', 'flagged']) , json_encode($r));
check('response carries signed receipt + factors', !empty($r['receipt']) && count($r['factors'] ?? []) > 2);
$txId = $r['transaction_id'];
[$s2, $r2, $h2] = call('POST', '/api/transaction/approve', $tx + ['verification_token' => $token], ['Idempotency-Key: e2e-key-0001']);
check('idempotent retry replays original response', $s2 === 200 && $r2['transaction_id'] === $txId && stripos(implode('|', $h2), 'Idempotent-Replay') !== false);
[$s3, $r3] = call('POST', '/api/transaction/approve', $tx + ['verification_token' => $token], ['Idempotency-Key: e2e-key-0002']);
check('token is single-use', $s3 === 403 && str_contains($r3['message'], 'already been used'));
[$s4, $r4] = call('POST', '/api/transaction/approve', array_merge($tx, ['amount_cents' => 60000, 'verification_token' => $token]), ['Idempotency-Key: e2e-key-0001']);
check('same Idempotency-Key + different body = 409', $s4 === 409);

// amount binding
$ch = newChallenge(['user_id' => $userId, 'amount_cents' => 10000, 'payee' => 'shop-a']);
[$s, $r] = call('POST', '/api/verify/session', ['user_id' => $userId, 'challenge_id' => $ch['challenge_id']] + evidence($ch, $jane));
[$s, $r2] = call('POST', '/api/transaction/approve', array_merge($tx, ['amount_cents' => 900000, 'verification_token' => $r['verification_token']]));
check('token bound to R100 cannot approve R9,000', $s === 403 && str_contains($r2['message'], 'different amount'));

echo "\n== PayPal gating (mock mode) ==\n";
$ch = newChallenge(['user_id' => $userId]);
[$s, $r] = call('POST', '/api/verify/session', ['user_id' => $userId, 'challenge_id' => $ch['challenge_id']] + evidence($ch, $jane));
[$s, $r] = call('POST', '/api/paypal/orders', ['user_id' => $userId, 'id_number' => $ID, 'amount_cents' => 50000, 'verification_token' => $r['verification_token']]);
check('approved payment creates a PayPal order', $s === 200 && !empty($r['paypal']['order_id']), json_encode($r));
if (!empty($r['paypal']['order_id'])) {
    [$s, $c] = call('POST', '/api/paypal/orders/' . $r['paypal']['order_id'] . '/capture', []);
    check('capture succeeds with valid receipt', $s === 200 && $c['status'] === 'COMPLETED');
    [$s, $v] = call('POST', '/api/receipt/verify', ['receipt' => $r['receipt']]);
    check('receipt verifies', $v['valid'] === true);
    [$s, $v] = call('POST', '/api/receipt/verify', ['receipt' => $r['receipt'] . 'x']);
    check('tampered receipt is rejected', $v['valid'] === false);
}
$ch = newChallenge(['user_id' => $userId]);
[$s, $r] = call('POST', '/api/verify/session', ['user_id' => $userId, 'challenge_id' => $ch['challenge_id']] + evidence($ch, $mallory));
check('different face is rejected', $r['status'] === 'rejected' && !$r['vault_match']['matched']);
$ch = newChallenge(['user_id' => $userId]);
[$s, $r] = call('POST', '/api/verify/session', ['user_id' => $userId, 'challenge_id' => $ch['challenge_id']] + evidence($ch, $mallory));
check('failed verification yields no token', empty($r['verification_token']));

echo "\n== Admin & audit ==\n";
[$s, $r] = call('GET', '/api/admin/stats');
check('stats endpoint works', $s === 200 && $r['total'] >= 1, json_encode($r));
[$s, $r] = call('POST', "/api/admin/transactions/$txId/explain");
check('AI analyst falls back to template with no key', $s === 200 && $r['source'] === 'template' && in_array($r['recommended_action'], ['approve','step_up_verification','manual_review','block_and_monitor']));
[$s, $r] = call('GET', '/api/admin/audit/verify');
check('audit hash chain verifies', $s === 200 && $r['valid'] === true && $r['checked'] > 5, json_encode($r));
$pdo = new PDO('sqlite:' . $db);
$pdo->exec("UPDATE audit_log SET action = 'tampered' WHERE id = 3");
[$s, $r] = call('GET', '/api/admin/audit/verify');
check('tampering with an audit row is detected', $r['valid'] === false && $r['broken_at_id'] === 3, json_encode($r));

echo "\n== Data at rest ==\n";
$row = $pdo->query('SELECT template_vector, algorithm_version FROM biometric_templates LIMIT 1')->fetch(PDO::FETCH_ASSOC);
check('biometric template stored AES-GCM (v2:)', str_starts_with($row['template_vector'], 'v2:'));
$u = $pdo->query('SELECT id_number_hash FROM users LIMIT 1')->fetchColumn();
check('ID lookup key is keyed HMAC, not plain SHA-256', $u !== hash('sha256', $ID) && strlen($u) === 64);

echo "\nResult: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
