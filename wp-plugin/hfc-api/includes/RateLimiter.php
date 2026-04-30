<?php
namespace HFC\Api;

/**
 * IP-bucketed sliding-window rate limiter backed by WP transients.
 * Each bucket key = "hfc_rl_{namespace}_{md5(ip)}". Stored value = [hits, windowStart].
 */
class RateLimiter
{
    public function __construct(
        private string $namespace,
        private int $maxHits,
        private int $windowSeconds
    ) {}

    public function allow(string $ip): bool
    {
        $key = $this->key($ip);
        $now = $this->now();
        $state = get_transient($key);

        if (!is_array($state)) {
            set_transient($key, [1, $now], $this->windowSeconds);
            return true;
        }

        [$hits, $start] = $state;
        if ($now - $start >= $this->windowSeconds) {
            set_transient($key, [1, $now], $this->windowSeconds);
            return true;
        }

        if ($hits >= $this->maxHits) {
            return false;
        }

        set_transient($key, [$hits + 1, $start], $this->windowSeconds);
        return true;
    }

    private function key(string $ip): string
    {
        return "hfc_rl_{$this->namespace}_" . md5($ip);
    }

    private function now(): int
    {
        return $GLOBALS['hfc_test_now'] ?? time();
    }
}
