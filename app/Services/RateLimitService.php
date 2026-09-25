<?php

namespace App\Services;

use PDO;

/**
 * Simple sliding-window rate limiter backed by the rate_limit_hits table.
 * No external dependency (Redis, etc.) needed for this scale - fine for a
 * pilot, but a single shared DB becomes the bottleneck under real load, so
 * revisit if this ever needs to handle serious traffic.
 */
class RateLimitService
{
    /**
     * Returns true if the request is allowed (and records the hit), false
     * if the caller has exceeded $maxRequests within the last $windowSeconds
     * for this $bucketKey. Also prunes old hits for this bucket so the table
     * doesn't grow unbounded.
     */
    public function allow(PDO $pdo, string $bucketKey, int $maxRequests, int $windowSeconds): bool
    {
        $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);

        $prune = $pdo->prepare('DELETE FROM rate_limit_hits WHERE bucket_key = ? AND created_at < ?');
        $prune->execute([$bucketKey, $cutoff]);

        $count = $pdo->prepare('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket_key = ? AND created_at >= ?');
        $count->execute([$bucketKey, $cutoff]);
        $currentCount = (int) $count->fetchColumn();

        if ($currentCount >= $maxRequests) {
            return false;
        }

        $insert = $pdo->prepare('INSERT INTO rate_limit_hits (bucket_key) VALUES (?)');
        $insert->execute([$bucketKey]);

        return true;
    }

    public static function bucketKey(string $ip, string $endpoint): string
    {
        return $ip . ':' . $endpoint;
    }
}
