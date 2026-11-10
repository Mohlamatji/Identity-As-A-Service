<?php

namespace App\Services;

use App\Support\AuthContext;
use App\Support\Config;
use App\Support\Http;
use App\Support\Tokens;
use PDO;

/**
 * API authentication for integrators (banks, fintechs, marketplaces).
 *
 * Three credential types, one scope model:
 *
 *  1. Secret keys (ivk_...)       Server-to-server. Stored only as SHA-256
 *     hashes in api_clients, individually scoped, revocable, rate-limited
 *     per client. Mint with bin/create-api-client.php (shown once).
 *  2. Client tokens (ivt_...)     Short-lived (15 min), signed, minted by
 *     an integrator's backend via POST /api/client-token and handed to the
 *     BROWSER. Can only run the verification flow. This is what replaces
 *     embedding the long-lived API key in page HTML (which the original
 *     console and dashboard did - leaking it to every visitor).
 *  3. Legacy API_KEY env var      Still accepted, full scope, so existing
 *     integrations don't break. Migrate to (1).
 *
 * With no credentials configured at all, development mode stays open
 * (zero-setup demos); production mode refuses every request.
 */
class ClientAuthService
{
    public const ALL_SCOPES = ['verify', 'consent', 'transactions', 'data_subject', 'admin'];
    public const BROWSER_SAFE_SCOPES = ['verify', 'consent'];

    public function authenticate(PDO $pdo): ?AuthContext
    {
        $presented = Http::bearerOrKey();

        if ($presented !== null && str_starts_with($presented, 'ivt_')) {
            $p = Tokens::verify('client', substr($presented, 4));
            return $p ? new AuthContext('client_token', (string) $p['cid'], (string) $p['name'], (array) $p['scopes'], (int) ($p['rl'] ?? 120), isset($p['uid']) ? (int) $p['uid'] : null) : null;
        }

        if ($presented !== null && str_starts_with($presented, 'ivk_')) {
            $stmt = $pdo->prepare("SELECT * FROM api_clients WHERE key_hash = ? AND status = 'active'");
            $stmt->execute([hash('sha256', $presented)]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$c) {
                return null;
            }
            // Touch last_used_at at most once a minute to avoid a write per request.
            if (!$c['last_used_at'] || strtotime($c['last_used_at'] . ' UTC') < time() - 60) {
                $pdo->prepare('UPDATE api_clients SET last_used_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $c['id']]);
            }
            return new AuthContext('secret', 'c' . $c['id'], $c['name'], array_filter(explode(',', $c['scopes'])), (int) $c['rate_limit_per_min']);
        }

        $security = require __DIR__ . '/../../config/security.php';
        if ($presented !== null && $security['api_key'] !== null && hash_equals($security['api_key'], $presented)) {
            return new AuthContext('legacy', 'legacy', 'Legacy API key', ['*']);
        }

        if ($presented === null && !Config::isProduction() && $security['api_key'] === null && !$this->anyClientsExist($pdo)) {
            return new AuthContext('open', 'open', 'Open development access', ['*']);
        }

        return null;
    }

    /** Mints a short-lived browser token limited to $scopes (only browser-safe ones unless demo mode). */
    public function mintClientToken(AuthContext $issuer, array $scopes, ?int $userId, bool $allowDemoScopes = false): array
    {
        $allowed = $allowDemoScopes && Config::get('demo_mode') ? array_diff(self::ALL_SCOPES, ['admin']) : self::BROWSER_SAFE_SCOPES;
        $granted = array_values(array_intersect($scopes ?: self::BROWSER_SAFE_SCOPES, $allowed, $issuer->scopes === ['*'] ? self::ALL_SCOPES : $issuer->scopes));
        $ttl = (int) Config::get('tokens.client_token_ttl', 900);
        $payload = ['cid' => $issuer->clientId, 'name' => $issuer->name, 'scopes' => $granted, 'rl' => $issuer->rateLimitPerMin];
        if ($userId !== null) {
            $payload['uid'] = $userId;
        }
        return ['client_token' => 'ivt_' . Tokens::sign('client', $payload, $ttl), 'expires_in' => $ttl, 'scopes' => $granted];
    }

    /** Creates a client and returns the plaintext key ONCE. */
    public static function createClient(PDO $pdo, string $name, array $scopes, int $rateLimit = 120, string $env = 'live'): array
    {
        $key = 'ivk_' . $env . '_' . EncryptionService::randomToken(24);
        $pdo->prepare('INSERT INTO api_clients (name, key_prefix, key_hash, scopes, rate_limit_per_min) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, substr($key, 0, 12), hash('sha256', $key), implode(',', $scopes), $rateLimit]);
        return ['id' => (int) $pdo->lastInsertId(), 'key' => $key];
    }

    private function anyClientsExist(PDO $pdo): bool
    {
        static $exists = null;
        return $exists ??= ((int) $pdo->query('SELECT COUNT(*) FROM api_clients')->fetchColumn() > 0);
    }
}
