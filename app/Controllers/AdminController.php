<?php

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\DhaVerification;
use App\Models\Transaction;
use App\Services\RetentionService;
use App\Services\RiskAnalystService;
use App\Support\AdminAuth;
use App\Support\Http;
use PDO;

class AdminController
{
    public function dashboard(): void
    {
        $csrf = AdminAuth::csrfToken();
        require __DIR__ . '/../Views/dashboard.php';
    }

    public function loginForm(?string $loginError = null): void
    {
        $_SESSION['login_csrf'] = $_SESSION['login_csrf'] ?? bin2hex(random_bytes(16));
        $loginCsrf = $_SESSION['login_csrf'];
        $configured = AdminAuth::isConfigured();
        require __DIR__ . '/../Views/login.php';
    }

    public function login(PDO $pdo): void
    {
        $sent = (string) ($_POST['csrf'] ?? '');
        if (empty($_SESSION['login_csrf']) || !hash_equals($_SESSION['login_csrf'], $sent)) {
            http_response_code(403);
            $this->loginForm('Session expired - please try again.');
            return;
        }
        $error = AdminAuth::attemptLogin($pdo, (string) ($_POST['password'] ?? ''));
        if ($error === null) {
            header('Location: dashboard');
            exit;
        }
        $this->loginForm($error);
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        header('Location: login');
        exit;
    }

    /** GET /api/transactions */
    public function transactions(PDO $pdo): array
    {
        $rows = array_map(function ($tx) {
            $tx['amount_display'] = Transaction::formatZar((int) $tx['amount_cents']);
            return $tx;
        }, Transaction::recentAll($pdo, 50));
        return [200, $rows];
    }

    /** GET /api/audit-log */
    public function auditLog(PDO $pdo): array
    {
        return [200, AuditLog::all($pdo, 100)];
    }

    /** GET /api/admin/audit/verify - recomputes the hash chain end to end. */
    public function auditVerify(PDO $pdo): array
    {
        return [200, AuditLog::verifyChain($pdo)];
    }

    /** GET /api/admin/stats */
    public function stats(PDO $pdo): array
    {
        $s = Transaction::stats($pdo);
        $count = fn ($d) => $s['by_decision'][$d]['count'] ?? 0;
        $total = $count('approved') + $count('flagged') + $count('rejected');
        $blocked = ($s['by_decision']['rejected']['value_cents'] ?? 0) + ($s['by_decision']['flagged']['value_cents'] ?? 0);
        $paypal = $pdo->query("SELECT status, COUNT(*) c FROM paypal_orders GROUP BY status")->fetchAll(\PDO::FETCH_KEY_PAIR);

        return [200, [
            'total' => $total,
            'approved' => $count('approved'),
            'flagged' => $count('flagged'),
            'rejected' => $count('rejected'),
            'approval_rate' => $total ? round($count('approved') / $total * 100, 1) : null,
            'value_protected_display' => Transaction::formatZar($blocked),
            'avg_score' => $total ? round(array_sum(array_map(fn ($d) => $d['avg_score'] * $d['count'], $s['by_decision'])) / $total, 1) : null,
            'score_histogram' => $s['score_histogram'],
            'required_actions' => $s['required_actions'],
            'paypal_orders' => array_map('intval', $paypal),
        ]];
    }

    /** GET /api/admin/transactions/{id} - full factor breakdown for the dashboard drawer. */
    public function transactionDetail(PDO $pdo, int $id): array
    {
        $tx = Transaction::find($pdo, $id);
        if (!$tx) {
            return [404, ['error' => 'Transaction not found.']];
        }
        $detail = json_decode($tx['factors_json'] ?? '{}', true) ?: [];
        $stmt = $pdo->prepare('SELECT paypal_order_id, status, capture_id, mode, amount_value, currency FROM paypal_orders WHERE transaction_id = ?');
        $stmt->execute([$id]);
        return [200, [
            'id' => (int) $tx['id'],
            'decision' => $tx['decision'],
            'score' => (float) $tx['risk_score'],
            'amount_display' => Transaction::formatZar((int) $tx['amount_cents']),
            'decision_id' => $tx['decision_id'],
            'engine_version' => $tx['engine_version'],
            'required_action' => $tx['required_action'],
            'threshold' => $detail['threshold'] ?? null,
            'factors' => $detail['factors'] ?? [],
            'gates' => $detail['gates'] ?? [],
            'reason' => $tx['decision_reason'],
            'ai_analysis' => $tx['ai_analysis'] ? json_decode($tx['ai_analysis'], true) : null,
            'paypal' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null,
        ]];
    }

    /** POST /api/admin/transactions/{id}/explain - AI Risk Analyst (advisory only). */
    public function explain(PDO $pdo, int $id): array
    {
        $tx = Transaction::find($pdo, $id);
        if (!$tx) {
            return [404, ['error' => 'Transaction not found.']];
        }
        if ($tx['ai_analysis'] && empty($_GET['refresh'])) {
            return [200, json_decode($tx['ai_analysis'], true) + ['cached' => true]];
        }
        $analysis = (new RiskAnalystService())->analyse($tx);
        Transaction::setAiAnalysis($pdo, $id, $analysis);
        AuditLog::record($pdo, 'admin', 'ai_analysis_generated', 'transactions', $id, Http::ip(), ['source' => $analysis['source']]);
        return [200, $analysis + ['cached' => false]];
    }

    /** GET /api/dha-verifications?user_id= */
    public function dhaVerifications(PDO $pdo): array
    {
        $userId = (int) ($_GET['user_id'] ?? 0);
        if (!$userId) {
            return [422, ['error' => 'user_id query param is required.']];
        }
        AuditLog::record($pdo, 'admin', 'dha_history_viewed', 'users', $userId, Http::ip());
        return [200, DhaVerification::recentForUser($pdo, $userId, 20)];
    }

    /** POST /api/admin/retention/run */
    public function runRetention(PDO $pdo): array
    {
        return [200, ['status' => 'complete'] + (new RetentionService())->runAll($pdo)];
    }
}
