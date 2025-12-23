<?php

declare(strict_types=1);

namespace Recranet\GuzzleRateLimiterMiddleware;

use Psr\Http\Message\RequestInterface;

/**
 * Throws a RateLimitExceededException when the rate limit is exceeded.
 *
 * Use this handler when you want the calling code to handle the rate limit
 * (e.g., requeue a message with a delay in a message queue system).
 */
final class ThrowExceptionHandler implements RateLimitExceededHandler
{
    public function handle(int $waitMs, RequestInterface $request, array $options, callable $nextHandler): mixed
    {
        throw new RateLimitExceededException($waitMs);
    }
}