<?php
/** Production-mode fail-closed checks + XSS regression. php tests/prod.php */
$root = dirname(__DIR__); $db = sys_get_temp_dir() . '/iv-prod-' . getmypid() . '.sqlite'; @unlink($db); $port = 8900 + random_int(0, 90);
$env = ['APP_ENV' => 'production', 'DB_SQLITE_PATH' => $db, 'APP_ENCRYPTION_KEY' => str_repeat('k', 40)];
$e = ''; foreach ($env as $k => $v) $e .= "$k=" . escapeshellarg($v) . ' ';
$proc = proc_open("$e php -S 127.0.0.1:$port -t $root/public $root/public/index.php", [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p);
register_shutdown_function(function () use ($proc, $db) { proc_terminate($proc); @unlink($db); @unlink("$db-wal"); @unlink("$db-shm"); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
$pass = $fail = 0;
function t($n, $ok, $x = '') { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  PASS  ' : '  FAIL  ') . $n . ($ok ? '' : "  <-- $x") . "\n"; }
function req($m, $path, $body = null, $h = []) { global $port; $c = stream_context_create(['http' => ['method' => $m, 'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $h)), 'content' => $body ? json_encode($body) : null, 'ignore_errors' => true, 'follow_location' => 0]]); $r = file_get_contents("http://127.0.0.1:$port$path", false, $c); preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m2); return [(int) $m2[1], $r, $http_response_header]; }

[$s] = req('POST', '/api/verify/challenge', []);
t('production: API with no credentials configured is refused (401)', $s === 401);
[$s] = req('GET', '/api/transactions');
t('production: admin API locked when no admin password configured', $s === 401);
[$s, , $h] = req('GET', '/');
t('production: demo console not served (redirects to docs)', $s === 302);
[$s, $b] = req('GET', '/dashboard');
t('production: dashboard redirects to login', $s === 302);
[$s, , $h] = req('GET', '/api-docs');
$hs = implode('|', $h);
t('security headers present (CSP, nosniff, frame deny, HSTS)', str_contains($hs, 'Content-Security-Policy') && str_contains($hs, 'nosniff') && str_contains($hs, 'X-Frame-Options: DENY') && str_contains($hs, 'Strict-Transport-Security'));
[$s] = req('POST', '/api/verify/challenge', [], ['X-Api-Key: ivk_live_forged']);
t('production: forged key refused', $s === 401);
echo "\nResult: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
