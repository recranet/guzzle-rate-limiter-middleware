<?php

namespace Recranet\GuzzleRateLimiterMiddleware\Handler;

use Psr\Http\Message\RequestInterface;
use Recranet\GuzzleRateLimiterMiddleware\Exception\RateLimitException;

/**
 * Throws a RateLimitException when the rate limit is exceeded.
 *
 * Use this handler when you want the calling code to handle the rate limit
 * (e.g., requeue a message with a delay in a message queue system).
 *
 * Applies jitter (0 to +jitter%) to the retry delay to prevent thundering herd problem.
 */
final class ThrowExceptionHandler implements RateLimitExceededHandler
{
    /**
     * @param float $jitter Jitter factor (0.0 to 1.0) applied to retry delays
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

        throw new RateLimitException(retryDelay: min($this->max, max($this->min, $waitMs)));
    }
}
