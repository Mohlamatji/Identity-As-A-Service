<?php
/**
 * Mint an integrator API key. The plaintext key is shown ONCE - only its
 * SHA-256 hash is stored.
 *
 *   php bin/create-api-client.php --name="Demo Bank" --scopes=verify,consent,transactions [--rate=120] [--env=live|test]
 *
 * Scopes: verify, consent, transactions, data_subject, admin
 */
require __DIR__ . '/../vendor_autoload.php';
if (file_exists(__DIR__ . '/../config/local.php')) {
    require __DIR__ . '/../config/local.php';
}
require __DIR__ . '/../config/database.php';

use App\Services\ClientAuthService;

$o = getopt('', ['name:', 'scopes:', 'rate::', 'env::']);
if (empty($o['name']) || empty($o['scopes'])) {
    fwrite(STDERR, "Usage: php bin/create-api-client.php --name=\"Demo Bank\" --scopes=verify,consent,transactions [--rate=120] [--env=live|test]\n");
    exit(1);
}
$scopes = array_map('trim', explode(',', $o['scopes']));
$bad = array_diff($scopes, ClientAuthService::ALL_SCOPES);
if ($bad) {
    fwrite(STDERR, 'Unknown scope(s): ' . implode(', ', $bad) . "\n");
    exit(1);
}
$c = ClientAuthService::createClient(get_pdo(), $o['name'], $scopes, (int) ($o['rate'] ?? 120), $o['env'] ?? 'live');
echo "Client #{$c['id']} created for \"{$o['name']}\" (scopes: " . implode(',', $scopes) . ")\n";
echo "API key (shown once - store it now):\n  {$c['key']}\n";
