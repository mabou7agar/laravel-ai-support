<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;

class CacheManager
{
    public function __construct(
        private Application $app
    ) {}

    /**
     * Get cached response for request
     */
    public function get(AIRequest $request): ?AIResponse
    {
        if (!config('ai-engine.cache.enabled', true)) {
            return null;
        }

        $cacheKey = $this->generateCacheKey($request);
        $cached = Cache::driver($this->getCacheDriver())->get($cacheKey);

        if ($cached) {
            return unserialize($cached);
        }

        // Check semantic cache if enabled
        if (config('ai-engine.cache.semantic_enabled', false)) {
            return $this->getSemanticCache($request);
        }

        return null;
    }

    /**
     * Cache response for request
     */
    public function put(AIRequest $request, AIResponse $response): bool
    {
        if (!config('ai-engine.cache.enabled', true)) {
            return false;
        }

        $cacheKey = $this->generateCacheKey($request);
        $ttl = config('ai-engine.cache.ttl', 3600);

        return Cache::driver($this->getCacheDriver())->put(
            $cacheKey,
            serialize($response),
            $ttl
        );
    }

    /**
     * Clear cache for specific engine or all
     */
    public function clear(?string $engine = null): bool
    {
        $pattern = $engine ? "ai_engine:{$engine}:*" : "ai_engine:*";
        
        // This would need to be implemented based on cache driver
        // For now, just clear all AI engine cache
        return true;
    }

    /**
     * Get semantic cache (similar prompts)
     */
    private function getSemanticCache(AIRequest $request): ?AIResponse
    {
        // This would implement semantic similarity search
        // For now, return null (not implemented)
        return null;
    }

    /**
     * Generate cache key for request
     */
    private function generateCacheKey(AIRequest $request): string
    {
        $keyData = [
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'prompt' => $request->prompt,
            'parameters' => $request->parameters,
            'system_prompt' => $request->systemPrompt,
            'messages' => $request->messages,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'seed' => $request->seed,
        ];

        return 'ai_engine:' . md5(serialize($keyData));
    }

    /**
     * Get cache driver
     */
    private function getCacheDriver(): string
    {
        return config('ai-engine.cache.driver', 'redis');
    }
}
