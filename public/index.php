<?php

require __DIR__ . '/../vendor_autoload.php';

// Local secrets (config/local.php) must load before anything reads getenv().
if (file_exists(__DIR__ . '/../config/local.php')) {
    require __DIR__ . '/../config/local.php';
}
require __DIR__ . '/../config/database.php';

use App\Controllers\AdminController;
use App\Controllers\ApiController;
use App\Controllers\PayPalController;
use App\Controllers\WebhookController;
use App\Services\ClientAuthService;
use App\Services\IdempotencyService;
use App\Services\RateLimitService;
use App\Support\AdminAuth;
use App\Support\AuthContext;
use App\Support\Config;
use App\Support\Http;

date_default_timezone_set('UTC'); // storage is UTC; local-time logic uses Config timezone explicitly

Http::securityHeaders();
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

// Never leak stack traces / SQL to clients.
set_exception_handler(function (Throwable $e) {
    error_log('[identity-vault] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        Http::json(500, ['status' => 'error', 'message' => 'Internal error.']);
    }
});

$pdo = get_pdo();

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = $basePath !== '' && str_starts_with($requestPath, $basePath) ? substr($requestPath, strlen($basePath)) : $requestPath;
$path = $path === '' ? '/' : $path;
$method = $_SERVER['REQUEST_METHOD'];

$api = new ApiController();
$admin = new AdminController();
$paypal = new PayPalController();
$webhook = new WebhookController();
$demo = Config::get('demo_mode');

// ---- Page routes ---------------------------------------------------------
$page = static function (string $view) {
    return static function () use ($view) { require __DIR__ . '/../app/Views/' . $view . '.php'; };
};
$demoOnly = static function (callable $render) use ($demo) {
    return static function () use ($render, $demo) {
        if (!$demo) { header('Location: api-docs'); exit; }
        $render();
    };
};

$pages = [
    ['GET', '#^/$#', $demoOnly($page('onboarding'))],
    ['GET', '#^/checkout$#', $demoOnly($page('checkout'))],
    ['GET', '#^/checkout/mock-paypal$#', $demoOnly($page('mock-paypal'))],
    ['GET', '#^/api-docs$#', $page('api-docs')],
    ['GET', '#^/dashboard$#', function () use ($admin) {
        if (!AdminAuth::isAuthenticated()) { header('Location: login'); exit; }
        $admin->dashboard();
    }],
    ['GET', '#^/login$#', fn () => $admin->loginForm()],
    ['POST', '#^/login$#', fn () => $admin->login($pdo)],
    ['GET', '#^/logout$#', fn () => $admin->logout()],
];
foreach ($pages as [$m, $re, $handler]) {
    if ($m === $method && preg_match($re, $path)) {
        $handler();
        exit;
    }
}

// ---- API routes ----------------------------------------------------------
// [method, regex, scope|null|'admin', handler(match[]), options]
$routes = [
    ['POST', '#^/api/client-token$#', 'verify', fn () => $api->clientToken($pdo, $GLOBALS['auth'])],
    ['POST', '#^/api/verify/challenge$#', 'verify', fn () => $api->challenge($pdo, $GLOBALS['auth']), ['limit' => ['challenge', 30]]],
    ['POST', '#^/api/verify/session$#', 'verify', fn () => $api->authenticate($pdo, $GLOBALS['auth']), ['limit' => ['authenticate', 10]]],
    ['POST', '#^/api/authenticate$#', 'verify', fn () => $api->authenticate($pdo, $GLOBALS['auth']), ['limit' => ['authenticate', 10]]],
    ['POST', '#^/api/enroll$#', 'verify', fn () => $api->enroll($pdo, $GLOBALS['auth']), ['limit' => ['enroll', 10]]],
    ['POST', '#^/api/transaction/approve$#', 'transactions', fn () => $api->transactionApprove($pdo, $GLOBALS['auth']), ['limit' => ['approve', 20], 'idempotent' => true]],
    ['POST', '#^/api/behavior/update$#', 'transactions', fn () => $api->behaviorUpdate($pdo, $GLOBALS['auth'])],
    ['GET', '#^/api/fraud/check$#', 'transactions', fn () => $api->fraudCheck($pdo, $GLOBALS['auth'])],
    ['POST', '#^/api/consent/grant$#', 'consent', fn () => $api->consentGrant($pdo, $GLOBALS['auth'])],
    ['GET', '#^/api/consent/status$#', 'consent', fn () => $api->consentStatus($pdo, $GLOBALS['auth'])],
    ['POST', '#^/api/consent/revoke$#', 'consent', fn () => $api->consentRevoke($pdo, $GLOBALS['auth'])],
    ['GET', '#^/api/data-subject/export$#', 'data_subject', fn () => $api->dataSubjectExport($pdo, $GLOBALS['auth'])],
    ['POST', '#^/api/data-subject/delete-request$#', 'data_subject', fn () => $api->dataSubjectDeleteRequest($pdo, $GLOBALS['auth'])],
    ['POST', '#^/api/webhook/notify/(\d+)$#', 'transactions', fn ($m) => $webhook->notify($pdo, $GLOBALS['auth'], (int) $m[1])],
    ['GET', '#^/api/paypal/config$#', 'verify', fn () => $paypal->config()],
    ['POST', '#^/api/paypal/orders$#', 'transactions', fn () => $paypal->createOrder($pdo, $GLOBALS['auth']), ['limit' => ['approve', 20], 'idempotent' => true]],
    ['POST', '#^/api/paypal/orders/([A-Z0-9]{6,40})/capture$#', 'transactions', fn ($m) => $paypal->capture($pdo, $GLOBALS['auth'], $m[1]), ['idempotent' => true]],
    ['POST', '#^/api/receipt/verify$#', 'transactions', fn () => $paypal->verifyReceipt()],
    ['POST', '#^/api/paypal/webhook$#', null, fn () => $paypal->webhook($pdo)],
    // Admin (session login OR a key with the admin scope)
    ['GET', '#^/api/(?:admin/)?transactions$#', 'admin', fn () => $admin->transactions($pdo)],
    ['GET', '#^/api/audit-log$#', 'admin', fn () => $admin->auditLog($pdo)],
    ['GET', '#^/api/admin/audit/verify$#', 'admin', fn () => $admin->auditVerify($pdo)],
    ['GET', '#^/api/admin/stats$#', 'admin', fn () => $admin->stats($pdo)],
    ['GET', '#^/api/admin/transactions/(\d+)$#', 'admin', fn ($m) => $admin->transactionDetail($pdo, (int) $m[1])],
    ['POST', '#^/api/admin/transactions/(\d+)/explain$#', 'admin', fn ($m) => $admin->explain($pdo, (int) $m[1])],
    ['GET', '#^/api/dha-verifications$#', 'admin', fn () => $admin->dhaVerifications($pdo)],
    ['POST', '#^/api/admin/retention/run$#', 'admin', fn () => $admin->runRetention($pdo)],
];

