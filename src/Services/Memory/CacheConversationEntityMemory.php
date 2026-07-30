<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Memory;

use Illuminate\Contracts\Cache\Repository;
use LaravelAIEngine\Contracts\ConversationEntityMemory;
use LaravelAIEngine\DTOs\ConversationEntityReference;

final class CacheConversationEntityMemory implements ConversationEntityMemory
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function remember(string $sessionId, ConversationEntityReference $reference, array $scope = []): void
    {
        if ($reference->type === '' || $reference->id === '') {
            throw new \InvalidArgumentException('Conversation entity references require type and id.');
        }

        $key = $this->key($sessionId, $scope);
        $items = $this->raw($key);
        $identity = $reference->type.':'.$reference->id;
        $items = array_values(array_filter(
            $items,
            static fn (array $item): bool => (($item['type'] ?? '').':'.($item['id'] ?? '')) !== $identity,
        ));
        array_unshift($items, $reference->toArray());
        $limit = max(1, (int) config('ai-agent.assistant.entity_memory.max_references', 20));
        $ttl = max(60, (int) config('ai-agent.assistant.entity_memory.ttl_seconds', 86400));
        $this->cache->put($key, array_slice($items, 0, $limit), $ttl);
    }

    public function focus(string $sessionId, ?string $type = null, array $scope = []): ?ConversationEntityReference
    {
        return $this->recent($sessionId, $type, 1, $scope)[0] ?? null;
    }

    public function recent(string $sessionId, ?string $type = null, int $limit = 10, array $scope = []): array
    {
        $items = array_map(
            static fn (array $item): ConversationEntityReference => ConversationEntityReference::fromArray($item),
            $this->raw($this->key($sessionId, $scope)),
        );
        if ($type !== null && trim($type) !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (ConversationEntityReference $item): bool => $item->type === $type,
            ));
        }

        return array_slice($items, 0, max(1, $limit));
    }

    public function forget(string $sessionId, array $scope = []): void
    {
        $this->cache->forget($this->key($sessionId, $scope));
    }

    /** @return list<array<string, mixed>> */
    private function raw(string $key): array
    {
        return array_values(array_filter(
            (array) $this->cache->get($key, []),
            static fn (mixed $item): bool => is_array($item),
        ));
    }

    /** @param array<string, mixed> $scope */
    private function key(string $sessionId, array $scope): string
    {
        $scopeValues = [
            'session' => trim($sessionId),
            'user' => (string) ($scope['user_id'] ?? ''),
            'tenant' => (string) ($scope['tenant_id'] ?? ''),
            'workspace' => (string) ($scope['workspace_id'] ?? ''),
        ];

        return 'ai-agent:assistant-entities:'.hash('sha256', json_encode($scopeValues));
    }
}
