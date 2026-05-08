<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Memory;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Contracts\ConversationMemory;

class CacheConversationMemory implements ConversationMemory
{
    public function get(string $namespace, string $key, mixed $default = null): mixed
    {
        return Cache::get($this->cacheKey($namespace, $key), $default);
    }

    public function put(string $namespace, string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): void
    {
        Cache::put($this->cacheKey($namespace, $key), $value, $ttl);
    }

    public function forget(string $namespace, string $key): void
    {
        Cache::forget($this->cacheKey($namespace, $key));
    }

    public function pull(string $namespace, string $key, mixed $default = null): mixed
    {
        return Cache::pull($this->cacheKey($namespace, $key), $default);
    }

    protected function cacheKey(string $namespace, string $key): string
    {
        $namespace = trim($namespace, ':');
        $key = trim($key, ':');

        return "ai-engine:conversation-memory:{$namespace}:{$key}";
    }
}

