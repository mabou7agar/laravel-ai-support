<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class ProviderResponseStateService
{
    public function __construct(
        protected CacheRepository $cache
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function remember(string $provider, string $conversationId, string $responseId, array $metadata = []): void
    {
        $ttl = (int) config('ai-engine.provider_response_state.ttl_seconds', 86400);

        $this->cache->put($this->key($provider, $conversationId), [
            'provider' => $provider,
            'conversation_id' => $conversationId,
            'response_id' => $responseId,
            'metadata' => $metadata,
            'remembered_at' => now()->toISOString(),
        ], $ttl);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previous(string $provider, string $conversationId): ?array
    {
        $state = $this->cache->get($this->key($provider, $conversationId));

        return is_array($state) ? $state : null;
    }

    protected function key(string $provider, string $conversationId): string
    {
        return 'ai-engine:provider-response-state:' . $provider . ':' . sha1($conversationId);
    }
}
