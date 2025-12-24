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
     * Search remote nodes in parallel using HTTP Pool
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

        // Filter nodes by circuit breaker
        $nodesToSearch = [];
        foreach ($selectedNodes as $node) {
            if ($this->circuitBreaker->isOpen($node)) {
                Log::channel('ai-engine')->debug('Skipping node - circuit breaker open', [
                    'node_slug' => $node->slug,
                ]);
                continue;
            }
            $nodesToSearch[$node->slug] = $node;
        }

        if (empty($nodesToSearch)) {
            return [];
        }

        $traceId = \Str::random(16);

        // Increment active connections for all nodes
        foreach ($nodesToSearch as $node) {
            $node->incrementConnections();
        }

        // Use HTTP Pool for TRUE parallel requests
        $verifySSL = config('ai-engine.nodes.verify_ssl', true);
        $timeout = config('ai-engine.nodes.request_timeout', 30);

        $responses = Http::pool(function ($pool) use ($nodesToSearch, $query, $limit, $options, $traceId, $verifySSL, $timeout) {
            foreach ($nodesToSearch as $slug => $node) {
                $request = $pool->as($slug)
                    ->withHeaders(NodeHttpClient::getSearchHeaders($node, $traceId))
                    ->timeout($timeout);

                // Disable SSL verification if configured
                if (!$verifySSL) {
                    $request = $request->withOptions(['verify' => false]);
                }

                $request->post($node->getApiUrl('search'), [
                    'query' => $query,
                    'limit' => $limit,
                    'options' => $options,
                ]);
            }
        });

        // Process responses from HTTP Pool
        $results = [];

        foreach ($responses as $slug => $response) {
            $node = $nodesToSearch[$slug] ?? null;

            if (!$node) {
                continue;
            }

            // Decrement active connections
            $node->decrementConnections();

            // Check if response is an exception (pool returns exceptions for failed requests)
            if ($response instanceof \Exception) {
                $this->circuitBreaker->recordFailure($node);

                Log::channel('ai-engine')->warning('Node search failed', [
                    'node' => $slug,
                    'error' => $response->getMessage(),
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

            if ($response->successful()) {
                $responseData = $response->json();

                \Log::info('Master: Raw HTTP response received', [
                    'node' => $slug,
                    'status' => $response->status(),
                    'body_length' => strlen($response->body()),
                    'body_preview' => substr($response->body(), 0, 1000),
                ]);

                \Log::info('Master: Decoded JSON response', [
                    'node' => $slug,
                    'response_data' => $responseData,
                    'results_key_exists' => isset($responseData['results']),
                    'results_is_array' => is_array($responseData['results'] ?? null),
                    'results_count' => count($responseData['results'] ?? []),
                    'count_key_value' => $responseData['count'] ?? 'not set',
                ]);

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

        \Log::info('mergeResults called', [
            'local_count' => count($local['results'] ?? []),
            'remote_nodes_count' => count($remote),
            'limit' => $limit,
        ]);

        // Add local results
        foreach ($local['results'] as $result) {
            $allResults[] = array_merge($result, [
                'source_node' => 'master',
                'source_node_name' => 'Master Node',
            ]);
        }

        // Add remote results
        $remoteResultsCount = 0;
        foreach ($remote as $nodeResults) {
            $nodeResultCount = count($nodeResults['results'] ?? []);
            $remoteResultsCount += $nodeResultCount;

            \Log::info('Adding remote node results', [
                'node' => $nodeResults['node'] ?? 'unknown',
                'node_name' => $nodeResults['node_name'] ?? 'unknown',
                'results_count' => $nodeResultCount,
            ]);

            foreach ($nodeResults['results'] as $result) {
                $allResults[] = array_merge($result, [
                    'source_node' => $nodeResults['node'],
                    'source_node_name' => $nodeResults['node_name'],
                ]);
            }
        }

        \Log::info('After adding all results', [
            'total_before_sort' => count($allResults),
            'local_count' => count($local['results'] ?? []),
            'remote_count' => $remoteResultsCount,
        ]);

        // Sort by score (descending)
        usort($allResults, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        // Deduplicate by content hash
        $beforeDedup = count($allResults);
        $allResults = $this->deduplicateResults($allResults);

        \Log::info('After deduplication', [
            'before' => $beforeDedup,
            'after' => count($allResults),
        ]);

        // Limit results
        $allResults = array_slice($allResults, 0, $limit);

        \Log::info('mergeResults final', [
            'final_count' => count($allResults),
            'first_result_content_preview' => isset($allResults[0]['content']) ? substr($allResults[0]['content'], 0, 100) : 'no content',
        ]);

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
     * Get aggregate data from all nodes (local + remote)
     */
    public function getAggregateData(array $collections, $userId = null): array
    {
        $startTime = microtime(true);
        $aggregateData = [];
        
        try {
            // Get nodes to query (pass null to get all active nodes)
            $nodes = $this->getSearchableNodes(null);
            
            // Get local aggregate data first
            $localAggregate = $this->getLocalAggregateData($collections, $userId);
            $aggregateData = $localAggregate;
            
            // Get remote aggregate data from each node
            foreach ($nodes as $node) {
                try {
                    $response = Http::timeout(5)
                        ->withToken($node->api_key)
                        ->post($node->url . '/api/ai-engine/aggregate', [
                            'collections' => $collections,
                            'user_id' => $userId,
                        ]);
                    
                    if ($response->successful()) {
                        $responseData = $response->json();
                        $remoteData = $responseData['aggregate_data'] ?? [];
                        
                        // Merge remote data with local data
                        foreach ($remoteData as $collection => $stats) {
                            if (!isset($aggregateData[$collection])) {
                                // New collection from remote node
                                $aggregateData[$collection] = $stats;
                                $aggregateData[$collection]['source'] = 'remote_node';
                                $aggregateData[$collection]['node'] = $node->name;
                            } else {
                                // Collection exists locally, sum the counts
                                $aggregateData[$collection]['count'] += $stats['count'] ?? 0;
                                $aggregateData[$collection]['indexed_count'] += $stats['indexed_count'] ?? 0;
                                $aggregateData[$collection]['source'] = 'federated';
                            }
                        }
                        
                        Log::channel('ai-engine')->info('Fetched aggregate from remote node', [
                            'node' => $node->name,
                            'collections_count' => count($remoteData),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->warning('Failed to get aggregate from node', [
                        'node' => $node->name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            Log::channel('ai-engine')->info('Federated aggregate completed', [
                'collections_count' => count($aggregateData),
                'duration_ms' => round($duration, 2),
            ]);
            
            return $aggregateData;
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Federated aggregate failed', [
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to local only
            return $this->getLocalAggregateData($collections, $userId);
        }
    }
    
    /**
     * Get local aggregate data only
     */
    protected function getLocalAggregateData(array $collections, $userId = null): array
    {
        $aggregateData = [];
        
        foreach ($collections as $collection) {
            if (!class_exists($collection)) {
                continue;
            }
            
            try {
                $instance = new $collection();
                $displayName = class_basename($collection);
                $description = '';
                
                // Get display name and description
                if (method_exists($instance, 'getRAGDisplayName')) {
                    $displayName = $instance->getRAGDisplayName();
                }
                if (method_exists($instance, 'getRAGDescription')) {
                    $description = $instance->getRAGDescription();
                }
                
                // Build filters for vector database query
                $filters = [];
                if ($userId !== null) {
                    $filters['user_id'] = $userId;
                }
                
                // Get count from vector database
                $vectorCount = $this->localSearch->getIndexedCountWithFilters($collection, $filters);
                
                $aggregateData[$collection] = [
                    'count' => $vectorCount,
                    'indexed_count' => $vectorCount,
                    'display_name' => $displayName,
                    'description' => $description,
                    'source' => 'local',
                ];
                
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to get local aggregate for collection', [
                    'collection' => $collection,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $aggregateData;
    }

    /**
     * Generate cache key
     */
    protected function generateCacheKey(string $query, ?array $nodeIds, array $options): string
    {
        return md5($query . json_encode($nodeIds) . json_encode($options));
    }
}