foreach ($routes as $route) {
    [$m, $re, $scope, $handler] = $route;
    $opts = $route[4] ?? [];
    if ($m !== $method || !preg_match($re, $path, $match)) {
        continue;
    }

    // --- authentication ---
    $auth = null;
    if ($scope === 'admin') {
        if (AdminAuth::isAuthenticated() && AdminAuth::isConfigured() && !empty($_SESSION['admin_authenticated'])) {
            if ($method === 'POST' && !AdminAuth::csrfValid()) {
                Http::json(403, ['status' => 'error', 'message' => 'Missing or invalid CSRF token.']);
                exit;
            }
            $auth = new AuthContext('admin_session', 'admin', 'Admin session', ['*']);
        } else {
            $keyAuth = (new ClientAuthService())->authenticate($pdo);
            if ($keyAuth && $keyAuth->can('admin') && $keyAuth->type !== 'client_token') {
                $auth = $keyAuth;
            } elseif (!AdminAuth::isConfigured() && !Config::isProduction()) {
                $auth = new AuthContext('open', 'open', 'Open development access', ['*']); // dev, no admin password set
            }
        }
        if (!$auth) {
            Http::json(401, ['status' => 'error', 'message' => 'Admin authentication required.']);
            exit;
        }
    } elseif ($scope !== null) {
        $auth = (new ClientAuthService())->authenticate($pdo);
        if (!$auth) {
            Http::json(401, ['status' => 'error', 'message' => 'Missing or invalid credentials. Send X-Api-Key (ivk_...) or Authorization: Bearer <ivt_ token>.']);
            exit;
        }
        if (!$auth->can($scope)) {
            Http::json(403, ['status' => 'error', 'message' => "Credential lacks the '{$scope}' scope."]);
            exit;
        }
    }
    $GLOBALS['auth'] = $auth;
    $GLOBALS['pdo'] = $pdo;

    // --- rate limiting: per client, plus a tighter per-IP bucket on sensitive routes ---
    $limiter = new RateLimitService();
    if ($auth && !$limiter->allow($pdo, 'client:' . $auth->clientId, $auth->rateLimitPerMin, 60)) {
        Http::json(429, ['status' => 'error', 'message' => 'Rate limit exceeded for this client.'], ['Retry-After' => '30']);
        exit;
    }
    if (isset($opts['limit']) && !$limiter->allow($pdo, RateLimitService::bucketKey(Http::ip(), $opts['limit'][0]), $opts['limit'][1], 60)) {
        Http::json(429, ['status' => 'error', 'message' => 'Too many requests. Try again shortly.'], ['Retry-After' => '30']);
        exit;
    }

    // --- idempotency for money-moving routes ---
    $idemKey = null;
    $idem = new IdempotencyService();
    if (!empty($opts['idempotent']) && $auth) {
        $idemKey = Http::header('Idempotency-Key');
        if ($idemKey === null && Config::isProduction()) {
            Http::json(422, ['status' => 'error', 'message' => 'Idempotency-Key header is required on this endpoint.']);
            exit;
        }
        if ($idemKey !== null) {
            if (!IdempotencyService::validKey($idemKey)) {
                Http::json(422, ['status' => 'error', 'message' => 'Idempotency-Key must be 8-128 chars of [A-Za-z0-9_-:.].']);
                exit;
            }
            $state = $idem->begin($pdo, $auth->clientId, $idemKey, hash('sha256', $method . $path . Http::rawBody()));
            if ($state['state'] === 'replay') {
                Http::json($state['status'], json_decode($state['body'], true) ?? [], ['Idempotent-Replay' => 'true']);
                exit;
            }
            if ($state['state'] === 'conflict') {
                Http::json(409, ['status' => 'error', 'message' => 'Idempotency-Key was already used with a different request.']);
                exit;
            }
            if ($state['state'] === 'in_flight') {
                Http::json(409, ['status' => 'error', 'message' => 'A request with this Idempotency-Key is still in progress.']);
                exit;
            }
        }
    }

    try {
        [$status, $body] = $handler($match);
    } catch (Throwable $e) {
        if ($idemKey !== null) {
            $idem->abandon($pdo, $auth->clientId, $idemKey);
        }
        throw $e;
    }
    if ($idemKey !== null) {
        $idem->finish($pdo, $auth->clientId, $idemKey, $status, json_encode($body));
    }
    Http::json($status, $body);
    exit;
}

Http::json(404, ['error' => 'Not found', 'path' => $path]);
