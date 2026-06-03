<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Contracts\RAG\NodeContextProvider;
use LaravelAIEngine\Models\AINode;

/**
 * Core node context provider for RAG.
 *
 * Holds the available-node discovery logic formerly inlined in
 * RAGContextService::getAvailableNodes(). Behavior is byte-identical.
 */
class NodeContextProviderImpl implements NodeContextProvider
{
    public function __construct(
        protected ?NodeRegistryService $nodeRegistry = null
    ) {
        $this->nodeRegistry = $nodeRegistry ?? (app()->bound(NodeRegistryService::class) ? app(NodeRegistryService::class) : null);
    }

    public function getAvailableNodes(): array
    {
        $nodes = new Collection();

        if ($this->nodeRegistry) {
            try {
                $nodes = $this->nodeRegistry->getActiveNodes();
            } catch (\Throwable $e) {
                Log::channel('ai-engine')->warning('Failed loading nodes from NodeRegistryService', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (
            $nodes->isEmpty()
            && config('ai-engine.nodes.enabled', true)
            && Schema::hasTable((new AINode())->getTable())
        ) {
            $nodes = AINode::active()->healthy()->get();
        }

        return $nodes->map(function ($node) {
            $collections = $node->collections ?? [];
            $models = [];

            if (!empty($collections) && is_array($collections)) {
                $firstItem = reset($collections);
                if (is_array($firstItem) && isset($firstItem['name'])) {
                    $models = collect($collections)->map(fn (array $collection) => [
                        'name' => $collection['name'],
                        'display_name' => $collection['display_name'] ?? $collection['name'],
                        'description' => $collection['description'] ?? "Model for {$collection['name']} data",
                        'aliases' => array_values((array) ($collection['aliases'] ?? [])),
                        'capabilities' => $collection['capabilities'] ?? [],
                    ])->toArray();
                } else {
                    $models = collect($collections)->map(fn ($collection) => [
                        'name' => strtolower(class_basename($collection)),
                        'display_name' => class_basename($collection),
                        'description' => 'Model for ' . class_basename($collection) . ' data',
                        'aliases' => [],
                        'capabilities' => [],
                    ])->toArray();
                }
            }

            return [
                'slug' => $node->slug,
                'name' => $node->name,
                'description' => $node->description,
                'models' => $models,
                'collections' => $collections,
            ];
        })->toArray();
    }
}
