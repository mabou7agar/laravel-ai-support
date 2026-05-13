<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Admin;

use Illuminate\Support\Collection;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Repositories\AINodeRepository;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class NodeAdminService
{
    public function __construct(
        protected AINodeRepository $nodes,
        protected NodeRegistryService $registry
    ) {}

    public function recentNodes(int $limit = 100): Collection
    {
        return $this->nodes->recent($limit);
    }

    public function findNode(int $nodeId): ?AINode
    {
        return $this->nodes->find($nodeId);
    }

    public function updateNode(int $nodeId, array $data): ?AINode
    {
        $node = $this->nodes->find($nodeId);
        if (!$node) {
            return null;
        }

        $node->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'type' => $data['type'],
            'url' => $data['url'],
            'description' => $data['description'] ?? null,
            'capabilities' => $this->parseCsvList(
                $data['capabilities'] ?? null,
                (array) ($node->capabilities ?? config('ai-engine.nodes.capabilities', ['search', 'actions', 'rag']))
            ),
            'weight' => (int) ($data['weight'] ?? 1),
        ]);

        $apiKey = $this->normalizeNullableString($data['api_key'] ?? null);
        if ($apiKey !== null) {
            $node->api_key = $apiKey;
        }

        if ($node->isDirty()) {
            $this->nodes->save($node);
        }

        if ($node->status !== $data['status']) {
            $this->registry->updateStatus($node, (string) $data['status']);
            $node->refresh();
        }

        return $node;
    }

    public function setStatus(int $nodeId, string $status): ?AINode
    {
        $node = $this->nodes->find($nodeId);
        if (!$node) {
            return null;
        }

        $this->registry->updateStatus($node, $status);
        $node->refresh();

        return $node;
    }

    public function ping(int $nodeId): ?array
    {
        $node = $this->nodes->find($nodeId);
        if (!$node) {
            return null;
        }

        $success = $this->registry->ping($node);
        $node->refresh();

        return [
            'node' => $node,
            'success' => $success,
        ];
    }

    public function delete(int $nodeId): ?string
    {
        $node = $this->nodes->find($nodeId);
        if (!$node) {
            return null;
        }

        $slug = (string) $node->slug;
        $this->registry->unregister($node);

        return $slug;
    }

    public function parseCsvList(?string $value, array $default = []): array
    {
        if (!is_string($value) || trim($value) === '') {
            return array_values(array_unique(array_filter(array_map(
                static fn ($item): string => trim((string) $item),
                $default
            ))));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        ))));
    }

    protected function normalizeNullableString(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
