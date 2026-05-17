<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Memory;

use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vector\VectorDriverManager;

class ConversationMemorySemanticIndex
{
    public function __construct(
        protected ConversationMemoryPolicy $policy,
        protected VectorDriverManager $drivers,
        protected EmbeddingService $embeddings,
    ) {
    }

    public function index(ConversationMemoryItem $item): bool
    {
        if (!$this->policy->semanticEnabled()) {
            return false;
        }

        $memoryId = $item->memoryId;
        if ($memoryId === null || $memoryId === '' || trim($item->summary) === '') {
            return false;
        }

        try {
            $vector = $this->embeddings->embed($this->text($item), $item->userId);
            $driver = $this->drivers->driver($this->policy->semanticDriver());
            $collection = $this->policy->semanticCollection();

            if (!$driver->collectionExists($collection)) {
                $driver->createCollection($collection, count($vector), [
                    'payload_index_fields' => $this->policy->semanticPayloadScopeFields(),
                ]);
            }

            return $driver->upsert($collection, [[
                'id' => $memoryId,
                'vector' => $vector,
                'metadata' => $this->payload($item),
            ]]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, float>
     */
    public function search(ConversationMemoryQuery $query): array
    {
        if (!$this->policy->semanticEnabled() || trim($query->message) === '') {
            return [];
        }

        try {
            $driver = $this->drivers->driver($this->policy->semanticDriver());
            $results = $driver->search(
                $this->policy->semanticCollection(),
                $this->embeddings->embed($query->message, $query->userId),
                max(1, $query->limit),
                $this->policy->minScore(),
                $this->filters($query)
            );
        } catch (\Throwable) {
            return [];
        }

        $scores = [];
        foreach ($results as $result) {
            $memoryId = (string) ($result['metadata']['memory_id'] ?? $result['id'] ?? '');
            if ($memoryId === '') {
                continue;
            }

            $scores[$memoryId] = max($scores[$memoryId] ?? 0.0, (float) ($result['score'] ?? 0.0));
        }

        arsort($scores);

        return $scores;
    }

    protected function text(ConversationMemoryItem $item): string
    {
        return trim(implode("\n", array_filter([
            $item->namespace . ':' . $item->key,
            $item->summary,
            $item->value,
        ])));
    }

    /**
     * @return array<string, string|null>
     */
    protected function payload(ConversationMemoryItem $item): array
    {
        $values = [
            'memory_id' => $item->memoryId,
            'namespace' => $item->namespace,
            'user_id' => $item->userId,
            'tenant_id' => $item->tenantId,
            'workspace_id' => $item->workspaceId,
            'session_id' => $item->sessionId,
        ];

        $payload = ['memory_id' => $item->memoryId];
        foreach ($this->policy->semanticPayloadScopeFields() as $field) {
            if (array_key_exists($field, $values)) {
                $payload[$field] = $values[$field];
            }
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    protected function filters(ConversationMemoryQuery $query): array
    {
        $values = [
            'namespace' => $query->namespace,
            'user_id' => $query->userId,
            'tenant_id' => $query->tenantId,
            'workspace_id' => $query->workspaceId,
            'session_id' => $query->sessionId,
        ];

        $filters = [];
        foreach ($this->policy->semanticPayloadScopeFields() as $field) {
            $value = $values[$field] ?? null;
            if ($value !== null && $value !== '') {
                $filters[$field] = (string) $value;
            }
        }

        return $filters;
    }
}
