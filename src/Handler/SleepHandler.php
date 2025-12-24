<?php

namespace Recranet\GuzzleRateLimiterMiddleware\Handler;

use Psr\Http\Message\RequestInterface;

/**
 * Sleeps when the rate limit is exceeded, allowing the middleware to retry.
 *
 * Use this handler when blocking the current process is acceptable
 * (e.g., in CLI scripts or synchronous operations).
 */
final class SleepHandler implements RateLimitExceededHandler
{
    /**
     * @param int $min Minimum delay in milliseconds
     * @param int $max Maximum delay in milliseconds
     */
    public function __construct(
        private readonly int $min = 0,
        private readonly int $max = 300000,
    ) {
    }

    public function handle(int $waitMs, RequestInterface $request, array $options, callable $nextHandler): mixed
    {
        $waitMs = min($this->max, max($this->min, $waitMs));

        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }

        // Return null to signal the middleware to retry
        return null;
    }
}
