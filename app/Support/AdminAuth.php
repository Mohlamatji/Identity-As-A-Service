<?php

namespace App\Support;

use App\Services\RateLimitService;
use PDO;

/**
 * Admin (dashboard) authentication: session login with a hashed password,
 * login throttling, session fixation protection and a per-session CSRF
 * token for state-changing admin calls.
 *
 * Development with no admin password configured stays open (as before) so
 * `php -S` just works; production with no password configured is LOCKED.
 */
final class AdminAuth
{
    public static function isConfigured(): bool
    {
        $s = require __DIR__ . '/../../config/security.php';
        return $s['admin_password_hash'] !== null || $s['admin_password'] !== null;
    }

    public static function isAuthenticated(): bool
    {
        if (!self::isConfigured()) {
            return !Config::isProduction();
        }
        return !empty($_SESSION['admin_authenticated']);
    }

    /** @return string|null error message, or null on success */
    public static function attemptLogin(PDO $pdo, string $password): ?string
    {
        $bucket = RateLimitService::bucketKey(Http::ip(), 'admin_login');
        if (!(new RateLimitService())->allow($pdo, $bucket, 5, 300)) {
            return 'Too many login attempts. Try again in a few minutes.';
        }
        $s = require __DIR__ . '/../../config/security.php';
        $ok = false;
        if ($s['admin_password_hash'] !== null) {
            $ok = password_verify($password, $s['admin_password_hash']);
        } elseif ($s['admin_password'] !== null) {
            $ok = hash_equals($s['admin_password'], $password);
        }
        if (!$ok) {
            return 'Incorrect password.';
        }
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return null;
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrfValid(): bool
    {
        $sent = Http::header('X-CSRF-Token') ?? '';
        return $sent !== '' && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
    }
}
