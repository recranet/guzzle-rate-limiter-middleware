<?php

declare(strict_types=1);

namespace Recranet\GuzzleRateLimiterMiddleware;

use Psr\Http\Message\RequestInterface;

/**
 * Interface for handling rate limit exceeded scenarios.
 */
interface RateLimitExceededHandler
{
    /**
     * Handle a rate limit exceeded scenario.
     *
     * @param int $waitMs The number of milliseconds to wait before retrying
     * @param RequestInterface $request The HTTP request that was rate limited
     * @param array<string, mixed> $options The Guzzle request options
     * @param callable $nextHandler The next handler in the middleware stack
     *
     * @return mixed The result (could be a promise, response, or throw an exception)
     */
    public function handle(int $waitMs, RequestInterface $request, array $options, callable $nextHandler): mixed;
}