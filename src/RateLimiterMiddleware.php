<?php

declare(strict_types=1);

namespace Recranet\GuzzleRateLimiterMiddleware;

use Psr\Http\Message\RequestInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * A thread-safe Guzzle middleware for rate limiting HTTP requests.
 *
 * Uses Symfony's RateLimiter component which provides atomic rate limit checks
 * through its storage backends (Redis, etc.), combined with distributed locking
 * to prevent race conditions when multiple workers check limits simultaneously.
 */
final class RateLimiterMiddleware
{
    public function __construct(
        private readonly RateLimiterFactory $rateLimiterFactory,
        private readonly LockFactory $lockFactory,
        private readonly string $lockResource,
        private readonly RateLimitExceededHandler $handler = new ThrowExceptionHandler(),
    ) {
    }

    public function __invoke(callable $nextHandler): callable
    {
        return function (RequestInterface $request, array $options) use ($nextHandler) {
            // Use distributed lock to ensure atomic check-and-consume
            $lock = $this->lockFactory->createLock($this->lockResource, ttl: 30);
            $lock->acquire(true);

            try {
                $limiter = $this->rateLimiterFactory->create($this->lockResource);
                $limit = $limiter->consume(1);

                if (!$limit->isAccepted()) {
                    $retryAfter = $limit->getRetryAfter();
                    $waitMs = $retryAfter !== null
                        ? (int) (($retryAfter->getTimestamp() - time()) * 1000)
                        : 0;

                    return $this->handler->handle($waitMs, $request, $options, $nextHandler);
                }
            } finally {
                $lock->release();
            }

            return $nextHandler($request, $options);
        };
    }
}