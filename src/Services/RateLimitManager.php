<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Exceptions\RateLimitExceededException;

class RateLimitManager
{
    public function __construct(
        private Application $app
    ) {}

    /**
     * Check if request is within rate limits
     */
    public function checkRateLimit(EngineEnum $engine, ?string $userId = null): bool
    {
        if (!config('ai-engine.rate_limiting.enabled', true)) {
            return true;
        }

        $limits = config("ai-engine.rate_limiting.per_engine.{$engine->value}");
        if (!$limits) {
            return true;
        }

        $key = $this->getRateLimitKey($engine, $userId);
        $requests = $limits['requests'];
        $perMinute = $limits['per_minute'];

        $current = Cache::driver($this->getCacheDriver())->get($key, 0);

        if ($current >= $requests) {
            throw new RateLimitExceededException(
                "Rate limit exceeded for engine {$engine->value}. Limit: {$requests} requests per {$perMinute} minute(s)"
            );
        }

        // Increment counter
        Cache::driver($this->getCacheDriver())->put($key, $current + 1, $perMinute * 60);

        return true;
    }

    /**
     * Get remaining requests for rate limit
     */
    public function getRemainingRequests(EngineEnum $engine, ?string $userId = null): int
    {
        if (!config('ai-engine.rate_limiting.enabled', true)) {
            return PHP_INT_MAX;
        }

        $limits = config("ai-engine.rate_limiting.per_engine.{$engine->value}");
        if (!$limits) {
            return PHP_INT_MAX;
        }

        $key = $this->getRateLimitKey($engine, $userId);
        $requests = $limits['requests'];
        $current = Cache::driver($this->getCacheDriver())->get($key, 0);

        return max(0, $requests - $current);
    }

    /**
     * Reset rate limit for engine/user
     */
    public function resetRateLimit(EngineEnum $engine, ?string $userId = null): bool
    {
        $key = $this->getRateLimitKey($engine, $userId);
        return Cache::driver($this->getCacheDriver())->forget($key);
    }

    /**
     * Generate rate limit cache key
     */
    private function getRateLimitKey(EngineEnum $engine, ?string $userId = null): string
    {
        $base = "ai_engine:rate_limit:{$engine->value}";
        return $userId ? "{$base}:user:{$userId}" : "{$base}:global";
    }

    /**
     * Get cache driver for rate limiting
     */
    private function getCacheDriver(): string
    {
        return config('ai-engine.rate_limiting.driver', 'redis');
    }
}
