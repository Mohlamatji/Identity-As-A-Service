<?php

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\PayPalService;
use App\Services\ReceiptService;
use App\Services\TransactionService;
use App\Support\AuthContext;
use App\Support\Config;
use App\Support\Http;
use PDO;

/**
 * PayPal checkout gated by Identity Vault.
 *
 *   1. POST /api/paypal/orders          run the full verification + decision
 *                                       pipeline. PayPal is called ONLY if the
 *                                       decision is "approved" - a declined
 *                                       payment never touches the payment rail.
 *   2. buyer approves at PayPal         (redirect to approve_url)
 *   3. POST /api/paypal/orders/{id}/capture   re-checks the signed receipt is
 *                                       still valid + approved, then captures.
 *   4. POST /api/paypal/webhook         reconciles PayPal's async events
 *                                       (signature-verified by PayPal).
 *
 * Honest scope note: this gate is only as strong as your integration - a
 * merchant that can call PayPal directly can bypass it. In production,
 * route capture through this service so Identity Vault is the only caller.
 */
class PayPalController
{
    public function __construct(
        private readonly PayPalService $paypal = new PayPalService(),
        private readonly TransactionService $transactions = new TransactionService(),
        private readonly ReceiptService $receipts = new ReceiptService()
    ) {
    }

    /** GET /api/paypal/config - no secrets, just what the UI needs. */
    public function config(): array
    {
        return [200, [
            'mode' => $this->paypal->mode(),
            'currency' => $this->paypal->currency(),
            'zar_per_usd' => $this->paypal->currency() === 'ZAR' ? null : Config::get('paypal.zar_per_usd'),
        ]];
    }

