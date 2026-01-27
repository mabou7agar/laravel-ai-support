<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
        return Cache::remember('ai_nodes_active', 300, function () {
            return AINode::active()->healthy()->get();
        });
    }
    
    /**
     * Get all nodes (including inactive)
     */
    public function getAllNodes(): Collection
    {
        return AINode::all();
    }
    
    /**
     * Get node by slug
     */
    public function getNode(string $slug): ?AINode
    {
        return AINode::where('slug', $slug)->first();
    }
    
    /**
     * Get node by ID
     */
    public function getNodeById(int $id): ?AINode
    {
        return AINode::find($id);
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
            
            $success = $response->successful();
            
            // Record ping result
            $node->recordPing($success, $duration);
            
            if ($success) {
                // Update node metadata from response (auto-discovered from child)
                $data = $response->json();
                $updateData = [
                    'version' => $data['version'] ?? $node->version,
                    'capabilities' => $data['capabilities'] ?? $node->capabilities,
                ];
                
                // Sync auto-discovered metadata if provided
                if (!empty($data['description'])) {
                    $updateData['description'] = $data['description'];
                }
                if (!empty($data['domains'])) {
                    $updateData['domains'] = $data['domains'];
                }
                if (!empty($data['data_types'])) {
                    $updateData['data_types'] = $data['data_types'];
                }
                if (!empty($data['keywords'])) {
                    $updateData['keywords'] = $data['keywords'];
                }
                if (!empty($data['collections'])) {
                    $updateData['collections'] = $data['collections'];
                }
                if (!empty($data['workflows'])) {
                    $updateData['workflows'] = $data['workflows'];
                }
                if (!empty($data['autonomous_collectors'])) {
                    $updateData['autonomous_collectors'] = $data['autonomous_collectors'];
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
