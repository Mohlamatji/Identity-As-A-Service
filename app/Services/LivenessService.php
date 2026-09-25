<?php

namespace App\Services;

/**
 * Liveness check. Client-side (capture.js) runs real face-landmark analysis
 * via face-api.js in the browser - tracking eye-aspect-ratio dips for blink
 * detection and face-box lateral movement for head-turn detection - and
 * reports the measured results here as 'mode' => 'real'.
 *
 * If the browser has no camera, or face-api.js/its models fail to load
 * (offline, restricted network, headless testing), capture.js falls back to
 * a timing-based signal reported as 'mode' => 'simulated'. That fallback
 * keeps curl-based testing and the README examples working without a real
 * camera, but should not be trusted as anti-spoofing evidence - it is
 * explicitly weaker and is flagged as such in the result.
 */
class LivenessService
{
    public function evaluate(array $captureMeta): array
    {
        $mode = $captureMeta['mode'] ?? 'simulated';

        if ($mode === 'real') {
            return $this->evaluateReal($captureMeta);
        }

        return $this->evaluateSimulated($captureMeta);
    }

    private function evaluateReal(array $captureMeta): array
    {
        $blinkDetected = (bool) ($captureMeta['blink_detected'] ?? false);
        $headTurnDetected = (bool) ($captureMeta['head_turn_detected'] ?? false);
        $framesAnalyzed = (int) ($captureMeta['frames_analyzed'] ?? 0);
        $durationMs = (int) ($captureMeta['duration_ms'] ?? 0);

        // Require both signals plus a sane analysis window - too few frames
        // means face-api.js couldn't track reliably (bad lighting, no face),
        // and an absurdly short/long duration suggests a replayed clip
        // rather than a live capture.
        $passed = $blinkDetected
            && $headTurnDetected
            && $framesAnalyzed >= 15
            && $durationMs >= 800
            && $durationMs <= 20000;

        $missing = [];
        if (!$blinkDetected) {
            $missing[] = 'blink';
        }
        if (!$headTurnDetected) {
            $missing[] = 'head turn';
        }

        return [
            'passed' => $passed,
            'mode' => 'real',
            'reason' => $passed
                ? 'Blink and head-turn detected via face landmark tracking'
                : ($missing
                    ? 'Missing signal(s): ' . implode(', ', $missing)
                    : 'Insufficient frames analyzed or timing out of bounds'),
        ];
    }

    private function evaluateSimulated(array $captureMeta): array
    {
        $gestureCompleted = (bool) ($captureMeta['gesture_completed'] ?? false);
        $frameCount = (int) ($captureMeta['frame_count'] ?? 0);
        $captureDurationMs = (int) ($captureMeta['duration_ms'] ?? 0);

        $passed = $gestureCompleted
            && $frameCount >= 8
            && $captureDurationMs >= 800
            && $captureDurationMs <= 15000;

        return [
            'passed' => $passed,
            'mode' => 'simulated',
            'reason' => $passed
                ? 'Timing-based fallback passed (no camera/face-landmark data available - not real anti-spoofing evidence)'
                : 'Gesture challenge missing, too few frames, or timing out of bounds',
        ];
    }
}
