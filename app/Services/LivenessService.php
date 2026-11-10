<?php

namespace App\Services;

use App\Support\Config;
use App\Support\Vec;

/**
 * Server-side validation of a liveness attempt against the challenge the
 * SERVER issued. Replaces the old "client says it passed" model.
 *
 * What the server checks (every check is reported in `signals` so the
 * decision is explainable):
 *  - the exact random action sequence was performed, in order, each marked complete
 *  - timing is human-plausible (gaps between actions, total duration, enough frames)
 *  - the claimed capture duration fits inside the wall-clock time since the
 *    challenge was issued (can't claim 10s of video in 1s)
 *  - several face descriptors sampled across the session all belong to the SAME
 *    person (catches a face swap mid-session) ...
 *  - ... but aren't implausibly IDENTICAL (a static photo held to the camera
 *    produces near-zero descriptor variance while the user supposedly turns
 *    their head, blinks and opens their mouth)
 *
 * HONEST LIMITS: landmark tracking runs in the browser, so a determined
 * attacker who controls the client can still fabricate evidence. This closes
 * replay, trivially-forged and static-photo attacks; it is NOT certified
 * presentation-attack detection (ISO 30107-3). For regulated production use,
 * add server-side PAD on the video/frames or a certified vendor SDK, and
 * keep this layer as defence in depth.
 */
class LivenessService
{
    /**
     * @param array $challenge row from ChallengeService::consume()
     * @param array $evidence  client payload (mode, actions[], descriptors[], duration_ms, frames_analyzed)
     * @return array{passed:bool, mode:string, score:float, reason:string, signals:array, mean_descriptor:?array}
     */
    public function evaluate(array $challenge, array $evidence, ?int $nowTs = null): array
    {
        $mode = ($evidence['mode'] ?? 'real') === 'simulated' ? 'simulated' : 'real';

        if ($mode === 'simulated') {
            return $this->evaluateSimulated();
        }

        $cfg = Config::get('liveness');
        $nowTs ??= time();
        $signals = [];
        $add = static function (string $code, bool $ok, string $detail) use (&$signals): void {
            $signals[] = ['code' => $code, 'ok' => $ok, 'detail' => $detail];
        };

        // 1. Exact challenge sequence performed.
        $expected = $challenge['actions'];
        $reported = is_array($evidence['actions'] ?? null) ? $evidence['actions'] : [];
        $sequenceOk = count($reported) === count($expected);
        $times = [];
        if ($sequenceOk) {
            foreach ($expected as $i => $action) {
                $r = $reported[$i] ?? [];
                if (($r['action'] ?? null) !== $action || empty($r['completed'])) {
                    $sequenceOk = false;
                    break;
                }
                $times[] = (int) ($r['t_ms'] ?? -1);
            }
        }
        $add('challenge_sequence', $sequenceOk, $sequenceOk
            ? 'Performed the requested actions in order: ' . implode(' → ', $expected)
            : 'Requested action sequence (' . implode(' → ', $expected) . ') was not completed in order');

        // 2. Human-plausible timing.
        $timingOk = $sequenceOk;
        if ($sequenceOk) {
            for ($i = 0; $i < count($times); $i++) {
                if ($times[$i] < 0 || ($i > 0 && $times[$i] - $times[$i - 1] < $cfg['min_action_gap_ms'])) {
                    $timingOk = false;
                }
            }
        }
        $duration = (int) ($evidence['duration_ms'] ?? 0);
        $frames = (int) ($evidence['frames_analyzed'] ?? 0);
        $timingOk = $timingOk && $duration >= $cfg['min_total_ms'] && $duration <= $cfg['max_total_ms'] && $frames >= $cfg['min_frames'];
        $add('human_timing', $timingOk, "{$frames} frames over {$duration}ms" . ($timingOk ? '' : ' - outside plausible human bounds'));

        // 3. Claimed capture fits inside server wall-clock since issue.
        $elapsed = $nowTs - (int) $challenge['issued_at'];
        $clockOk = $elapsed >= 1 && ($elapsed * 1000 + 1500) >= $duration;
        $add('server_clock', $clockOk, "Challenge answered {$elapsed}s after issue");

        // 4/5. Descriptor consistency + variance.
        $samples = $evidence['descriptors'] ?? [];
        $valid = is_array($samples) ? array_values(array_filter($samples, [Vec::class, 'isDescriptor'])) : [];
        $meanDescriptor = null;
        $enough = count($valid) >= $cfg['min_descriptor_samples'];
        $add('descriptor_samples', $enough, count($valid) . ' valid face descriptors sampled (need ' . $cfg['min_descriptor_samples'] . ')');
        $samePerson = false;
        $notStatic = false;
        if ($enough) {
            $spread = Vec::spread($valid);
            $samePerson = $spread['max'] <= $cfg['max_sample_distance'];
            $notStatic = $spread['max'] >= $cfg['min_sample_variance'];
            $meanDescriptor = Vec::mean($valid);
            $add('same_person', $samePerson, sprintf('Max distance between samples %.3f (limit %.2f)', $spread['max'], $cfg['max_sample_distance']));
            $add('not_static', $notStatic, sprintf('Descriptor variation %.3f (static-image floor %.3f)', $spread['max'], $cfg['min_sample_variance']));
        }

        $passedCount = count(array_filter($signals, fn ($s) => $s['ok']));
        $passed = $sequenceOk && $timingOk && $clockOk && $enough && $samePerson && $notStatic;
        $failed = array_values(array_map(fn ($s) => $s['code'], array_filter($signals, fn ($s) => !$s['ok'])));

        return [
            'passed' => $passed,
            'mode' => 'real',
            'score' => round($passedCount / max(1, count($signals)), 2),
            'reason' => $passed
                ? 'Server-issued challenge completed; face consistent across session'
                : 'Liveness checks failed: ' . implode(', ', $failed),
            'signals' => $signals,
            'mean_descriptor' => $passed ? $meanDescriptor : null,
        ];
    }

    private function evaluateSimulated(): array
    {
        if (!Config::get('allow_simulated_liveness')) {
            return [
                'passed' => false,
                'mode' => 'simulated',
                'score' => 0.0,
                'reason' => 'Simulated liveness is disabled in this environment - a real camera capture is required',
                'signals' => [['code' => 'simulated_blocked', 'ok' => false, 'detail' => 'ALLOW_SIMULATED_LIVENESS is off']],
                'mean_descriptor' => null,
            ];
        }
        return [
            'passed' => true,
            'mode' => 'simulated',
            'score' => 0.3,
            'reason' => 'DEMO ONLY: simulated liveness accepted (no camera/face-landmark evidence - not anti-spoofing)',
            'signals' => [['code' => 'simulated_demo', 'ok' => true, 'detail' => 'Development/demo environment only']],
            'mean_descriptor' => null,
        ];
    }
}
