<?php

namespace App\Services;

use App\Services\VerificationAdapters\VerificationResult;

/**
 * Deliberately simple, explainable weighted-score engine for the MVP/pilot
 * stage. Every factor and its weight is visible in decision_reason, which
 * matters a lot when a bank's risk team asks "why was this approved?".
 *
 * Score is 0-100 (higher = more trustworthy). Swap thresholds as you get
 * real pilot data; keep the explainability property even if you later move
 * to a trained model - blend a model score in as one more weighted factor
 * rather than replacing the reasoning trail outright.
 */
class DecisionEngine
{
    private const APPROVE_THRESHOLD = 70;
    private const REVIEW_THRESHOLD = 40;

    public function decide(
        VerificationResult $dhaResult,
        array $livenessResult,
        array $behavioralResult,
        array $vaultMatchResult
    ): array {
        // Identity Vault Match is evaluated first and can short-circuit the
        // rest of scoring: if this capture does not match what was enrolled
        // for this user, that is a strong impersonation signal (someone
        // other than the enrolled user is presenting) and should not be
        // rescued by a good DHA/behavioral score.
        if (!$vaultMatchResult['is_first_enrollment'] && !$vaultMatchResult['matched']) {
            return [
                'decision' => 'rejected',
                'score' => 0,
                'reason' => 'Identity Vault Match failed: ' . $vaultMatchResult['reason'],
            ];
        }

        $score = 0;
        $reasons = [];

        if ($vaultMatchResult['is_first_enrollment']) {
            $reasons[] = 'Identity Vault: first enrollment, no prior template to match (+0)';
        } else {
            // Scale with the real match confidence now that VaultService
            // reports actual cosine similarity (not just a flat 1.0/0.0) for
            // real face-descriptor comparisons - a borderline-but-passing
            // match contributes less than a near-certain one.
            $confidence = $vaultMatchResult['confidence'] ?? 1.0;
            $vaultPoints = (int) round($confidence * 10);
            $score += $vaultPoints;
            $reasons[] = "Identity Vault Match confirmed, confidence {$confidence} (+{$vaultPoints})";
        }

        if ($dhaResult->matchResult === 'match') {
            $score += 50;
            $reasons[] = 'DHA identity match confirmed (+50)';
        } elseif ($dhaResult->matchResult === 'error') {
            // An operational bureau failure (out of credits, rate limited,
            // bad API key, network down) is NOT evidence the person failed
            // verification - it's evidence the check couldn't run at all.
            // Scored the same as a non-match for now (+0, conservative
            // default), but the reason text below keeps this distinguishable
            // in the audit trail/dashboard from a genuine non-match, since
            // conflating the two would misrepresent why a transaction was
            // rejected/flagged.
            $codeNote = $dhaResult->errorCode ? " ({$dhaResult->errorCode})" : '';
            $reasons[] = "DHA verification unavailable{$codeNote} - not evidence of fraud, treated as unverified (+0)";
        } else {
            $reasons[] = 'DHA identity match failed or inconclusive (+0)';
        }

        if ($dhaResult->photoMatchConfidence !== null) {
            $photoPoints = (int) round($dhaResult->photoMatchConfidence * 20);
            $score += $photoPoints;
            $reasons[] = "Photo match confidence {$dhaResult->photoMatchConfidence} (+{$photoPoints})";
        }

        if ($livenessResult['passed']) {
            $score += 20;
            $reasons[] = 'Liveness check passed (+20)';
        } else {
            $reasons[] = 'Liveness check failed: ' . $livenessResult['reason'] . ' (+0)';
        }

        $behavioralPenalty = $behavioralResult['risk_contribution'];
        $score -= $behavioralPenalty;
        if ($behavioralPenalty > 0) {
            $reasons[] = "Behavioral risk penalty -{$behavioralPenalty}: " . implode('; ', $behavioralResult['reasons']);
        }

        $score = max(0, min(100, $score));

        if ($score >= self::APPROVE_THRESHOLD) {
            $decision = 'approved';
        } elseif ($score >= self::REVIEW_THRESHOLD) {
            $decision = 'flagged';
        } else {
            $decision = 'rejected';
        }

        return [
            'decision' => $decision,
            'score' => $score,
            'reason' => implode(' | ', $reasons),
        ];
    }
}