    /** POST /api/paypal/orders */
    public function createOrder(PDO $pdo, AuthContext $auth): array
    {
        $input = Http::input();
        [$status, $body] = $this->transactions->approve($pdo, $auth, $input);
        if ($status !== 200) {
            return [$status, $body];
        }

        $body['paypal'] = null;
        if ($body['status'] !== 'approved') {
            $body['paypal_note'] = 'PayPal was not called: payment was not approved by Identity Vault.';
            return [200, $body];
        }

        try {
            $baseUrl = $this->appBaseUrl();
            $order = $this->paypal->createOrder([
                'reference_id' => 'tx_' . $body['transaction_id'],
                'decision_id' => $body['decision_id'],
                'description' => 'Identity Vault verified payment',
                'amount_cents' => (int) $input['amount_cents'],
                'return_url' => $baseUrl . 'checkout',
                'mock_url' => $baseUrl . 'checkout/mock-paypal',
                'cancel_url' => $baseUrl . 'checkout?cancelled=1',
                'request_id' => 'iv-create-' . $body['decision_id'],
            ]);
        } catch (\Throwable $e) {
            AuditLog::record($pdo, 'client:' . $auth->clientId, 'paypal_order_failed', 'transactions', $body['transaction_id'], Http::ip());
            $body['paypal_error'] = 'Payment approved by Identity Vault, but PayPal order creation failed: ' . $e->getMessage();
            return [502, $body];
        }

        $now = time();
        $pdo->prepare(
            'INSERT INTO paypal_orders (transaction_id, paypal_order_id, decision_id, mode, status, amount_value, currency, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$body['transaction_id'], $order['id'], $body['decision_id'], $order['mode'], $order['status'], $order['amount_value'], $order['currency'], $now, $now]);
        AuditLog::record($pdo, 'client:' . $auth->clientId, 'paypal_order_created', 'transactions', $body['transaction_id'], Http::ip(), ['mode' => $order['mode'], 'order' => $order['id']]);

        $body['paypal'] = [
            'order_id' => $order['id'], 'status' => $order['status'], 'approve_url' => $order['approve_url'],
            'mode' => $order['mode'], 'amount' => $order['amount_value'], 'currency' => $order['currency'],
        ];
        return [200, $body];
    }

    /** POST /api/paypal/orders/{orderId}/capture */
    public function capture(PDO $pdo, AuthContext $auth, string $orderId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM paypal_orders WHERE paypal_order_id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return [404, ['status' => 'error', 'message' => 'Unknown PayPal order. Create it via POST /api/paypal/orders.']];
        }
        if ($order['status'] === 'COMPLETED') {
            return [200, ['status' => 'COMPLETED', 'capture_id' => $order['capture_id'], 'idempotent' => true]];
        }

        $tx = Transaction::find($pdo, (int) $order['transaction_id']);
        $receipt = $tx ? $this->receipts->verify((string) $tx['receipt']) : null;
        if (!$tx || $tx['decision'] !== 'approved') {
            return [403, ['status' => 'error', 'message' => 'Capture refused: the linked decision is not "approved".']];
        }
        if (!$receipt || ($receipt['dec'] ?? '') !== 'approved' || ($receipt['did'] ?? '') !== $order['decision_id']) {
            AuditLog::record($pdo, 'client:' . $auth->clientId, 'paypal_capture_blocked_stale_receipt', 'transactions', (int) $tx['id'], Http::ip());
            return [403, ['status' => 'error', 'message' => 'Capture refused: the signed approval receipt is expired or invalid. Re-run verification.']];
        }

        try {
            $cap = $this->paypal->captureOrder($orderId, 'iv-capture-' . $order['decision_id']);
        } catch (\Throwable $e) {
            return [502, ['status' => 'error', 'message' => $e->getMessage()]];
        }

        $pdo->prepare('UPDATE paypal_orders SET status = ?, capture_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$cap['status'], $cap['capture_id'], time(), $order['id']]);
        AuditLog::record($pdo, 'client:' . $auth->clientId, 'paypal_captured', 'transactions', (int) $tx['id'], Http::ip(), ['status' => $cap['status'], 'mode' => $order['mode']]);

        return [200, ['status' => $cap['status'], 'capture_id' => $cap['capture_id'], 'mode' => $order['mode'], 'transaction_id' => (int) $tx['id'], 'decision_id' => $order['decision_id']]];
    }

    /** POST /api/paypal/webhook - public endpoint; authenticity proven by PayPal's own signature check. */
    public function webhook(PDO $pdo): array
    {
        $event = json_decode(Http::rawBody(), true);
        if (!is_array($event)) {
            return [400, ['status' => 'error', 'message' => 'Invalid JSON.']];
        }
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_PAYPAL_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (!$this->paypal->verifyWebhook($headers, $event)) {
            return [401, ['status' => 'error', 'message' => 'Webhook signature could not be verified.']];
        }

        $type = (string) ($event['event_type'] ?? '');
        $resource = $event['resource'] ?? [];
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? ($resource['id'] ?? null);
        $map = ['PAYMENT.CAPTURE.COMPLETED' => 'COMPLETED', 'PAYMENT.CAPTURE.DENIED' => 'DENIED', 'PAYMENT.CAPTURE.REFUNDED' => 'REFUNDED', 'CHECKOUT.ORDER.APPROVED' => 'APPROVED'];
        if ($orderId && isset($map[$type])) {
            $pdo->prepare('UPDATE paypal_orders SET status = ?, updated_at = ? WHERE paypal_order_id = ?')->execute([$map[$type], time(), $orderId]);
            AuditLog::record($pdo, 'paypal', 'paypal_webhook_' . strtolower($type), 'paypal_orders', null, Http::ip(), ['order' => $orderId]);
        }
        return [200, ['status' => 'ok']];
    }

    /** POST /api/receipt/verify - lets a bank/PayPal-side service check a decision receipt. */
    public function verifyReceipt(): array
    {
        $payload = $this->receipts->verify((string) (Http::input()['receipt'] ?? ''));
        return [200, $payload ? ['valid' => true, 'receipt' => $payload] : ['valid' => false]];
    }

    private function appBaseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (Config::get('trust_proxy_headers') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
        return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
    }
}
