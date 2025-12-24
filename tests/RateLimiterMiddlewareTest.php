<?php

namespace Recranet\GuzzleRateLimiterMiddleware\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Recranet\GuzzleRateLimiterMiddleware\Exception\RateLimitException;
use Recranet\GuzzleRateLimiterMiddleware\Handler\SleepHandler;
use Recranet\GuzzleRateLimiterMiddleware\Handler\ThrowExceptionHandler;
use Recranet\GuzzleRateLimiterMiddleware\RateLimiterMiddleware;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Integration tests for RateLimiterMiddleware.
 */
class RateLimiterMiddlewareTest extends TestCase
{
    private ArrayAdapter $cache;
    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new ArrayAdapter();
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    /**
     * Test that requests within the rate limit are allowed through.
     */
    public function testRequestWithinLimitIsAllowed(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'OK'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perSecond(
            1,
            $this->cache,
            $this->lockFactory,
            'test-per-second',
        ));

        $client = new Client(['handler' => $handlerStack]);
        $response = $client->get('https://example.com/test');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', (string) $response->getBody());
    }

    /**
     * Test that SleepHandler (default) sleeps and retries when rate limit is exceeded.
     */
    public function testSleepHandlerSleepsAndRetries(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'First'),
            new Response(200, [], 'Second'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perSecond(
            1,
            $this->cache,
            $this->lockFactory,
            'test-sleep-handler',
        ));

        $client = new Client(['handler' => $handlerStack]);

        // First request should succeed immediately
        $startTime = microtime(true);
        $response1 = $client->get('https://example.com/test');
        $firstRequestTime = microtime(true) - $startTime;

        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals('First', (string) $response1->getBody());
        $this->assertLessThan(0.5, $firstRequestTime, 'First request should be fast');

        // Second request should be rate limited, sleep ~1 second, then succeed
        $startTime = microtime(true);
        $response2 = $client->get('https://example.com/test');
        $secondRequestTime = microtime(true) - $startTime;

        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('Second', (string) $response2->getBody());
        $this->assertGreaterThan(0.5, $secondRequestTime, 'Second request should wait for rate limit');
    }

    /**
     * Test that ThrowExceptionHandler throws RateLimitException when rate limit is exceeded.
     */
    public function testThrowExceptionHandlerThrowsOnRateLimit(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'First'),
            new Response(200, [], 'Second'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perSecond(
            1,
            $this->cache,
            $this->lockFactory,
            'test-throw-handler',
            new ThrowExceptionHandler(),
        ));

        $client = new Client(['handler' => $handlerStack]);

        // First request should succeed
        $response1 = $client->get('https://example.com/test');
        $this->assertEquals(200, $response1->getStatusCode());

        // Second request should throw RateLimitException
        $this->expectException(RateLimitException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $client->get('https://example.com/test');
    }

    /**
     * Test that RateLimitException contains retry delay with jitter applied.
     */
    public function testRateLimitExceptionContainsRetryDelayWithJitter(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'First'),
            new Response(200, [], 'Second'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perSecond(
            1,
            $this->cache,
            $this->lockFactory,
            'test-retry-delay',
            new ThrowExceptionHandler(),
        ));

        $client = new Client(['handler' => $handlerStack]);

        // First request should succeed
        $client->get('https://example.com/test');

        // Second request should throw with retry delay (with jitter applied)
        try {
            $client->get('https://example.com/test');
            $this->fail('Expected RateLimitException was not thrown');
        } catch (RateLimitException $e) {
            $retryDelay = $e->getRetryDelay();
            $this->assertNotNull($retryDelay);
            $this->assertGreaterThan(0, $retryDelay);
            // With 20% jitter, delay could be up to 1200ms (1000 * 1.2)
            $this->assertLessThanOrEqual(1200, $retryDelay, 'Retry delay should be ~1 second + jitter');
        }
    }

    /**
     * Test perXMinutes rate limiting with ThrowExceptionHandler.
     */
    public function testPerXMinutesWithThrowExceptionHandler(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'Request 1'),
            new Response(200, [], 'Request 2'),
            new Response(200, [], 'Request 3'),
            new Response(200, [], 'Request 4'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perXMinutes(
            5,
            3,
            $this->cache,
            $this->lockFactory,
            'test-per-x-minutes',
            new ThrowExceptionHandler(),
        ));

        $client = new Client(['handler' => $handlerStack]);

        // First 3 requests should succeed
        for ($i = 1; $i <= 3; ++$i) {
            $response = $client->get('https://example.com/test');
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals("Request $i", (string) $response->getBody());
        }

        // 4th request should throw RateLimitException
        $this->expectException(RateLimitException::class);
        $client->get('https://example.com/test');
    }

    /**
     * Test that rate limiter locks are properly acquired and released.
     */
    public function testLocksAreProperlyAcquiredAndReleased(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'Request 1'),
            new Response(200, [], 'Request 2'),
            new Response(200, [], 'Request 3'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perSecond(
            10,
            $this->cache,
            $this->lockFactory,
            'test-locks',
        ));

        $client = new Client(['handler' => $handlerStack]);

        // Multiple sequential requests should all succeed without deadlock
        for ($i = 1; $i <= 3; ++$i) {
            $response = $client->get('https://example.com/test');
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals("Request $i", (string) $response->getBody());
        }
    }

    /**
     * Test that separate rate limiter IDs don't interfere with each other.
     */
    public function testSeparateRateLimiterIdsAreIndependent(): void
    {
        $mockHandler1 = new MockHandler([
            new Response(200, [], 'Client 1 Request 1'),
            new Response(200, [], 'Client 1 Request 2'),
        ]);

        $mockHandler2 = new MockHandler([
            new Response(200, [], 'Client 2 Request 1'),
            new Response(200, [], 'Client 2 Request 2'),
        ]);

        $handlerStack1 = HandlerStack::create($mockHandler1);
        $handlerStack1->push(RateLimiterMiddleware::perSecond(
            1,
            $this->cache,
            $this->lockFactory,
            'client-1-limit',
            new ThrowExceptionHandler(),
        ));

        $handlerStack2 = HandlerStack::create($mockHandler2);
        $handlerStack2->push(RateLimiterMiddleware::perSecond(
            1,
            $this->cache,
            $this->lockFactory,
            'client-2-limit',
            new ThrowExceptionHandler(),
        ));

        $client1 = new Client(['handler' => $handlerStack1]);
        $client2 = new Client(['handler' => $handlerStack2]);

        // First request from each client should succeed
        $response1a = $client1->get('https://example.com/test');
        $this->assertEquals('Client 1 Request 1', (string) $response1a->getBody());

        $response2a = $client2->get('https://example.com/test');
        $this->assertEquals('Client 2 Request 1', (string) $response2a->getBody());

        // Second request from client 1 should throw (rate limited)
        try {
            $client1->get('https://example.com/test');
            $this->fail('Expected RateLimitException for client 1');
        } catch (RateLimitException) {
            // Expected
        }

        // Second request from client 2 should also throw (independently rate limited)
        try {
            $client2->get('https://example.com/test');
            $this->fail('Expected RateLimitException for client 2');
        } catch (RateLimitException) {
            // Expected
        }
    }

    /**
     * Test static factory methods create correct configurations.
     */
    public function testStaticFactoryMethods(): void
    {
        $mockHandler = new MockHandler([
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
        ]);

        // Test perSecond
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perSecond(5, $this->cache, $this->lockFactory, 'test-1'));
        $client = new Client(['handler' => $handlerStack]);
        $this->assertEquals(200, $client->get('https://example.com')->getStatusCode());

        // Test perXSeconds
        $mockHandler = new MockHandler([new Response(200)]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perXSeconds(10, 5, $this->cache, $this->lockFactory, 'test-2'));
        $client = new Client(['handler' => $handlerStack]);
        $this->assertEquals(200, $client->get('https://example.com')->getStatusCode());

        // Test perMinute
        $mockHandler = new MockHandler([new Response(200)]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perMinute(5, $this->cache, $this->lockFactory, 'test-3'));
        $client = new Client(['handler' => $handlerStack]);
        $this->assertEquals(200, $client->get('https://example.com')->getStatusCode());

        // Test perXMinutes
        $mockHandler = new MockHandler([new Response(200)]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perXMinutes(5, 60, $this->cache, $this->lockFactory, 'test-4'));
        $client = new Client(['handler' => $handlerStack]);
        $this->assertEquals(200, $client->get('https://example.com')->getStatusCode());
    }

    /**
     * Test token bucket rate limiter allows first request immediately.
     */
    public function testTokenBucketAllowsFirstRequestImmediately(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'OK'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::tokenBucket(
            '1 second',
            1,
            $this->cache,
            $this->lockFactory,
            'test-token-bucket-first',
        ));

        $client = new Client(['handler' => $handlerStack]);

        $startTime = microtime(true);
        $response = $client->get('https://example.com/test');
        $elapsed = microtime(true) - $startTime;

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertLessThan(0.5, $elapsed, 'First request should be immediate');
    }

    /**
     * Test token bucket with ThrowExceptionHandler throws when no token available.
     */
    public function testTokenBucketWithThrowExceptionHandler(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'First'),
            new Response(200, [], 'Second'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::tokenBucket(
            '1 second',
            1,
            $this->cache,
            $this->lockFactory,
            'test-token-bucket-throw',
            new ThrowExceptionHandler(),
        ));

        $client = new Client(['handler' => $handlerStack]);

        // First request consumes the token
        $response = $client->get('https://example.com/test');
        $this->assertEquals(200, $response->getStatusCode());

        // Second request immediately after should throw (no token available)
        $this->expectException(RateLimitException::class);
        $client->get('https://example.com/test');
    }

    /**
     * Test token bucket burst parameter allows controlled bursting.
     */
    public function testTokenBucketBurstParameter(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'Request 1'),
            new Response(200, [], 'Request 2'),
            new Response(200, [], 'Request 3'),
            new Response(200, [], 'Request 4'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::tokenBucket(
            '1 second',
            3,
            $this->cache,
            $this->lockFactory,
            'test-token-bucket-burst-param',
        ));

        $client = new Client(['handler' => $handlerStack]);

        $startTime = microtime(true);

        // First 3 requests should be immediate (using burst capacity)
        for ($i = 1; $i <= 3; ++$i) {
            $response = $client->get('https://example.com/test');
            $this->assertEquals("Request $i", (string) $response->getBody());
        }

        $burstTime = microtime(true) - $startTime;
        $this->assertLessThan(0.5, $burstTime, 'First 3 requests should be immediate (burst)');

        // 4th request should wait for a token
        $beforeFourth = microtime(true);
        $response = $client->get('https://example.com/test');
        $fourthWait = microtime(true) - $beforeFourth;

        $this->assertEquals('Request 4', (string) $response->getBody());
        $this->assertGreaterThanOrEqual(0.5, $fourthWait, '4th request should wait for token replenishment');
    }

    /**
     * Test ThrowExceptionHandler throws InvalidArgumentException for invalid jitter values.
     */
    public function testThrowExceptionHandlerInvalidJitter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Jitter must be between 0 and 1');

        new ThrowExceptionHandler(jitter: 1.5);
    }

    /**
     * Test SleepHandler throws InvalidArgumentException for invalid jitter values.
     */
    public function testSleepHandlerInvalidJitter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Jitter must be between 0 and 1');

        new SleepHandler(jitter: -0.1);
    }

    /**
     * Test ThrowExceptionHandler with zero jitter returns exact delay.
     */
    public function testThrowExceptionHandlerWithZeroJitter(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'First'),
            new Response(200, [], 'Second'),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perSecond(
            1,
            $this->cache,
            $this->lockFactory,
            'test-zero-jitter',
            new ThrowExceptionHandler(jitter: 0),
        ));

        $client = new Client(['handler' => $handlerStack]);

        // First request should succeed
        $client->get('https://example.com/test');

        // Second request should throw with exact delay (no jitter)
        try {
            $client->get('https://example.com/test');
            $this->fail('Expected RateLimitException was not thrown');
        } catch (RateLimitException $e) {
            $retryDelay = $e->getRetryDelay();
            $this->assertNotNull($retryDelay);
            $this->assertGreaterThan(0, $retryDelay);
            // With zero jitter, delay should be exactly what the rate limiter returns (<=1000ms)
            $this->assertLessThanOrEqual(1000, $retryDelay, 'Retry delay should be ~1 second without jitter');
        }
    }
}
