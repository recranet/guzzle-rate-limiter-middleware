<?php

namespace Recranet\GuzzleRateLimiterMiddleware\Handler;

use Psr\Http\Message\RequestInterface;

/**
 * Sleeps when the rate limit is exceeded, allowing the middleware to retry.
 *
 * Use this handler when blocking the current process is acceptable
 * (e.g., in CLI scripts or synchronous operations).
 *
 * Applies jitter (0 to +jitter%) to the sleep delay to prevent thundering herd problem.
 */
final class SleepHandler implements RateLimitExceededHandler
{
    /**
     * @param float $jitter Jitter factor (0.0 to 1.0) applied to sleep delays
     * @param int   $min    Minimum delay in milliseconds
     * @param int   $max    Maximum delay in milliseconds
     */
    public function __construct(
        private readonly float $jitter = 0.2,
        private readonly int $min = 0,
        private readonly int $max = 300000,
    ) {
        if ($jitter < 0 || $jitter > 1) {
            throw new \InvalidArgumentException(\sprintf('Jitter must be between 0 and 1: "%s" given.', $jitter));
        }
    }

    public function handle(int $waitMs, RequestInterface $request, array $options, callable $nextHandler): mixed
    {
        if ($this->jitter > 0) {
            $waitMs = (int) ($waitMs * (1 + $this->jitter * (random_int(0, 1000) / 1000)));
        }

        $waitMs = min($this->max, max($this->min, $waitMs));

        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }

        // Return null to signal the middleware to retry
        return null;
    }
}
