# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2025-12-24

### Removed

- Removed jitter from `SleepHandler` and `ThrowExceptionHandler`

## [1.0.0] - 2025-12-24

### Added

- Initial release
- `RateLimiterMiddleware` with thread-safe rate limiting using Symfony RateLimiter and Lock components
- Factory methods: `perSecond()`, `perMinute()`, `perXSeconds()`, `perXMinutes()`, `tokenBucket()`
- `SleepHandler` for blocking until rate limit resets
- `ThrowExceptionHandler` for throwing `RateLimitException`
- Support for any PSR-6 cache implementation