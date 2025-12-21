<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class FederatedSearchService
{
    public function __construct(
        protected NodeRegistryService $registry,
        protected VectorSearchService $localSearch,
        protected NodeCacheService $cache,
        protected CircuitBreakerService $circuitBreaker,
        protected LoadBalancerService $loadBalancer
    ) {}
    
    /**
     * Search across all nodes (federated search)
     */
    public function search(
        string $query,
        ?array $nodeIds = null,
        int $limit = 10,
        array $options = [],
        $userId = null
    ): array {
        $startTime = microtime(true);
        
        // Add userId to options if provided
        if ($userId !== null) {
            $options['user_id'] = $userId;
        }
        
        // Generate cache key
        $cacheKey = $this->generateCacheKey($query, $nodeIds, $options);
        
        // Check cache
        if ($cached = $this->cache->getCachedSearch($query, $nodeIds ?? [], $options)) {
            Log::channel('ai-engine')->debug('Federated search cache hit', [
                'query' => substr($query, 0, 100),
            ]);
            return $cached;
        }
        
        try {
            // Get nodes to search
            $nodes = $this->getSearchableNodes($nodeIds);
            
            // Search local node first
            $localResults = $this->searchLocal($query, $limit, $options);
            
            // Search remote nodes in parallel
            $remoteResults = $this->searchRemoteNodes($nodes, $query, $limit, $options);
            
            \Log::debug('🔍 Remote search results', [
                'remote_count' => count($remoteResults),
                'remote_results' => $remoteResults,
            ]);
            
            // Merge and rank results
            $mergedResults = $this->mergeResults($localResults, $remoteResults, $limit, $query);
            
            $duration = (int) ((microtime(true) - $startTime) * 1000);
            
            // Cache results
            $this->cache->cacheSearch($query, $nodeIds ?? [], $mergedResults, $duration, $options);
            
            return $mergedResults;
            
        } catch (\Exception $e) {
            \Log::error('🚨 Federated search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Fallback to local search only
            return $this->fallbackToLocal($query, $limit, $options);
        }
    }
    
    /**
     * Search local node
     */
    protected function searchLocal(string $query, int $limit, array $options): array
    {
        try {
            $collections = $options['collections'] ?? [];
            $userId = $options['user_id'] ?? null;
            $results = [];
            
            if (empty($collections)) {
                Log::channel('ai-engine')->debug('No collections specified for local search');
                return [
                    'node' => 'master',
                    'node_name' => 'Master Node',
                    'results' => [],
                    'count' => 0,
                ];
            }
            
            foreach ($collections as $collection) {
                if (!class_exists($collection)) {
                    continue;
                }
                
                try {
                    $searchResults = $this->localSearch->search(
                        $collection,
                        $query,
                        $limit,
                        $options['threshold'] ?? 0.3,
                        $options['filters'] ?? [],
                        $userId
                    );
                    
                    foreach ($searchResults as $result) {
                        $results[] = [
                            'id' => $result->id ?? null,
                            'content' => $this->extractContent($result),
                            'score' => $result->vector_score ?? 0,
                            'model_class' => $collection,
                            'model_type' => class_basename($collection),
                            // Include metadata for enrichResponseWithSources
                            'metadata' => [
                                'model_class' => $collection,
                                'model_type' => class_basename($collection),
                                'model_id' => $result->id ?? null,
                            ],
                            'vector_metadata' => $result->vector_metadata ?? [
                                'model_class' => $collection,
                                'model_type' => class_basename($collection),
                            ],
                            // Include additional display fields
                            'title' => $result->title ?? $result->name ?? $result->subject ?? null,
                            'name' => $result->name ?? null,
                            'body' => $result->body ?? null,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->warning('Local search failed for collection', [
                        'collection' => $collection,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return [
                'node' => 'master',
                'node_name' => 'Master Node',
                'results' => $results,
                'count' => count($results),
            ];
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Local search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'node' => 'master',
                'node_name' => 'Master Node',
                'results' => [],
                'count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Search remote nodes in parallel
     */
    protected function searchRemoteNodes(
        Collection $nodes,
        string $query,
        int $limit,
        array $options
    ): array {
        if ($nodes->isEmpty()) {
            return [];
        }
        
        // Use load balancer to select best nodes
        $selectedNodes = $this->loadBalancer->selectNodes(
            $nodes,
            $options['max_nodes'] ?? null,
            $options['load_balance_strategy'] ?? LoadBalancerService::STRATEGY_RESPONSE_TIME
        );
        
        $promises = [];
        $traceId = \Str::random(16);
        
        foreach ($selectedNodes as $node) {
            // Check circuit breaker
            if ($this->circuitBreaker->isOpen($node)) {
                Log::channel('ai-engine')->debug('Skipping node - circuit breaker open', [
                    'node_slug' => $node->slug,
                ]);
                continue;
            }
            
            // Increment active connections
            $node->incrementConnections();
            
            try {
                $response = NodeHttpClient::makeForSearch($node, $traceId)
                    ->post($node->getApiUrl('search'), [
                        'query' => $query,
                        'limit' => $limit,
                        'options' => $options,
                    ]);
                    
                $promises[$node->slug] = [
                    'node' => $node,
                    'response' => $response,
                ];
            } catch (\Exception $e) {
                $promises[$node->slug] = [
                    'node' => $node,
                    'error' => $e,
                ];
            }
        }
        
        // Process responses (now synchronous)
        $responses = $promises;
        
        // Process responses
        $results = [];
        
        foreach ($responses as $slug => $data) {
            $node = $data['node'];
            
            // Decrement active connections
            $node->decrementConnections();
            
            if (isset($data['error'])) {
                $this->circuitBreaker->recordFailure($node);
                
                Log::channel('ai-engine')->warning('Node search failed', [
                    'node' => $slug,
                    'error' => $data['error']->getMessage(),
                ]);
                
                $results[] = [
                    'node' => $slug,
                    'node_name' => $node->name,
                    'results' => [],
                    'count' => 0,
                    'error' => 'Request failed',
                ];
                
                continue;
            }
            
            $response = $data['response'];
            
            if ($response->successful()) {
                $responseData = $response->json();
                
                $results[] = [
                    'node' => $slug,
                    'node_name' => $node->name,
                    'results' => $responseData['results'] ?? [],
                    'count' => count($responseData['results'] ?? []),
                    'duration_ms' => $responseData['duration_ms'] ?? 0,
                ];
                
                // Record success
                $this->circuitBreaker->recordSuccess($node);
                
                Log::channel('ai-engine')->debug('Node search successful', [
                    'node' => $slug,
                    'count' => count($responseData['results'] ?? []),
                    'response_data' => $responseData,
                ]);
            } else {
                $this->circuitBreaker->recordFailure($node);
                
                Log::channel('ai-engine')->warning('Node search failed', [
                    'node' => $slug,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                
                $results[] = [
                    'node' => $slug,
                    'node_name' => $node->name,
                    'results' => [],
                    'count' => 0,
                    'error' => 'Request failed: HTTP ' . $response->status(),
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Merge and rank results from all nodes
     */
    protected function mergeResults(array $local, array $remote, int $limit, string $query): array
    {
        $allResults = [];
        
        // Debug: Log first local result
        if (!empty($local['results'])) {
            \Log::info('Local result before merge', [
                'keys' => array_keys($local['results'][0]),
                'has_metadata' => isset($local['results'][0]['metadata']),
                'metadata' => $local['results'][0]['metadata'] ?? 'not set',
            ]);
        }
        
        // Add local results
        foreach ($local['results'] as $result) {
            $allResults[] = array_merge($result, [
                'source_node' => 'master',
                'source_node_name' => 'Master Node',
            ]);
        }
        
        // Add remote results
        foreach ($remote as $nodeResults) {
            foreach ($nodeResults['results'] as $result) {
                $allResults[] = array_merge($result, [
                    'source_node' => $nodeResults['node'],
                    'source_node_name' => $nodeResults['node_name'],
                ]);
            }
        }
        
        // Sort by score (descending)
        usort($allResults, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        
        // Deduplicate by content hash
        $allResults = $this->deduplicateResults($allResults);
        
        // Limit results
        $allResults = array_slice($allResults, 0, $limit);
        
        return [
            'query' => $query,
            'total_results' => count($allResults),
            'results' => $allResults,
            'nodes_searched' => count($remote) + 1,
            'node_breakdown' => $this->getNodeBreakdown($allResults),
        ];
    }
    
    /**
     * Deduplicate results by content similarity
     */
    protected function deduplicateResults(array $results): array
    {
        $unique = [];
        $hashes = [];
        
        foreach ($results as $result) {
            $content = $result['content'] ?? '';
            $hash = md5($content);
            
            if (!in_array($hash, $hashes)) {
                $unique[] = $result;
                $hashes[] = $hash;
            }
        }
        
        return $unique;
    }
    
    /**
     * Get node breakdown
     */
    protected function getNodeBreakdown(array $results): array
    {
        $breakdown = [];
        
        foreach ($results as $result) {
            $node = $result['source_node'] ?? 'unknown';
            $breakdown[$node] = ($breakdown[$node] ?? 0) + 1;
        }
        
        return $breakdown;
    }
    
    /**
     * Get searchable nodes
     */
    protected function getSearchableNodes(?array $nodeIds): Collection
    {
        $query = AINode::active()->healthy()->child();
        
        if ($nodeIds) {
            $query->whereIn('id', $nodeIds);
        }
        
        return $query->get();
    }
    
    /**
     * Fallback to local search only
     */
    protected function fallbackToLocal(string $query, int $limit, array $options): array
    {
        Log::channel('ai-engine')->warning('Falling back to local search only');
        
        $localResults = $this->searchLocal($query, $limit, $options);
        
        return [
            'query' => $query,
            'total_results' => $localResults['count'],
            'results' => $localResults['results'],
            'nodes_searched' => 1,
            'node_breakdown' => ['master' => $localResults['count']],
            'fallback' => true,
        ];
    }
    
    /**
     * Extract content from result
     */
    protected function extractContent($model): string
    {
        if (method_exists($model, 'getVectorContent')) {
            return $model->getVectorContent();
        }
        
        $fields = ['content', 'body', 'description', 'text', 'title', 'name'];
        $content = [];
        
        foreach ($fields as $field) {
            if (isset($model->$field)) {
                $content[] = $model->$field;
            }
        }
        
        return implode(' ', $content);
    }
    
    /**
     * Generate cache key
     */
    protected function generateCacheKey(string $query, ?array $nodeIds, array $options): string
    {
        return md5($query . json_encode($nodeIds) . json_encode($options));
    }
}
