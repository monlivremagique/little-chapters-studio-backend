<?php

declare(strict_types=1);

namespace App\RateLimiting;

/**
 * Marks a controller method (or all methods of a controller) for rate limiting.
 *
 * @see RateLimitSubscriber applies the limit at kernel.controller time.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class RateLimit
{
    /**
     * @param string       $limiter     Name of the limiter defined in rate_limiter.yaml
     * @param string       $keyStrategy How to build the rate limit key:
     *                                  - 'ip'          client IP (anonymous endpoints)
     *                                  - 'token'       X-Personalization-Owner-Token header
     *                                  - 'user'        JWT user identifier (username/UUID)
     *                                  - 'session'     {ownerToken}:{routeParams[id]}
     *                                  - 'webhook'     source IP + User-Agent prefix
     *                                  - 'global'      fixed single key (for catch-all)
     * @param int|null     $limit       Override the limit from config (optional)
     * @param string|null  $interval    Override the interval from config (optional)
     */
    public function __construct(
        public readonly string $limiter,
        public readonly string $keyStrategy = 'ip',
        public readonly ?int $limit = null,
        public readonly ?string $interval = null,
    ) {
    }
}
