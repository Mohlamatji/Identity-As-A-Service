<?php

namespace App\Services;

use App\Services\VerificationAdapters\VerificationResult;
use App\Support\Config;

/**
 * Explainable fraud decision engine (v2).
 *
 * Score 0-100 (higher = more trustworthy) built from named factors, plus a
 * set of HARD GATES that no amount of score can override. Every factor and
 * gate is returned as structured data (`factors`) as well as a flat
 * `reason` string, so a bank's risk team can answer "why was this
 * approved/declined?" - and so the AI Risk Analyst has facts, not prose, to
 * reason over. The engine itself stays deterministic: AI explains
 * decisions, it never makes them.
 *
 * Fixes vs v1:
 *  - Liveness was only worth +20 points, so a payment with FAILED liveness
 *    could still score 80 (DHA 50 + photo 20 + vault 10) and be approved.
 *    Liveness is now a gate.
 *  - A flat 70 threshold for every amount. Approval thresholds now scale
 *    with value (config 'decision.amount_tiers').
 *  - DHA no_match only lost 50 points; a bureau "this is not that person"
 *    is now a gate. A bureau outage (error) can never auto-approve.
 *
 * Decisions: approved | flagged | rejected. A flagged decision carries a
 * `required_action` (step_up | manual_review) telling the integrator what
 * to do next, instead of an undifferentiated "flagged".
 */
class DecisionEngine
{
    public function decide(
        VerificationResult $dha,
        array $liveness,
        array $behavioral,
        array $vault,
        int $amountCents = 0,
        ?array $idCheck = null
    ): array {
        $cfg = Config::get('decision');
        $factors = [];
        $gates = [];
        $score = 0;

        $factor = static function (string $code, string $label, int $points, int $max, string $detail) use (&$factors, &$score): void {
            $factors[] = ['code' => $code, 'label' => $label, 'kind' => $points >= 0 ? 'positive' : 'negative', 'points' => $points, 'max' => $max, 'detail' => $detail];
            $score += $points;
        };
        $gate = static function (string $code, string $label, string $detail) use (&$gates): void {
            $gates[] = ['code' => $code, 'label' => $label, 'detail' => $detail];
        };

        // ---- Hard gates ------------------------------------------------
        if ($idCheck !== null && !$idCheck['valid']) {
            $gate('id_invalid', 'ID number invalid', $idCheck['reason']);
        }
        if (!$vault['is_first_enrollment'] && !$vault['matched']) {
            $gate('vault_mismatch', 'Identity Vault mismatch', $vault['reason']);
        }
        if (!$liveness['passed']) {
            $gate('liveness_failed', 'Liveness failed', $liveness['reason']);
        }
        if ($dha->matchResult === 'no_match') {
            $gate('dha_no_match', 'DHA no match', 'Bureau reports the identity details do not match Home Affairs records');
        }

        // ---- Scored factors -------------------------------------------
        if ($vault['is_first_enrollment']) {
            $factor('vault', 'Identity Vault', 0, 10, 'First enrollment - no prior template to match against');
        } elseif ($vault['matched']) {
            $conf = (float) ($vault['confidence'] ?? 1.0);
            $factor('vault', 'Identity Vault match', (int) round($conf * 10), 10, "Face match confidence {$conf}" . (isset($vault['distance']) ? " (embedding distance {$vault['distance']})" : ''));
        }

        if ($dha->matchResult === 'match') {
            $factor('dha', 'DHA identity match', 50, 50, 'Bureau confirms identity against Home Affairs records');
        } elseif ($dha->matchResult === 'error') {
            $code = $dha->errorCode ? " ({$dha->errorCode})" : '';
            $factor('dha', 'DHA unavailable', 0, 50, "Bureau check could not run{$code} - not evidence of fraud, but unverified");
        } else {
            $factor('dha', 'DHA not matched', 0, 50, 'Bureau result negative or inconclusive');
        }

        if ($dha->photoMatchConfidence !== null) {
            $pts = (int) round($dha->photoMatchConfidence * 20);
            $factor('photo', 'Selfie vs DHA photo', $pts, 20, "Photo match confidence {$dha->photoMatchConfidence}");
        }

        if ($liveness['passed']) {
            // Real server-validated liveness earns full marks; the demo-only
            // simulated mode earns less so it can't clear high-value tiers.
            $real = ($liveness['mode'] ?? 'real') === 'real';
            $factor('liveness', 'Liveness', $real ? 20 : 8, 20, $real ? 'Server-issued challenge completed' : 'DEMO simulated liveness (reduced credit)');
        }

        foreach ($behavioral['factors'] ?? [] as $b) {
            $factor('behavior.' . $b['code'], $b['label'], -$b['points'], $b['points'], $b['detail']);
        }

        $score = max(0, min(100, $score));

        // ---- Threshold for this amount --------------------------------
        $threshold = (int) $cfg['approve_threshold'];
        foreach ($cfg['amount_tiers'] as $minAmount => $needed) {
            if ($amountCents >= $minAmount) {
                $threshold = max($threshold, (int) $needed);
            }
        }

        // ---- Verdict --------------------------------------------------
        $requiredAction = null;
        if ($gates) {
            $decision = 'rejected';
            $requiredAction = 'retry_verification';
            $score = 0;
        } elseif ($dha->matchResult === 'error') {
            // Fail closed: a bureau outage can never auto-approve.
            $decision = 'flagged';
            $requiredAction = 'manual_review';
        } elseif ($score >= $threshold) {
            $decision = 'approved';
        } elseif ($score >= (int) $cfg['approve_threshold']) {
            $decision = 'flagged';
            $requiredAction = 'step_up'; // good enough for a small payment, not for this amount
        } elseif ($score >= (int) $cfg['review_threshold']) {
            $decision = 'flagged';
            $requiredAction = 'manual_review';
        } else {
            $decision = 'rejected';
        }

        $reasonParts = array_map(fn ($g) => 'GATE ' . $g['label'] . ': ' . $g['detail'], $gates);
        foreach ($factors as $f) {
            $reasonParts[] = sprintf('%s %s%d: %s', $f['label'], $f['points'] >= 0 ? '+' : '', $f['points'], $f['detail']);
        }
        $reasonParts[] = "Approval threshold for this amount: {$threshold}";

        return [
            'decision' => $decision,
            'score' => $score,
            'reason' => implode(' | ', $reasonParts),
            'decision_id' => 'dec_' . bin2hex(random_bytes(10)),
            'engine_version' => $cfg['engine_version'],
            'required_action' => $requiredAction,
            'threshold' => $threshold,
            'factors' => $factors,
            'gates' => $gates,
        ];
    }
}
