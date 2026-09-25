<?php

namespace App\Controllers;

use App\Models\Transaction;
use App\Models\AuditLog;
use PDO;

class WebhookController
{
    /**
     * Fires the appropriate outbound call based on a transaction's decision.
     * Stubbed here as a log entry - wire real bank partner endpoints in once
     * you have pilot integration details for each partner.
     */
    public function notify(PDO $pdo, int $transactionId): void
    {
        $tx = Transaction::find($pdo, $transactionId);
        if (!$tx) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found.']);
            return;
        }

        if ($tx['decision'] === 'approved') {
            $action = 'bank_api_call';
            $result = $this->callBankApi($tx);
        } else {
            $action = 'fraud_alert_review';
            $result = $this->raiseFraudAlert($tx);
        }

        AuditLog::record($pdo, 'system', $action, 'transactions', $transactionId, $_SERVER['REMOTE_ADDR'] ?? null);

        header('Content-Type: application/json');
        echo json_encode(['action' => $action, 'result' => $result]);
    }

    private function callBankApi(array $tx): string
    {
        // Placeholder - replace with the specific bank partner's API client.
        $amount = Transaction::formatZar((int) $tx['amount_cents']);
        return "Simulated: notified {$tx['bank_partner_id']} to proceed with {$amount} transaction #{$tx['id']}.";
    }

    private function raiseFraudAlert(array $tx): string
    {
        // Placeholder - replace with real alerting (email/SMS/ops dashboard).
        $amount = Transaction::formatZar((int) $tx['amount_cents']);
        return "Simulated: fraud alert raised for {$amount} transaction #{$tx['id']}, routed to manual review.";
    }
}
