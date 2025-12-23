<?php

declare(strict_types=1);

namespace Recranet\GuzzleRateLimiterMiddleware;

use Psr\Http\Message\RequestInterface;

/**
 * Sleeps and then retries the request when the rate limit is exceeded.
 *
 * Use this handler when blocking the current process is acceptable
 * (e.g., in CLI scripts or synchronous operations).
 */
final class SleepHandler implements RateLimitExceededHandler
{
    public function __construct(
        private readonly RateLimiterMiddleware $middleware,
    ) {
    }

    public function handle(int $waitMs, RequestInterface $request, array $options, callable $nextHandler): mixed
    {
        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }

        // Re-invoke the middleware to re-check and make the request
        $handler = ($this->middleware)($nextHandler);

        return $handler($request, $options);
    }
}