<?php

namespace App\Controllers;

use App\Models\Transaction;
use App\Models\AuditLog;
use App\Models\DhaVerification;
use App\Services\RetentionService;
use PDO;

class AdminController
{
    public function dashboard(): void
    {
        require __DIR__ . '/../Views/dashboard.php';
    }

    public function loginForm(?string $loginError = null): void
    {
        require __DIR__ . '/../Views/login.php';
    }

    public function login(): void
    {
        $security = require __DIR__ . '/../../config/security.php';
        $submitted = $_POST['password'] ?? '';

        if ($security['admin_password'] !== null && hash_equals($security['admin_password'], $submitted)) {
            $_SESSION['admin_authenticated'] = true;
            header('Location: dashboard');
            exit;
        }

        $this->loginForm('Incorrect password.');
    }

    public function logout(): void
    {
        unset($_SESSION['admin_authenticated']);
        header('Location: login');
        exit;
    }

    /** GET /api/transactions - recent transactions across all users, for the dashboard table. */
    public function transactions(PDO $pdo): void
    {
        $rows = Transaction::recentAll($pdo, 50);
        $rows = array_map(function ($tx) {
            $tx['amount_display'] = Transaction::formatZar((int) $tx['amount_cents']);
            return $tx;
        }, $rows);

        header('Content-Type: application/json');
        echo json_encode($rows);
    }

    /** GET /api/audit-log */
    public function auditLog(PDO $pdo): void
    {
        header('Content-Type: application/json');
        echo json_encode(AuditLog::all($pdo, 100));
    }

    /**
     * GET /api/dha-verifications?user_id=1
     * Full (decrypted) bureau response history for one user - for
     * compliance/investigation use. Requires user_id since these payloads
     * contain PII (names, DOB) and browsing them for arbitrary users
     * without a reason isn't something even an internal dashboard should
     * make casual.
     */
    public function dhaVerifications(PDO $pdo): void
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        if (!$userId) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'user_id query param is required.']);
            return;
        }

        header('Content-Type: application/json');
        echo json_encode(DhaVerification::recentForUser($pdo, $userId, 20));
    }

    /**
     * POST /api/admin/retention/run
     * Runs the configured retention policy on demand - the same logic
     * bin/purge-expired-data.php runs via cron, exposed here so the
     * mechanism is demonstrable without needing real cron access set up.
     */
    public function runRetention(PDO $pdo): void
    {
        $result = (new RetentionService())->runAll($pdo);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'complete'] + $result);
    }
}
