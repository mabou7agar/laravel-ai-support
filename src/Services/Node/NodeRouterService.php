<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Log;

/**
 * Simple Node Router Service
 *
 * Routes requests to the appropriate node based on deterministic ownership,
 * instead of searching all nodes in parallel.
 */
class NodeRouterService
{
    protected NodeRegistryService $registry;
    protected CircuitBreakerService $circuitBreaker;
    protected NodeOwnershipResolver $ownershipResolver;

    public function __construct(
        NodeRegistryService $registry,
        CircuitBreakerService $circuitBreaker,
        NodeOwnershipResolver $ownershipResolver
    ) {
        $this->registry = $registry;
        $this->circuitBreaker = $circuitBreaker;
        $this->ownershipResolver = $ownershipResolver;
    }

    /**
     * Route a query to the appropriate node
     *
     * @param string $query User's query
     * @param array $collections Collections to search (from AI analysis)
     * @param array $options Additional options
     * @return array ['node' => AINode|null, 'is_local' => bool, 'reason' => string]
     */
    public function route(string $query, array $collections = [], array $options = []): array
    {
        if (!empty($collections)) {
            return $this->routeByCollections($collections);
        }

        $targetCollection = $options['target_collection'] ?? $options['target_model'] ?? null;
        if (is_string($targetCollection) && trim($targetCollection) !== '') {
            return $this->routeByCollections([$targetCollection]);
        }

        return [
            'node' => null,
            'is_local' => true,
            'reason' => 'No explicit node ownership match found',
            'collections' => [],
        ];
    }

    /**
     * Route based on specified collections
     */
    protected function routeByCollections(array $collections): array
    {
        $node = $this->ownershipResolver->resolveForCollections($collections);

        if ($node && $node->type === 'child') {
            if (!$this->isNodeAvailable($node)) {
                Log::channel('ai-engine')->warning('Owned node is unavailable, falling back to local', [
                    'collections' => $collections,
                    'node' => $node->slug,
                ]);
            } else {
                return [
                    'node' => $node,
                    'is_local' => false,
                    'reason' => "Collection ownership resolved to node {$node->name}",
                    'collections' => $collections,
                ];
            }
        }

        return [
            'node' => null,
            'is_local' => true,
            'reason' => 'Collections are local or no remote owner exists',
            'collections' => $collections,
        ];
    }

    /**
     * Score how well a node matches a query (debug visibility only).
     */
    protected function scoreNodeForQuery(AINode $node, string $queryLower): int
    {
        $score = 0;

        // Check keywords
        $keywords = $node->keywords ?? [];
        foreach ($keywords as $keyword) {
            if (str_contains($queryLower, strtolower($keyword))) {
                $score += 10;
            }
        }

        // Check domains
        $domains = $node->domains ?? [];
        foreach ($domains as $domain) {
            if (str_contains($queryLower, strtolower($domain))) {
                $score += 5;
            }
        }

        // Check collection names
        $collections = $node->collections ?? [];
        foreach ($collections as $collection) {
            $collectionName = strtolower(class_basename($collection));
            if (str_contains($queryLower, $collectionName)) {
                $score += 15;
            }
        }

        // Check data types
        $dataTypes = $node->data_types ?? [];
        foreach ($dataTypes as $dataType) {
            if (str_contains($queryLower, strtolower($dataType))) {
                $score += 8;
            }
        }

        return $score;
    }

    /**
     * Check if a node is available for requests
     */
    protected function isNodeAvailable(AINode $node): bool
    {
        // Check circuit breaker
        if ($this->circuitBreaker->isOpen($node)) {
            return false;
        }

        // Check if node is healthy
        if (!$node->isHealthy()) {
            return false;
        }

        // Check rate limiting
        if ($node->isRateLimited()) {
            return false;
        }

        return true;
    }

