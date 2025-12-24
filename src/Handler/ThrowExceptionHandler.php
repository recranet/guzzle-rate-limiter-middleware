<?php

namespace Recranet\GuzzleRateLimiterMiddleware\Handler;

use Psr\Http\Message\RequestInterface;
use Recranet\GuzzleRateLimiterMiddleware\Exception\RateLimitException;

/**
 * Throws a RateLimitException when the rate limit is exceeded.
 *
 * Use this handler when you want the calling code to handle the rate limit
 * (e.g., requeue a message with a delay in a message queue system).
 */
final class ThrowExceptionHandler implements RateLimitExceededHandler
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
        throw new RateLimitException(retryDelay: min($this->max, max($this->min, $waitMs)));
    }
}
