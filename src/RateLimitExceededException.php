<?php

declare(strict_types=1);

namespace Recranet\GuzzleRateLimiterMiddleware;

/**
 * Exception thrown when a rate limit is exceeded.
 */
final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        private readonly int $retryAfterMs,
        string $message = 'Rate limit exceeded',
    ) {
        parent::__construct($message);
    }

    /**
     * Get the number of milliseconds to wait before retrying.
     */
    public function getRetryAfterMs(): int
    {
        return $this->retryAfterMs;
    }
}