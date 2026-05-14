<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Exceptions\RateLimitExceededException;

class RateLimitManager
{
    public function __construct(
        private Application $app
    ) {}

    /**
     * Check if request is within rate limits
     */
    public function checkRateLimit(EngineEnum $engine, ?string $userId = null, array $scope = []): bool
    {
        if (!config('ai-engine.rate_limiting.enabled', true)) {
            return true;
        }

        $limits = config("ai-engine.rate_limiting.per_engine.{$engine->value}");
        if (!$limits) {
            return true;
        }

        $key = $this->getRateLimitKey($engine, $userId, $scope);
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
    public function getRemainingRequests(EngineEnum $engine, ?string $userId = null, array $scope = []): int
    {
        if (!config('ai-engine.rate_limiting.enabled', true)) {
            return PHP_INT_MAX;
        }

        $limits = config("ai-engine.rate_limiting.per_engine.{$engine->value}");
        if (!$limits) {
            return PHP_INT_MAX;
        }

        $key = $this->getRateLimitKey($engine, $userId, $scope);
        $requests = $limits['requests'];
        $current = Cache::driver($this->getCacheDriver())->get($key, 0);

        return max(0, $requests - $current);
    }

    /**
     * Reset rate limit for engine/user
     */
    public function resetRateLimit(EngineEnum $engine, ?string $userId = null, array $scope = []): bool
    {
        $key = $this->getRateLimitKey($engine, $userId, $scope);
        return Cache::driver($this->getCacheDriver())->forget($key);
    }

    /**
     * Generate rate limit cache key
     */
    private function getRateLimitKey(EngineEnum $engine, ?string $userId = null, array $scope = []): string
    {
        $base = "ai_engine:rate_limit:{$engine->value}";
        $key = $userId ? "{$base}:user:{$userId}" : "{$base}:global";

        $scope = array_filter($scope, static fn ($value, string $name): bool => $name !== 'user_id' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);
        if ($scope === []) {
            return $key;
        }

        ksort($scope);

        foreach ($scope as $name => $value) {
            if (is_array($value)) {
                $value = md5(json_encode($value));
            }

            $key .= ':' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) $name)
                . ':' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) $value);
        }

        return $key;
    }

    /**
     * Get cache driver for rate limiting
     */
    private function getCacheDriver(): string
    {
        return config('ai-engine.rate_limiting.driver', 'redis');
    }
}
