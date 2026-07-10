<?php

declare(strict_types=1);

namespace Ptad\Api;

/**
 * ============================================================
 * PTAD — Rate Limiter
 * ============================================================
 * Simple, file-based, per-IP sliding-window rate limiter. No
 * database schema changes needed, and no dependency on APCu or
 * Redis (not guaranteed available on shared cPanel hosting) —
 * just plain files in a writable directory, protected with
 * flock() for safe concurrent access.
 *
 * DESIGN: one small JSON file per IP (hashed to avoid weird
 * characters in filenames), storing a list of recent request
 * timestamps. On each check: prune timestamps older than the
 * window, count what's left, reject if at/over the limit,
 * otherwise record this request and allow it.
 *
 * This is intentionally simple — good enough to blunt casual
 * abuse/scraping of a free public API, not a defense against a
 * determined distributed attack (which would need infrastructure-
 * level protection beyond what plain PHP on shared hosting can
 * provide anyway).
 * ============================================================
 */
final class RateLimiter
{
    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? __DIR__ . '/../../storage/rate_limit';

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }
    }

    /**
     * @return array{allowed: bool, remaining: int, retry_after_seconds: int}
     */
    public function check(string $ip, int $maxRequests = 60, int $windowSeconds = 60): array
    {
        $file = $this->storageDir . '/' . hash('sha256', $ip) . '.json';
        $now = time();

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            // Fail OPEN on a filesystem error — never let a rate-limiter
            // bug take down the whole public API; availability of the
            // real data matters more than strict enforcement here.
            return ['allowed' => true, 'remaining' => $maxRequests, 'retry_after_seconds' => 0];
        }

        flock($handle, LOCK_EX);

        $contents = stream_get_contents($handle);
        $timestamps = $contents !== false && $contents !== '' ? (json_decode($contents, true) ?? []) : [];

        // Prune anything outside the current window.
        $timestamps = array_values(array_filter($timestamps, fn($t) => $t > $now - $windowSeconds));

        $allowed = count($timestamps) < $maxRequests;

        if ($allowed) {
            $timestamps[] = $now;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($timestamps));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $oldestInWindow = $timestamps[0] ?? $now;
        $retryAfter = $allowed ? 0 : max(0, ($oldestInWindow + $windowSeconds) - $now);

        return [
            'allowed'              => $allowed,
            'remaining'            => max(0, $maxRequests - count($timestamps)),
            'retry_after_seconds'  => $retryAfter,
        ];
    }

    /**
     * Best-effort real client IP resolution. Trusts X-Forwarded-For
     * only if explicitly needed behind a known proxy — for a simple
     * cPanel deployment (no load balancer), REMOTE_ADDR is correct
     * and cannot be spoofed by the client, unlike X-Forwarded-For.
     */
    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
