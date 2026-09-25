<?php

require __DIR__ . '/../vendor_autoload.php';

// Local secrets, if you've set them up - see config/local.php.example.
// Must load before database.php/providers.php/security.php, since those
// all read secrets via getenv() at require-time.
if (file_exists(__DIR__ . '/../config/local.php')) {
    require __DIR__ . '/../config/local.php';
}

require __DIR__ . '/../config/database.php';

use App\Controllers\ApiController;
use App\Controllers\WebhookController;
use App\Controllers\AdminController;

session_start();

$pdo = get_pdo();
$security = require __DIR__ . '/../config/security.php';

// Strip whatever subfolder this app happens to be served from (e.g. when
// dropped into an Apache/XAMPP htdocs folder as htdocs/identity-vault/public,
// requests arrive as "/identity-vault/public/dashboard"), so routes below
// can match on the clean path regardless of deployment depth. Confirmed
// empirically: PHP's built-in server always reports SCRIPT_NAME as
// "/index.php" here (basePath ""), and Apache with public/.htaccess
// rewriting to index.php reports the full script path (e.g.
// "/identity-vault/public/index.php", basePath "/identity-vault/public") -
// both cases are handled correctly by this one computation.
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = $basePath !== '' && str_starts_with($requestPath, $basePath)
    ? substr($requestPath, strlen($basePath))
    : $requestPath;
if ($path === '') {
    $path = '/';
}
$method = $_SERVER['REQUEST_METHOD'];

// API key check, opt-in via config/security.php (API_KEY env var). Off by
// default so local `php -S` testing and the README curl examples keep
// working with zero setup - set API_KEY before deploying anywhere public.
if (str_starts_with($path, '/api/') && $security['api_key'] !== null) {
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($security['api_key'], $providedKey)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Missing or invalid X-Api-Key header.']);
        exit;
    }
}

// Dashboard session check, opt-in via config/security.php (ADMIN_PASSWORD
// env var). Off by default, same reasoning as the API key above.
if ($path === '/dashboard' && $security['admin_password'] !== null && empty($_SESSION['admin_authenticated'])) {
    header('Location: login');
    exit;
}

// Very small router - fine for an MVP/pilot demo. Swap for a real router
// (or a micro-framework) once route count grows.
if ($path === '/' && $method === 'GET') {
    require __DIR__ . '/../app/Views/onboarding.php';
} elseif ($path === '/dashboard' && $method === 'GET') {
    (new AdminController())->dashboard();
} elseif ($path === '/login' && $method === 'GET') {
    (new AdminController())->loginForm();
} elseif ($path === '/login' && $method === 'POST') {
    (new AdminController())->login();
} elseif ($path === '/logout' && $method === 'GET') {
    (new AdminController())->logout();
} elseif ($path === '/api-docs' && $method === 'GET') {
    require __DIR__ . '/../app/Views/api-docs.php';
} elseif ($path === '/api/enroll' && $method === 'POST') {
    (new ApiController())->enroll($pdo);
} elseif ($path === '/api/authenticate' && $method === 'POST') {
    (new ApiController())->authenticate($pdo);
} elseif ($path === '/api/transaction/approve' && $method === 'POST') {
    (new ApiController())->transactionApprove($pdo);
} elseif ($path === '/api/behavior/update' && $method === 'POST') {
    (new ApiController())->behaviorUpdate($pdo);
} elseif ($path === '/api/fraud/check' && $method === 'GET') {
    (new ApiController())->fraudCheck($pdo);
} elseif ($path === '/api/consent/grant' && $method === 'POST') {
    (new ApiController())->consentGrant($pdo);
} elseif ($path === '/api/consent/status' && $method === 'GET') {
    (new ApiController())->consentStatus($pdo);
} elseif ($path === '/api/consent/revoke' && $method === 'POST') {
    (new ApiController())->consentRevoke($pdo);
} elseif ($path === '/api/data-subject/export' && $method === 'GET') {
    (new ApiController())->dataSubjectExport($pdo);
} elseif ($path === '/api/data-subject/delete-request' && $method === 'POST') {
    (new ApiController())->dataSubjectDeleteRequest($pdo);
} elseif ($path === '/api/admin/retention/run' && $method === 'POST') {
    (new AdminController())->runRetention($pdo);
} elseif ($path === '/api/dha-verifications' && $method === 'GET') {
    (new AdminController())->dhaVerifications($pdo);
} elseif ($path === '/api/transactions' && $method === 'GET') {
    (new AdminController())->transactions($pdo);
} elseif ($path === '/api/audit-log' && $method === 'GET') {
    (new AdminController())->auditLog($pdo);
} elseif (preg_match('#^/api/webhook/notify/(\d+)$#', $path, $m) && $method === 'POST') {
    (new WebhookController())->notify($pdo, (int) $m[1]);
} else {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found', 'path' => $path]);
}
