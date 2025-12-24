<?php

namespace Recranet\GuzzleRateLimiterMiddleware\Exception;

class RateLimitException extends \RuntimeException
{
    public function __construct(
        private readonly ?int $retryDelay = null,
    ) {
        parent::__construct('Rate limit exceeded');
    }

    /**
     * @return int|null The time to wait for the next request in milliseconds
     */
    public function getRetryDelay(): ?int
    {
        return $this->retryDelay;
    }
}
