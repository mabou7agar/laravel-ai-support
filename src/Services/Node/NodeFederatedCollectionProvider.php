<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Contracts\RAG\FederatedCollectionProvider;

/**
 * Core federated RAG collection provider.
 *
 * Holds the remote-node collection discovery logic formerly inlined in
 * RAGCollectionDiscovery (discoverFromNodes() + the remote loop in
 * discoverWithDescriptions()). Behavior is byte-identical.
 */
class NodeFederatedCollectionProvider implements FederatedCollectionProvider
{
    protected $nodeRegistry = null;

    public function __construct(?NodeRegistryService $nodeRegistry = null)
    {
        $this->nodeRegistry = $nodeRegistry
            ?? (class_exists(NodeRegistryService::class) && app()->bound(NodeRegistryService::class)
                ? app(NodeRegistryService::class)
                : (class_exists(NodeRegistryService::class) ? app(NodeRegistryService::class) : null));
    }

    public function isEnabled(): bool
    {
        return $this->nodeRegistry && config('ai-engine.nodes.enabled', false);
    }

    /**
     * Discover collections from remote nodes.
     *
     * @return array
     */
    public function discoverCollections(): array
    {
        $collections = [];

        try {
            $nodes = $this->nodeRegistry->getActiveNodes();

            foreach ($nodes as $node) {

                        foreach ($node->collections ?? [] as $collection) {
                            $collections[] = $collection;
                        }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to discover collections from nodes', [
                'error' => $e->getMessage(),
            ]);
        }

        return $collections;
    }

    /**
     * Discover remote collections keyed by class name, each carrying RAG
     * descriptions and the list of nodes that advertise it.
     *
     * @return array
     */
    public function discoverCollectionsWithDescriptions(): array
    {
        $allCollections = [];

        try {
            $nodes = $this->nodeRegistry->getActiveNodes();

            foreach ($nodes as $node) {
                try {
                    $response = NodeHttpClient::makeAuthenticated($node)
                        ->get($node->getApiUrl('manifest'));

                    if ($response->successful()) {
                        $data = $response->json();
                        foreach (($data['collections'] ?? []) as $collection) {
                            $className = $collection['class'];

                            if (!isset($allCollections[$className])) {
                                $description = $collection['description'] ?? '';

                                // If no description provided, generate a default one with a warning
                                if (empty($description)) {
                                    $name = $collection['name'];
                                    $description = "Search through {$name} collection";

                                    Log::warning('Remote RAG collection missing description - using auto-generated description', [
                                        'class' => $className,
                                        'node' => $node->name,
                                        'auto_description' => $description,
                                        'recommendation' => "Add getRAGDescription() method to {$className} on remote node for better AI selection",
                                    ]);
                                }

                                $allCollections[$className] = [
                                    'class' => $className,
                                    'name' => $collection['name'],
                                    'display_name' => $collection['display_name'] ?? $collection['name'],
                                    'description' => $description,
                                    'nodes' => [],
                                ];
                            }

                            $allCollections[$className]['nodes'][] = [
                                'node_id' => $node->id,
                                'node_slug' => $node->slug,
                                'node_name' => $node->name,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug('Failed to get collections from node', [
                        'node' => $node->slug,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to discover collections from nodes', [
                'error' => $e->getMessage(),
            ]);
        }

        return $allCollections;
    }
}
