<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

class NodeRegistryService
{
    public function __construct(
        protected CircuitBreakerService $circuitBreaker,
        protected NodeAuthService $authService
    ) {}

    /**
     * Register a new node
     */
    public function register(array $data): AINode
    {
        $node = AINode::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? \Str::slug($data['name']),
            'type' => $data['type'] ?? 'child',
            'url' => $data['url'],
            'api_key' => $data['api_key'] ?? \Str::random(64),
            'capabilities' => $data['capabilities'] ?? ['search', 'actions'],
            'metadata' => $data['metadata'] ?? [],
            'version' => $data['version'] ?? '1.0.0',
            'status' => 'active',
            'weight' => $data['weight'] ?? 1,
        ]);

        // Ping the node to verify connectivity
        $this->ping($node);

        // Clear cache
        $this->clearCache();

        Log::channel('ai-engine')->info('Node registered', [
            'node_id' => $node->id,
            'name' => $node->name,
            'url' => $node->url,
            'type' => $node->type,
        ]);

        return $node;
    }

    /**
     * Unregister a node
     */
    public function unregister(AINode $node): bool
    {
        $deleted = $node->delete();

        $this->clearCache();

        Log::channel('ai-engine')->info('Node unregistered', [
            'node_id' => $node->id,
            'name' => $node->name,
        ]);

        return $deleted;
    }

    /**
     * Get all active nodes
     */
    public function getActiveNodes(): Collection
    {
        if (!$this->nodesTableExists()) {
            return collect();
        }

        if(app()->environment('local')) {
            return AINode::get();
        }
        return Cache::remember('ai_nodes_active', 300, function () {
            return AINode::active()->healthy()->get();
        });
    }

    /**
     * Get all nodes (including inactive)
     */
    public function getAllNodes(): Collection
    {
        if (!$this->nodesTableExists()) {
            return collect();
        }

        return AINode::all();
    }

    /**
     * Get node by slug
     */
    public function getNode(string $slug): ?AINode
    {
        if (!$this->nodesTableExists()) {
            return null;
        }

        return AINode::where('slug', $slug)->first();
    }

    /**
     * Get node by ID
     */
    public function getNodeById(int $id): ?AINode
    {
        if (!$this->nodesTableExists()) {
            return null;
        }

        return AINode::find($id);
    }

    protected function nodesTableExists(): bool
    {
        static $checked = null;

        if ($checked !== null) {
            return $checked;
        }

        try {
            $checked = Schema::hasTable((new AINode())->getTable());
        } catch (\Throwable) {
            $checked = false;
        }

        if ($checked === false) {
            Log::channel('ai-engine')->warning('Node features are disabled because the ai_nodes table is missing');
        }

        return $checked;
    }

    /**
     * Ping a node to check health
     */
    public function ping(AINode $node): bool
    {
        // Check circuit breaker first
        if ($this->circuitBreaker->isOpen($node)) {
            Log::channel('ai-engine')->debug('Skipping ping - circuit breaker open', [
                'node_id' => $node->id,
                'node_slug' => $node->slug,
            ]);
            return false;
        }

        try {
            $startTime = microtime(true);

            $response = NodeHttpClient::makeForHealthCheck($node)
                ->get($node->getApiUrl('health'));

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            $health = $response->json();
            $declaredStatus = strtolower((string) ($health['status'] ?? 'healthy'));
            $declaredReady = (bool) ($health['ready'] ?? true);
            $declaredHealthy = in_array($declaredStatus, ['healthy', 'ok'], true) && $declaredReady;
            $success = $response->successful() && $declaredHealthy;

            // Record ping result
            $node->recordPing($success, $duration);

            if ($success) {
                $updateData = [
                    'version' => $health['version'] ?? $node->version,
                    'capabilities' => $health['capabilities'] ?? $node->capabilities,
                ];

                $manifest = $this->fetchManifest($node);
                if (!empty($manifest)) {
                    $updateData = array_merge($updateData, $this->mapManifestToNodeFields($manifest));
                } else {
                    $updateData = array_merge($updateData, $this->mapLegacyHealthMetadata($health));
                }

                $node->update($updateData);

                Log::channel('ai-engine')->debug('Node metadata synced', [
                    'node_slug' => $node->slug,
                    'synced_fields' => array_keys($updateData),
                ]);

                // Record success in circuit breaker
                $this->circuitBreaker->recordSuccess($node);

                Log::channel('ai-engine')->debug('Node ping successful', [
                    'node_id' => $node->id,
                    'node_slug' => $node->slug,
                    'duration_ms' => $duration,
                ]);
            } else {
                // Record failure in circuit breaker
                $this->circuitBreaker->recordFailure($node);

                Log::channel('ai-engine')->warning('Node ping failed', [
                    'node_id' => $node->id,
                    'node_slug' => $node->slug,
                    'status_code' => $response->status(),
                    'health_status' => $declaredStatus,
                    'health_ready' => $declaredReady,
                    'health_message' => data_get($health, 'checks.remote_node_migrations.message')
                        ?? data_get($health, 'checks.qdrant_connectivity.message'),
                ]);
            }

            return $success;
        } catch (\Exception $e) {
            $node->recordPing(false);
            $this->circuitBreaker->recordFailure($node);

            Log::channel('ai-engine')->error('Node ping exception', [
                'node_id' => $node->id,
                'node_slug' => $node->slug,
                'node_name' => $node->name,
                'node_url' => $node->url,
                'health_url' => $node->getApiUrl('health'),
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return false;
        }
    }

    /**
     * Ping all nodes
     */
    public function pingAll(): array
    {
        $results = [];

        foreach (AINode::all() as $node) {
            $results[$node->slug] = [
                'success' => $this->ping($node),
                'status' => $node->status,
                'is_healthy' => $node->isHealthy(),
            ];
        }

        return $results;
    }

    protected function fetchManifest(AINode $node): ?array
    {
        try {
            $response = NodeHttpClient::makeAuthenticated($node)
                ->get($node->getApiUrl('manifest'));

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Failed to fetch node manifest', [
                'node_slug' => $node->slug,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function mapManifestToNodeFields(array $manifest): array
    {
        return array_filter([
            'description' => data_get($manifest, 'node.description'),
            'capabilities' => $manifest['capabilities'] ?? [],
            'domains' => $manifest['domains'] ?? [],
            'data_types' => $manifest['data_types'] ?? [],
            'keywords' => $manifest['keywords'] ?? [],
            'collections' => $manifest['collections'] ?? [],
            'workflows' => $manifest['workflows'] ?? [],
            'autonomous_collectors' => $manifest['autonomous_collectors'] ?? [],
            'version' => data_get($manifest, 'node.version') ?? ($manifest['version'] ?? null),
        ], static fn ($value) => $value !== null && $value !== []);
    }

    protected function mapLegacyHealthMetadata(array $data): array
    {
        $updateData = [];

        foreach (['description', 'domains', 'data_types', 'keywords', 'collections', 'workflows', 'autonomous_collectors'] as $field) {
            if (!empty($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        return $updateData;
    }

    /**
     * Get node statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => AINode::count(),
            'active' => AINode::active()->count(),
            'inactive' => AINode::where('status', 'inactive')->count(),
            'error' => AINode::where('status', 'error')->count(),
            'healthy' => AINode::healthy()->count(),
            'by_type' => AINode::groupBy('type')
                ->selectRaw('type, count(*) as count')
                ->pluck('count', 'type')
                ->toArray(),
            'avg_response_time' => AINode::active()->avg('avg_response_time'),
        ];
    }

    /**
     * Get nodes with capability
     */
    public function getNodesWithCapability(string $capability): Collection
    {
        return AINode::active()
            ->healthy()
            ->withCapability($capability)
            ->get();
    }

    /**
     * Find node that owns a specific collection/model class
     *
     * @param string $modelClass The model class or collection name
     * @return AINode|null The node that owns this collection, or null if local
     */
    public function findNodeForCollection(string $modelClass): ?AINode
    {
        $cacheKey = 'node_for_collection:' . md5($modelClass);

        return Cache::remember($cacheKey, 300, function () use ($modelClass) {
            $nodes = $this->getActiveNodes();

            foreach ($nodes as $node) {
                if ($this->nodeOwnsCollection($node, $modelClass)) {
                    return $node;
                }
            }

            return null;
        });
    }

    /**
     * Check if a node owns a specific collection
     */
    public function nodeOwnsCollection(AINode $node, string $modelClass): bool
    {
        $collections = $node->collections ?? [];

        if (empty($collections)) {
            return false;
        }

        foreach ($collections as $collection) {
            // Handle new format (array with metadata) or legacy format (class string)
            if (is_array($collection)) {
                $collectionClass = $collection['class'] ?? '';
                $collectionName = $collection['name'] ?? '';

                // Exact class match
                if ($collectionClass === $modelClass) {
                    return true;
                }

                // Name match
                $modelBasename = strtolower(class_basename($modelClass));
                if (strtolower($collectionName) === $modelBasename ||
                    strtolower($collectionName) === $modelBasename . 's' ||
                    strtolower($collectionName) . 's' === $modelBasename) {
                    return true;
                }
            } else {
                // Legacy format: class string
                // Exact match
                if ($collection === $modelClass) {
                    return true;
                }

                // Check by class basename (e.g., "Email" matches "App\Models\Email")
                if (class_basename($collection) === class_basename($modelClass)) {
                    return true;
                }

                // Check by collection name variations
                $modelBasename = strtolower(class_basename($modelClass));
                $collectionLower = strtolower($collection);

                // Singular/plural matching
                if ($collectionLower === $modelBasename ||
                    $collectionLower === $modelBasename . 's' ||
                    $collectionLower . 's' === $modelBasename) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all collections across all nodes
     */
    public function getAllCollections(): array
    {
        $collections = [];

        foreach ($this->getActiveNodes() as $node) {
            $nodeCollections = $node->collections ?? [];
            foreach ($nodeCollections as $collection) {
                $collections[$collection] = [
                    'collection' => $collection,
                    'node_slug' => $node->slug,
                    'node_name' => $node->name,
                ];
            }
        }

        return $collections;
    }

    /**
     * Update node status
     */
    public function updateStatus(AINode $node, string $status): void
    {
        $node->update(['status' => $status]);

        $this->clearCache();

        Log::channel('ai-engine')->info('Node status updated', [
            'node_id' => $node->id,
            'node_slug' => $node->slug,
            'status' => $status,
        ]);
    }

    /**
     * Clear node cache
     */
    protected function clearCache(): void
    {
        Cache::forget('ai_nodes_active');
    }

    /**
     * Get node health report
     */
    public function getHealthReport(AINode $node): array
    {
        return [
            'node' => [
                'id' => $node->id,
                'slug' => $node->slug,
                'name' => $node->name,
                'status' => $node->status,
            ],
            'health' => [
                'is_healthy' => $node->isHealthy(),
                'success_rate' => $node->getSuccessRate(),
                'avg_response_time' => $node->avg_response_time,
                'ping_failures' => $node->ping_failures,
                'last_ping_at' => $node->last_ping_at?->toDateTimeString(),
            ],
            'circuit_breaker' => $this->circuitBreaker->getStatistics($node),
            'load' => [
                'active_connections' => $node->active_connections,
                'load_score' => $node->getLoadScore(),
                'weight' => $node->weight,
            ],
        ];
    }
}
