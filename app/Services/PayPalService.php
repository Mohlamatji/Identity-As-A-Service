<?php

namespace App\Services;

use App\Support\Config;

/**
 * PayPal Orders v2 client (server-side, as PayPal recommends - never call
 * the Orders API from the browser).
 *
 * Modes (PAYPAL_MODE):
 *   mock     no credentials needed; simulates PayPal locally so the
 *            checkout demo always runs end to end. Clearly labelled.
 *   sandbox  api-m.sandbox.paypal.com with your sandbox REST app creds.
 *   live     api-m.paypal.com.
 *
 * Endpoints used (all documented in PayPal's REST reference):
 *   POST /v1/oauth2/token                          client-credentials token
 *   POST /v2/checkout/orders                       create order (intent CAPTURE)
 *   POST /v2/checkout/orders/{id}/capture          capture approved order
 *   POST /v1/notifications/verify-webhook-signature  verify inbound webhooks
 * Idempotency: PayPal-Request-Id header on create + capture.
 *
 * $transport is injectable ( fn(method, url, headers[], body|null): [status, array] )
 * so the request construction is unit-tested without network access.
 */
class PayPalService
{
    /** @var callable|null */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function mode(): string
    {
        $m = Config::get('paypal.mode', 'mock');
        return in_array($m, ['mock', 'sandbox', 'live'], true) ? $m : 'mock';
    }

    public function isMock(): bool
    {
        return $this->mode() === 'mock';
    }

    public function currency(): string
    {
        return strtoupper((string) Config::get('paypal.currency', 'USD'));
    }

    /** Converts ZAR cents to PayPal's decimal string in the configured currency. */
    public function amountValue(int $zarCents): string
    {
        $zar = $zarCents / 100;
        $value = $this->currency() === 'ZAR' ? $zar : $zar / max(0.0001, (float) Config::get('paypal.zar_per_usd', 18.5));
        return number_format(max(0.01, $value), 2, '.', '');
    }

    /**
     * @param array{reference_id:string, decision_id:string, description:string, amount_cents:int, return_url:string, cancel_url:string, request_id:string} $o
     * @return array{id:string, status:string, approve_url:?string, mode:string, amount_value:string, currency:string}
     */
    public function createOrder(array $o): array
    {
        $value = $this->amountValue($o['amount_cents']);
        $currency = $this->currency();

        if ($this->isMock()) {
            $id = 'MOCK' . strtoupper(bin2hex(random_bytes(8)));
            return [
                'id' => $id, 'status' => 'PAYER_ACTION_REQUIRED', 'mode' => 'mock',
                'approve_url' => ($o['mock_url'] ?? $o['return_url']) . '?token=' . $id,
                'amount_value' => $value, 'currency' => $currency,
            ];
        }

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => substr($o['reference_id'], 0, 120),
                'custom_id' => substr($o['decision_id'], 0, 127),   // ties the PayPal order back to our signed decision
                'description' => substr($o['description'], 0, 127),
                'amount' => ['currency_code' => $currency, 'value' => $value],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => 'Identity Vault Checkout',
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $o['return_url'],
                'cancel_url' => $o['cancel_url'],
            ]]],
        ];
        [$status, $res] = $this->call('POST', '/v2/checkout/orders', $body, ['PayPal-Request-Id: ' . $o['request_id']]);
        if ($status >= 300 || empty($res['id'])) {
            throw new \RuntimeException('PayPal create order failed (HTTP ' . $status . '): ' . ($res['message'] ?? 'unknown error'));
        }

        $approve = null;
        foreach ($res['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $approve = $link['href'];
                break;
            }
        }
        return ['id' => $res['id'], 'status' => $res['status'] ?? 'CREATED', 'approve_url' => $approve, 'mode' => $this->mode(), 'amount_value' => $value, 'currency' => $currency];
    }

    /** @return array{status:string, capture_id:?string} */
    public function captureOrder(string $orderId, string $requestId): array
    {
        if ($this->isMock() || str_starts_with($orderId, 'MOCK')) {
            return ['status' => 'COMPLETED', 'capture_id' => 'MOCKCAP' . strtoupper(bin2hex(random_bytes(6)))];
        }
        [$status, $res] = $this->call('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', new \stdClass(), ['PayPal-Request-Id: ' . $requestId]);
        if ($status >= 300) {
            throw new \RuntimeException('PayPal capture failed (HTTP ' . $status . '): ' . ($res['message'] ?? 'unknown error'));
        }
        return [
            'status' => $res['status'] ?? 'UNKNOWN',
            'capture_id' => $res['purchase_units'][0]['payments']['captures'][0]['id'] ?? null,
        ];
    }

    /** Verifies an inbound PayPal webhook via PayPal's own verification endpoint. */
    public function verifyWebhook(array $headers, array $event): bool
    {
        $webhookId = (string) Config::get('paypal.webhook_id', '');
        if ($this->isMock() || $webhookId === '') {
            return false; // never trust an unverifiable webhook
        }
        $h = array_change_key_case($headers, CASE_LOWER);
        [$status, $res] = $this->call('POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $h['paypal-auth-algo'] ?? '',
            'cert_url' => $h['paypal-cert-url'] ?? '',
            'transmission_id' => $h['paypal-transmission-id'] ?? '',
            'transmission_sig' => $h['paypal-transmission-sig'] ?? '',
            'transmission_time' => $h['paypal-transmission-time'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);
        return $status === 200 && ($res['verification_status'] ?? '') === 'SUCCESS';
    }

    // --- internals ---------------------------------------------------

    private function baseUrl(): string
    {
        return $this->mode() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function accessToken(): string
    {
        $cacheFile = __DIR__ . '/../../storage/paypal_token_' . $this->mode() . '.json';
        if (is_file($cacheFile)) {
            $c = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($c) && ($c['exp'] ?? 0) > time() + 60) {
                return $c['token'];
            }
        }
        $id = (string) Config::get('paypal.client_id');
        $secret = (string) Config::get('paypal.client_secret');
        if ($id === '' || $secret === '') {
            throw new \RuntimeException('PAYPAL_CLIENT_ID / PAYPAL_CLIENT_SECRET are not configured.');
        }
        [$status, $res] = $this->send('POST', $this->baseUrl() . '/v1/oauth2/token', [
            'Authorization: Basic ' . base64_encode("$id:$secret"),
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], 'grant_type=client_credentials');
        if ($status !== 200 || empty($res['access_token'])) {
            throw new \RuntimeException('PayPal authentication failed (HTTP ' . $status . ').');
        }
        @file_put_contents($cacheFile, json_encode(['token' => $res['access_token'], 'exp' => time() + (int) ($res['expires_in'] ?? 300)]), LOCK_EX);
        return $res['access_token'];
    }

    private function call(string $method, string $path, array|object $body, array $extraHeaders = []): array
    {
        return $this->send($method, $this->baseUrl() . $path, array_merge([
            'Authorization: Bearer ' . $this->accessToken(),
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders), json_encode($body));
    }

    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport) {
            return ($this->transport)($method, $url, $headers, $body);
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The PHP curl extension is required for PayPal sandbox/live modes.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('Could not reach PayPal.');
        }
        return [$status, json_decode($raw, true) ?: []];
    }
}
