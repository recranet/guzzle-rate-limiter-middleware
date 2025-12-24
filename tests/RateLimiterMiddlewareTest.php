<?php

namespace Recranet\GuzzleRateLimiterMiddleware\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Recranet\GuzzleRateLimiterMiddleware\Exception\RateLimitException;
use Recranet\GuzzleRateLimiterMiddleware\Handler\ThrowExceptionHandler;
use Recranet\GuzzleRateLimiterMiddleware\RateLimiterMiddleware;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Integration tests for RateLimiterMiddleware.
 *
 * Tests the rate limiter with various configurations:
 * - 1 request per second (SleepHandler)
 * - Multiple requests per X minutes (SleepHandler/ThrowExceptionHandler)
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
     *
     * This test verifies that when using the default SleepHandler:
     * 1. First request succeeds immediately
     * 2. Second request is rate limited, sleeps, then succeeds
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
            new Response(200, [], 'Second'), // This should not be reached
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
     * Test that RateLimitException contains retry delay.
     */
    public function testRateLimitExceptionContainsRetryDelay(): void
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

        // Second request should throw with retry delay
        try {
            $client->get('https://example.com/test');
            $this->fail('Expected RateLimitException was not thrown');
        } catch (RateLimitException $e) {
            $retryDelay = $e->getRetryDelay();
            $this->assertNotNull($retryDelay);
            $this->assertGreaterThan(0, $retryDelay);
            $this->assertLessThanOrEqual(1000, $retryDelay, 'Retry delay should be ~1 second or less');
        }
    }

    /**
     * Test perXMinutes rate limiting with ThrowExceptionHandler.
     *
     * Uses a smaller limit (3 requests) for faster testing.
     */
    public function testPerXMinutesWithThrowExceptionHandler(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'Request 1'),
            new Response(200, [], 'Request 2'),
            new Response(200, [], 'Request 3'),
            new Response(200, [], 'Request 4'), // Should not be reached
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perXMinutes(
            5,
            3, // 3 requests per 5 minutes for faster testing
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
     *
     * This test verifies that multiple sequential requests don't deadlock.
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
            10, // High limit to avoid rate limiting
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
     * Test stacked rate limiters configuration.
     *
     * Configuration:
     * - 1 request per second (SleepHandler)
     * - 3 requests per 5 minutes (ThrowExceptionHandler) - reduced for testing
     */
    public function testStackedRateLimiters(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'Request 1'),
            new Response(200, [], 'Request 2'),
            new Response(200, [], 'Request 3'),
            new Response(200, [], 'Request 4'), // Should not be reached
        ]);

        $handlerStack = HandlerStack::create($mockHandler);

        // Per-second limit with SleepHandler (default)
        $handlerStack->push(RateLimiterMiddleware::perSecond(
            1,
            $this->cache,
            $this->lockFactory,
            'stacked-second',
        ));

        // Per 5 minutes limit with ThrowExceptionHandler
        $handlerStack->push(RateLimiterMiddleware::perXMinutes(
            5,
            3, // 3 requests per 5 minutes for faster testing
            $this->cache,
            $this->lockFactory,
            'stacked-5-minutes',
            new ThrowExceptionHandler(),
        ));

        $client = new Client(['handler' => $handlerStack]);

        // First request should succeed immediately
        $startTime = microtime(true);
        $response1 = $client->get('https://example.com/test');
        $this->assertEquals(200, $response1->getStatusCode());

        // Second request should wait for per-second limit
        $response2 = $client->get('https://example.com/test');
        $this->assertEquals(200, $response2->getStatusCode());

        // Third request should wait for per-second limit
        $response3 = $client->get('https://example.com/test');
        $this->assertEquals(200, $response3->getStatusCode());

        $totalTime = microtime(true) - $startTime;
        $this->assertGreaterThan(2.0, $totalTime, 'Should have waited ~2 seconds for rate limits');

        // Fourth request should throw due to 5-minute limit
        $this->expectException(RateLimitException::class);
        $client->get('https://example.com/test');
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

        // Create two clients with different rate limiter IDs
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
     * Test that retry delay is calculated based on fixed window algorithm.
     *
     * When all tokens are consumed in a 5-minute window, the fixed_window
     * policy returns the time until the window resets (up to 5 minutes).
     */
    public function testRetryDelayMatchesWindowDuration(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], 'Request 1'),
            new Response(200, [], 'Request 2'),
            new Response(200, [], 'Request 3'),
            new Response(200, [], 'Request 4'), // Should not be reached
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perXMinutes(
            5, // 5 minute window
            3, // 3 requests allowed
            $this->cache,
            $this->lockFactory,
            'test-window-duration',
            new ThrowExceptionHandler(),
        ));

        $client = new Client(['handler' => $handlerStack]);

        // Consume all 3 tokens
        for ($i = 1; $i <= 3; ++$i) {
            $response = $client->get('https://example.com/test');
            $this->assertEquals(200, $response->getStatusCode());
        }

        // 4th request should throw with retry delay until window resets
        try {
            $client->get('https://example.com/test');
            $this->fail('Expected RateLimitException was not thrown');
        } catch (RateLimitException $e) {
            $retryDelay = $e->getRetryDelay();
            $this->assertNotNull($retryDelay);

            // Fixed window returns time until window resets (up to 5 minutes = 300,000ms)
            // Allow tolerance for test execution time
            $this->assertGreaterThan(290000, $retryDelay, 'Retry delay should be close to 5 minutes');
            $this->assertLessThanOrEqual(300000, $retryDelay, 'Retry delay should not exceed 5 minutes');
        }
    }

    /**
     * Test rate limiter with high volume of requests.
     *
     * This test verifies the rate limiter works correctly over many requests
     * using the SleepHandler to automatically wait when rate limited.
     *
     * @group slow
     */
    public function testHighVolumeWithSleepHandler(): void
    {
        $requestCount = 5;
        $responses = array_map(
            fn ($i) => new Response(200, [], "Request $i"),
            range(1, $requestCount)
        );

        $mockHandler = new MockHandler($responses);

        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(RateLimiterMiddleware::perSecond(
            2, // 2 requests per second
            $this->cache,
            $this->lockFactory,
            'test-high-volume',
        ));

        $client = new Client(['handler' => $handlerStack]);

        $startTime = microtime(true);

        for ($i = 1; $i <= $requestCount; ++$i) {
            $response = $client->get('https://example.com/test');
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals("Request $i", (string) $response->getBody());
        }

        $totalTime = microtime(true) - $startTime;

        // With 5 requests at 2/second, we should take at least 2 seconds
        // (first 2 immediate, then wait 1s, next 2, then wait 1s, last 1)
        $this->assertGreaterThan(1.5, $totalTime, 'Should have waited for rate limits');
    }
}