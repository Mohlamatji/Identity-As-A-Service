<?php

namespace App\Support;

/** Small vector helpers for 128-d face descriptors. */
final class Vec
{
    public const DESCRIPTOR_DIM = 128;

    /** True if $v is a list of exactly 128 finite numbers in a sane range. */
    public static function isDescriptor(mixed $v): bool
    {
        if (!is_array($v) || count($v) !== self::DESCRIPTOR_DIM) {
            return false;
        }
        foreach ($v as $x) {
            if (!is_int($x) && !is_float($x)) {
                return false;
            }
            if (!is_finite((float) $x) || abs($x) > 5.0) {
                return false;
            }
        }
        return true;
    }

    public static function distance(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $d = (float) $a[$i] - (float) $b[$i];
            $sum += $d * $d;
        }
        return sqrt($sum);
    }

    public static function mean(array $vectors): array
    {
        $n = count($vectors);
        $out = array_fill(0, self::DESCRIPTOR_DIM, 0.0);
        foreach ($vectors as $v) {
            foreach ($v as $i => $x) {
                $out[$i] += (float) $x / $n;
            }
        }
        return $out;
    }

    /** Largest and smallest pairwise distance across a set of vectors. */
    public static function spread(array $vectors): array
    {
        $max = 0.0;
        $min = INF;
        $n = count($vectors);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $d = self::distance($vectors[$i], $vectors[$j]);
                $max = max($max, $d);
                $min = min($min, $d);
            }
        }
        return ['max' => $max, 'min' => is_infinite($min) ? 0.0 : $min];
    }
}
