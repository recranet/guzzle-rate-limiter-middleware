<?php

namespace Recranet\GuzzleRateLimiterMiddleware;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Recranet\GuzzleRateLimiterMiddleware\Handler\RateLimitExceededHandler;
use Recranet\GuzzleRateLimiterMiddleware\Handler\SleepHandler;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

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
        private readonly RateLimitExceededHandler $handler = new SleepHandler(),
    ) {
    }

    public static function perSecond(
        int $limit,
        CacheItemPoolInterface $cache,
        LockFactory $lockFactory,
        string $id,
        ?RateLimitExceededHandler $handler = null,
    ): self {
        return self::perXSeconds(1, $limit, $cache, $lockFactory, $id, $handler);
    }

    public static function perXSeconds(
        int $seconds,
        int $limit,
        CacheItemPoolInterface $cache,
        LockFactory $lockFactory,
        string $id,
        ?RateLimitExceededHandler $handler = null,
    ): self {
        $rateLimiterFactory = new RateLimiterFactory(
            [
                'id' => $id,
                'policy' => 'sliding_window',
                'limit' => $limit,
                'interval' => sprintf('%d second', $seconds),
            ],
            new CacheStorage($cache),
        );

        return new self(
            $rateLimiterFactory,
            $lockFactory,
            $id.'-lock',
            $handler ?? new SleepHandler(),
        );
    }

    public static function perMinute(
        int $limit,
        CacheItemPoolInterface $cache,
        LockFactory $lockFactory,
        string $id,
        ?RateLimitExceededHandler $handler = null,
    ): self {
        return self::perXMinutes(1, $limit, $cache, $lockFactory, $id, $handler);
    }

    public static function perXMinutes(
        int $minutes,
        int $limit,
        CacheItemPoolInterface $cache,
        LockFactory $lockFactory,
        string $id,
        ?RateLimitExceededHandler $handler = null,
    ): self {
        $rateLimiterFactory = new RateLimiterFactory(
            [
                'id' => $id,
                'policy' => 'sliding_window',
                'limit' => $limit,
                'interval' => sprintf('%d minute', $minutes),
            ],
            new CacheStorage($cache),
        );

        return new self(
            $rateLimiterFactory,
            $lockFactory,
            $id.'-lock',
            $handler ?? new SleepHandler(),
        );
    }

    /**
     * Creates a token bucket rate limiter.
     *
     * Token bucket allows a sustained rate of requests with controlled bursting.
     * Tokens are added at a fixed rate, and each request consumes one token.
     *
     * Example: tokenBucket(rate: '5 seconds', burst: 1) = 1 request every 5 seconds, no bursting
     * Example: tokenBucket(rate: '5 seconds', burst: 3) = sustained 1 req/5s, can burst up to 3
     *
     * @param string $rate How often a token is added (e.g., '5 seconds', '1 minute')
     * @param int $burst Maximum tokens that can accumulate (burst capacity)
     */
    public static function tokenBucket(
        string $rate,
        int $burst,
        CacheItemPoolInterface $cache,
        LockFactory $lockFactory,
        string $id,
        ?RateLimitExceededHandler $handler = null,
    ): self {
        $rateLimiterFactory = new RateLimiterFactory(
            [
                'id' => $id,
                'policy' => 'token_bucket',
                'rate' => ['interval' => $rate],
                'limit' => $burst,
            ],
            new CacheStorage($cache),
        );

        $lockResource = $id.'-lock';

        return new self(
            $rateLimiterFactory,
            $lockFactory,
            $lockResource,
            $handler ?? new SleepHandler(),
        );
    }

    public function __invoke(callable $nextHandler): callable
    {
        return function (RequestInterface $request, array $options) use ($nextHandler) {
            while (true) {
                try {
                    // Use distributed lock to ensure atomic check-and-consume.
                    // TTL is a safety timeout if process crashes - actual lock hold time is milliseconds.
                    $lock = $this->lockFactory->createLock($this->lockResource, ttl: 30);
                    $lock->acquire(true);

                    try {
                        $limiter = $this->rateLimiterFactory->create($this->lockResource);
                        $limit = $limiter->consume(1);
                    } finally {
                        $lock->release();
                    }
                } catch (LockAcquiringException) {
                    // Convert lock acquisition failure to rate limit with 5 second delay
                    // Messenger will retry with this delay
                    $this->handler->handle(5000, $request, $options, $nextHandler);
                    continue;
                }

                if ($limit->isAccepted()) {
                    return $nextHandler($request, $options);
                }

                $retryAfter = $limit->getRetryAfter();
                $waitMs = (int) (($retryAfter->getTimestamp() - time()) * 1000);

                // SleepHandler: sleeps and returns null, loop continues
                // ThrowExceptionHandler: throws, loop exits via exception
                $this->handler->handle($waitMs, $request, $options, $nextHandler);
            }
        };
    }
}
