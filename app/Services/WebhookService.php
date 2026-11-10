<?php

namespace App\Services;

use App\Support\Config;

/**
 * Outbound decision webhooks, signed Stripe-style so the receiver can prove
 * authenticity and reject replays:
 *
 *   X-IV-Signature: t=<unix ts>,v1=<hex HMAC-SHA256 of "<ts>.<raw body>">
 *
 * Receivers: recompute the HMAC with WEBHOOK_SECRET over "<t>.<raw body>",
 * compare in constant time, and reject if |now - t| > 5 minutes.
 * With no WEBHOOK_URL configured, delivery is simulated (as before).
 */
class WebhookService
{
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $secret, string $header, string $body, int $toleranceSeconds = 300): bool
    {
        if (!preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m)) {
            return false;
        }
        if (abs(time() - (int) $m[1]) > $toleranceSeconds) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $m[1] . '.' . $body, $secret), $m[2]);
    }

    /** @return array{delivered:bool, simulated:bool, detail:string} */
    public function deliver(array $payload): array
    {
        $url = (string) Config::get('webhook.url');
        $secret = (string) Config::get('webhook.secret');
        if ($url === '' || $secret === '') {
            return ['delivered' => false, 'simulated' => true, 'detail' => 'No WEBHOOK_URL/WEBHOOK_SECRET configured - delivery simulated'];
        }
        if (!preg_match('#^https://#i', $url) && Config::isProduction()) {
            return ['delivered' => false, 'simulated' => false, 'detail' => 'Webhook URL must be https in production'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-IV-Signature: ' . self::sign($secret, time(), $body)],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['delivered' => $status >= 200 && $status < 300, 'simulated' => false, 'detail' => "Receiver responded HTTP {$status}"];
    }
}