    /**
     * Forward a search request to a specific node
     */
    public function forwardSearch(
        AINode $node,
        string $query,
        array $collections,
        int $limit,
        array $options = [],
        $userId = null
    ): array {
        $startTime = microtime(true);

        try {
            $response = NodeHttpClient::makeForSearch($node)
                ->post($node->getApiUrl('search'), [
                    'query' => $query,
                    'limit' => $limit,
                    'options' => array_merge($options, [
                        'collections' => $collections,
                        'user_id' => $userId,
                    ]),
                ]);

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $this->circuitBreaker->recordSuccess($node);
                $data = $response->json();

                Log::channel('ai-engine')->info('Routed search successful', [
                    'node' => $node->slug,
                    'query' => substr($query, 0, 50),
                    'results_count' => count($data['results'] ?? []),
                    'duration_ms' => $duration,
                ]);

                return [
                    'success' => true,
                    'node' => $node->slug,
                    'node_name' => $node->name,
                    'results' => $data['results'] ?? [],
                    'count' => count($data['results'] ?? []),
                    'duration_ms' => $duration,
                ];
            }

            $this->circuitBreaker->recordFailure($node);

            Log::channel('ai-engine')->warning('Routed search failed', [
                'node' => $node->slug,
                'status' => $response->status(),
            ]);

            return [
                'success' => false,
                'node' => $node->slug,
                'error' => 'HTTP ' . $response->status(),
                'results' => [],
            ];

        } catch (\Exception $e) {
            $this->circuitBreaker->recordFailure($node);

            Log::channel('ai-engine')->error('Routed search exception', [
                'node' => $node->slug,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'node' => $node->slug,
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }
    }

    /**
     * Forward a chat/RAG request to a specific node
     */
    public function forwardChat(
        AINode $node,
        string $message,
        string $sessionId,
        array $options = [],
        $userId = null
    ): array {
        $startTime = microtime(true);

        try {
            // Extract headers from options if provided (from AgentOrchestrator)
            $customHeaders = $options['headers'] ?? [];

            // Default headers
            $defaultHeaders = [
                'X-Forwarded-From-Node' => config('app.name', 'master'),
            ];

            // Merge custom headers with defaults (custom headers take precedence)
            $headers = array_merge($defaultHeaders, $customHeaders);

            $response = NodeHttpClient::makeAuthenticated($node)
                ->withHeaders($headers)
                ->post($node->getApiUrl('chat'), [
                    'message' => $message,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'options' => $options,
                ]);

            $duration = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $this->circuitBreaker->recordSuccess($node);
                $data = $response->json();

                Log::channel('ai-engine')->info('Routed chat successful', [
                    'node' => $node->slug,
                    'duration_ms' => $duration,
                ]);

                return [
                    'success' => true,
                    'node' => $node->slug,
                    'node_name' => $node->name,
                    'response' => $data['response'] ?? '',
                    'metadata' => $data['metadata'] ?? [],
                    'credits_used' => $data['credits_used'] ?? 0,
                    'duration_ms' => $duration,
                ];
            }

            $this->circuitBreaker->recordFailure($node);

            return [
                'success' => false,
                'node' => $node->slug,
                'error' => 'HTTP ' . $response->status(),
            ];

        } catch (\Exception $e) {
            $this->circuitBreaker->recordFailure($node);

            return [
                'success' => false,
                'node' => $node->slug,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get routing decision for logging/debugging
     */
    public function explainRouting(string $query, array $collections = []): array
    {
        $routing = $this->route($query, $collections);

        $nodes = AINode::active()->child()->get();
        $nodeScores = [];

        $queryLower = strtolower($query);
        foreach ($nodes as $node) {
            $nodeScores[$node->slug] = [
                'name' => $node->name,
                'score' => $this->scoreNodeForQuery($node, $queryLower),
                'available' => $this->isNodeAvailable($node),
                'collections' => $node->collections ?? [],
                'keywords' => $node->keywords ?? [],
            ];
        }

        return [
            'query' => $query,
            'collections_requested' => $collections,
            'routing_decision' => $routing,
            'node_scores' => $nodeScores,
        ];
    }
}
