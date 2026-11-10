<?php

namespace App\Services;

use App\Support\Config;
use App\Support\Tokens;

/**
 * Signed decision receipts. Every approve/decline returns a compact,
 * HMAC-signed receipt a bank (or PayPal-side capture step) can verify
 * without trusting the network path: it states exactly what was decided,
 * for which user/amount/payee, and when it expires. Capture endpoints
 * refuse to proceed without a valid, unexpired "approved" receipt.
 */
class ReceiptService
{
    public function issue(array $decision, int $transactionId, int $userId, int $amountCents, string $currency, ?string $payee): string
    {
        return Tokens::sign('receipt', [
            'did' => $decision['decision_id'],
            'tx' => $transactionId,
            'uid' => $userId,
            'dec' => $decision['decision'],
            'score' => $decision['score'],
            'amt' => $amountCents,
            'cur' => $currency,
            'payee' => $payee,
            'iat' => time(),
            'eng' => $decision['engine_version'],
        ], (int) Config::get('tokens.receipt_ttl', 900));
    }

    /** @return array|null the verified payload, or null if forged/expired */
    public function verify(string $receipt): ?array
    {
        return Tokens::verify('receipt', $receipt);
    }
}
