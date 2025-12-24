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
 * Normalizes delays >= $normalize to $normalize boundaries (rounds up) for predictable behavior.
 * Delays below $normalize are not modified.
 */
final class ThrowExceptionHandler implements RateLimitExceededHandler
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

        throw new RateLimitException(retryDelay: min($this->max, max($this->min, $waitMs)));
    }
}
