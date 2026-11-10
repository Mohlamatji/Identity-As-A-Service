<?php

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\WebhookService;
use App\Support\AuthContext;
use App\Support\Http;
use PDO;

class WebhookController
{
    /**
     * POST /api/webhook/notify/{id}
     * Delivers the decision to the integrator's WEBHOOK_URL, signed with
     * HMAC-SHA256 (see WebhookService). Falls back to a simulated delivery
     * when no webhook is configured.
     */
    public function notify(PDO $pdo, AuthContext $auth, int $transactionId): array
    {
        $tx = Transaction::find($pdo, $transactionId);
        if (!$tx) {
            return [404, ['error' => 'Transaction not found.']];
        }

        $approved = $tx['decision'] === 'approved';
        $action = $approved ? 'bank_api_call' : 'fraud_alert_review';
        $delivery = (new WebhookService())->deliver([
            'event' => 'decision.' . $tx['decision'],
            'decision_id' => $tx['decision_id'],
            'transaction_id' => (int) $tx['id'],
            'decision' => $tx['decision'],
            'required_action' => $tx['required_action'],
            'amount_cents' => (int) $tx['amount_cents'],
            'currency' => $tx['currency'],
            'score' => (float) $tx['risk_score'],
            'receipt' => $tx['receipt'],
            'created' => time(),
        ]);

        $amount = Transaction::formatZar((int) $tx['amount_cents']);
        $result = $delivery['simulated']
            ? ($approved
                ? "Simulated: notified {$tx['bank_partner_id']} to proceed with {$amount} transaction #{$tx['id']}."
                : "Simulated: fraud alert raised for {$amount} transaction #{$tx['id']}, routed to manual review.")
            : $delivery['detail'];

        AuditLog::record($pdo, 'client:' . $auth->clientId, $action, 'transactions', $transactionId, Http::ip(), ['delivered' => $delivery['delivered'], 'simulated' => $delivery['simulated']]);
        return [200, ['action' => $action, 'result' => $result, 'delivery' => $delivery]];
    }
}
