<?php

namespace Recranet\GuzzleRateLimiterMiddleware\Handler;

use Psr\Http\Message\RequestInterface;

/**
 * Sleeps when the rate limit is exceeded, allowing the middleware to retry.
 *
 * Use this handler when blocking the current process is acceptable
 * (e.g., in CLI scripts or synchronous operations).
 *
 * Normalizes delays >= $normalize to $normalize boundaries (rounds up) for predictable behavior.
 * Delays below $normalize are not modified.
 */
final class SleepHandler implements RateLimitExceededHandler
{
    /**
     * @param int $normalize Normalization threshold and boundary in milliseconds (delays >= this value are rounded up to multiples of this value)
     * @param int $min       Minimum delay in milliseconds
     * @param int $max       Maximum delay in milliseconds
     */
    public function __construct(
        private readonly int $normalize = 2000,
        private readonly int $min = 0,
        private readonly int $max = 300000,
    ) {
    }

    public function handle(int $waitMs, RequestInterface $request, array $options, callable $nextHandler): mixed
    {
        // Normalize to $normalize boundaries for predictable behavior (only when >= $normalize)
        if ($waitMs >= $this->normalize) {
            $waitMs = (int) (ceil($waitMs / $this->normalize) * $this->normalize);
        }

        $waitMs = min($this->max, max($this->min, $waitMs));

        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }

        // Return null to signal the middleware to retry
        return null;
    }
}
