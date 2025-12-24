# Guzzle Rate Limiter Middleware

A thread-safe rate limiter middleware for Guzzle using Symfony RateLimiter with atomic locks.

## Features

- Thread-safe rate limiting using distributed locks
- Multiple rate limiting strategies: sliding window and token bucket
- Configurable handlers for rate limit exceeded scenarios
- Built-in jitter to prevent thundering herd problems
- Works across multiple processes and servers

## Why This Package?

Unlike simple in-memory rate limiters, this package uses Symfony's Lock component to provide atomic rate limit checks. This prevents race conditions when multiple workers or processes check limits simultaneously, making it safe for use in distributed systems, queue workers, and multi-threaded applications.

## Installation

```bash
composer require recranet/guzzle-rate-limiter-middleware
```

## Usage

### Basic Usage

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Recranet\GuzzleRateLimiterMiddleware\RateLimiterMiddleware;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$cache = new RedisAdapter($redis);
$lockFactory = new LockFactory(new RedisStore($redis));

$stack = HandlerStack::create();
$stack->push(RateLimiterMiddleware::perSecond(5, $cache, $lockFactory, 'api-client'));

$client = new Client([
    'handler' => $stack,
]);
```

### Factory Methods

The middleware provides several factory methods for common rate limiting scenarios:

```php
// 5 requests per second
RateLimiterMiddleware::perSecond(5, $cache, $lockFactory, 'api-client');

// 100 requests per minute
RateLimiterMiddleware::perMinute(100, $cache, $lockFactory, 'api-client');

// 10 requests per 30 seconds
RateLimiterMiddleware::perXSeconds(30, 10, $cache, $lockFactory, 'api-client');

// 1000 requests per 15 minutes
RateLimiterMiddleware::perXMinutes(15, 1000, $cache, $lockFactory, 'api-client');
```

### Token Bucket

For APIs that allow bursting, use the token bucket strategy:

```php
// Sustained rate of 1 request per 5 seconds, with burst capacity of 3
RateLimiterMiddleware::tokenBucket(
    rate: '5 seconds',
    burst: 3,
    cache: $cache,
    lockFactory: $lockFactory,
    id: 'api-client',
);
```

## Handlers

When the rate limit is exceeded, a handler determines what happens next.

### SleepHandler (Default)

Blocks the process until the rate limit window resets, then retries automatically:

```php
use Recranet\GuzzleRateLimiterMiddleware\Handler\SleepHandler;

$handler = new SleepHandler(
    jitter: 0.2,    // Add 0-20% random delay (default)
    min: 0,         // Minimum delay in ms
    max: 300000,    // Maximum delay in ms (5 minutes)
);

RateLimiterMiddleware::perSecond(5, $cache, $lockFactory, 'api-client', $handler);
```

### ThrowExceptionHandler

Throws a `RateLimitException` for the calling code to handle:

```php
use Recranet\GuzzleRateLimiterMiddleware\Handler\ThrowExceptionHandler;
use Recranet\GuzzleRateLimiterMiddleware\Exception\RateLimitException;

$handler = new ThrowExceptionHandler(
    jitter: 0.2,
    min: 0,
    max: 300000,
);

$middleware = RateLimiterMiddleware::perSecond(5, $cache, $lockFactory, 'api-client', $handler);

try {
    $response = $client->get('/api/endpoint');
} catch (RateLimitException $e) {
    $retryAfterMs = $e->getRetryDelay();
    // Handle accordingly, e.g., requeue with delay
}
```

This is useful for message queue systems where you want to requeue the job with a delay rather than blocking the worker.

### Custom Handler

Implement `RateLimitExceededHandler` to create your own handler:

```php
use Psr\Http\Message\RequestInterface;
use Recranet\GuzzleRateLimiterMiddleware\Handler\RateLimitExceededHandler;

class LogAndSleepHandler implements RateLimitExceededHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function handle(int $waitMs, RequestInterface $request, array $options, callable $nextHandler): mixed
    {
        $this->logger->warning('Rate limit exceeded', [
            'wait_ms' => $waitMs,
            'uri' => (string) $request->getUri(),
        ]);

        usleep($waitMs * 1000);

        return null; // Return null to retry
    }
}
```

## Cache Backends

Any PSR-6 cache implementation works. Here are some common options:

### Redis (Recommended for distributed systems)

```php
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\Store\RedisStore;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$cache = new RedisAdapter($redis);
$lockFactory = new LockFactory(new RedisStore($redis));
```

### Filesystem (Single server)

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\Store\FlockStore;

$cache = new FilesystemAdapter();
$lockFactory = new LockFactory(new FlockStore());
```

### APCu (Single server, high performance)

```php
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Lock\Store\SemaphoreStore;

$cache = new ApcuAdapter();
$lockFactory = new LockFactory(new SemaphoreStore());
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.