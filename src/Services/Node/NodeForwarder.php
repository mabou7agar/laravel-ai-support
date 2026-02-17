<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Log;

/**
 * Handles all HTTP forwarding to remote nodes (chat, search, actions).
 *
 * Extracted from NodeRouterService so that routing decisions and
 * transport are separate concerns. Adds:
 *
 *  - Configurable retry with exponential backoff
 *  - Failover to alternate nodes that own the same collection
 *  - Unified circuit-breaker integration
 *  - Connection tracking (increment/decrement)
 *
 * Every public method returns a standardised result array:
 *   ['success' => bool, 'node' => slug, 'response'|'results'|'data' => ..., 'duration_ms' => int]
 */
class NodeForwarder
{
    public function __construct(
        protected CircuitBreakerService $circuitBreaker,
        protected NodeRegistryService $registry
    ) {
    }

    // ──────────────────────────────────────────────
    //  Chat forwarding
    // ──────────────────────────────────────────────

    /**
     * Forward a chat/RAG message to a node with retry + failover.
     *
     * @param AINode      $node        Primary target node
     * @param string      $message     User message
     * @param string      $sessionId   Session identifier
     * @param array       $options     Forwarding options (headers, user_token, etc.)
     * @param mixed       $userId      User identifier
     * @param string|null $collection  If provided, enables failover to other nodes owning this collection
     */
    public function forwardChat(
        AINode $node,
        string $message,
        string $sessionId,
        array $options = [],
        $userId = null,
        ?string $collection = null
    ): array {
        $result = $this->attemptForwardChat($node, $message, $sessionId, $options, $userId);

        if ($result['success']) {
            return $result;
        }

        // Retry on the same node (configurable)
        $maxRetries = $this->maxRetries();
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if ($this->circuitBreaker->isOpen($node)) {
                break; // No point retrying if circuit is open
            }

            $backoffMs = $this->backoffMs($attempt);
            Log::channel('ai-engine')->info('NodeForwarder: retrying chat', [
                'node' => $node->slug,
                'attempt' => $attempt + 1,
                'backoff_ms' => $backoffMs,
            ]);

            usleep($backoffMs * 1000);
            $result = $this->attemptForwardChat($node, $message, $sessionId, $options, $userId);

            if ($result['success']) {
                return $result;
            }
        }

        // Failover: try another node that owns the same collection
        if ($collection !== null) {
            $failoverResult = $this->failoverChat($node, $collection, $message, $sessionId, $options, $userId);
            if ($failoverResult !== null) {
                return $failoverResult;
            }
        }

        return $result; // Return last failure
    }

    /**
     * Single attempt to forward chat to a node.
     */
    protected function attemptForwardChat(
        AINode $node,
        string $message,
        string $sessionId,
        array $options,
        $userId
    ): array {
        $startTime = microtime(true);

        try {
            $customHeaders = $options['headers'] ?? [];
            $defaultHeaders = [
                'X-Forwarded-From-Node' => config('app.name', 'master'),
            ];
            $headers = array_merge($defaultHeaders, $customHeaders);

            $node->incrementConnections();

            $response = NodeHttpClient::makeAuthenticated($node)
                ->withHeaders($headers)
                ->post($node->getApiUrl('chat'), [
                    'message' => $message,
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'options' => $options,
                ]);

            $node->decrementConnections();
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $this->circuitBreaker->recordSuccess($node);
                $data = $response->json();

                Log::channel('ai-engine')->info('NodeForwarder: chat forwarded', [
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
                'duration_ms' => $duration,
            ];
        } catch (\Exception $e) {
            $node->decrementConnections();
            $this->circuitBreaker->recordFailure($node);

            return [
                'success' => false,
                'node' => $node->slug,
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * Try forwarding chat to an alternate node that owns the same collection.
     */
    protected function failoverChat(
        AINode $failedNode,
        string $collection,
        string $message,
        string $sessionId,
        array $options,
        $userId
    ): ?array {
        $alternates = $this->findAlternateNodes($failedNode, $collection);

        foreach ($alternates as $altNode) {
            Log::channel('ai-engine')->info('NodeForwarder: failover chat attempt', [
                'failed_node' => $failedNode->slug,
                'failover_node' => $altNode->slug,
                'collection' => $collection,
            ]);

            $result = $this->attemptForwardChat($altNode, $message, $sessionId, $options, $userId);
            if ($result['success']) {
                $result['failover_from'] = $failedNode->slug;
                return $result;
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Search forwarding
    // ──────────────────────────────────────────────

    /**
     * Forward a search request to a node with retry + failover.
     */
    public function forwardSearch(
        AINode $node,
        string $query,
        array $collections,
        int $limit,
        array $options = [],
        $userId = null
    ): array {
        $result = $this->attemptForwardSearch($node, $query, $collections, $limit, $options, $userId);

        if ($result['success']) {
            return $result;
        }

        // Retry
        $maxRetries = $this->maxRetries();
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if ($this->circuitBreaker->isOpen($node)) {
                break;
            }

            usleep($this->backoffMs($attempt) * 1000);
            $result = $this->attemptForwardSearch($node, $query, $collections, $limit, $options, $userId);

            if ($result['success']) {
                return $result;
            }
        }

        // Failover: try another node for the first collection
        $primaryCollection = $collections[0] ?? null;
        if ($primaryCollection !== null) {
            $alternates = $this->findAlternateNodes($node, $primaryCollection);
            foreach ($alternates as $altNode) {
                Log::channel('ai-engine')->info('NodeForwarder: failover search attempt', [
                    'failed_node' => $node->slug,
                    'failover_node' => $altNode->slug,
                ]);

                $altResult = $this->attemptForwardSearch($altNode, $query, $collections, $limit, $options, $userId);
                if ($altResult['success']) {
                    $altResult['failover_from'] = $node->slug;
                    return $altResult;
                }
            }
        }

        return $result;
    }

    /**
     * Single attempt to forward search to a node.
     */
    protected function attemptForwardSearch(
        AINode $node,
        string $query,
        array $collections,
        int $limit,
        array $options,
        $userId
    ): array {
        $startTime = microtime(true);

        try {
            $node->incrementConnections();

            $response = NodeHttpClient::makeForSearch($node)
                ->post($node->getApiUrl('search'), [
                    'query' => $query,
                    'limit' => $limit,
                    'options' => array_merge($options, [
                        'collections' => $collections,
                        'user_id' => $userId,
                    ]),
                ]);

            $node->decrementConnections();
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $this->circuitBreaker->recordSuccess($node);
                $data = $response->json();

                Log::channel('ai-engine')->info('NodeForwarder: search forwarded', [
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

            return [
                'success' => false,
                'node' => $node->slug,
                'error' => 'HTTP ' . $response->status(),
                'results' => [],
                'duration_ms' => $duration,
            ];
        } catch (\Exception $e) {
            $node->decrementConnections();
            $this->circuitBreaker->recordFailure($node);

            return [
                'success' => false,
                'node' => $node->slug,
                'error' => $e->getMessage(),
                'results' => [],
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    // ──────────────────────────────────────────────
    //  Action forwarding
    // ──────────────────────────────────────────────

    /**
     * Forward an action to a node with retry (no failover — actions are node-specific).
     */
    public function forwardAction(
        AINode $node,
        string $action,
        array $params = []
    ): array {
        $result = $this->attemptForwardAction($node, $action, $params);

        if ($result['success']) {
            return $result;
        }

        $maxRetries = $this->maxRetries();
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            if ($this->circuitBreaker->isOpen($node)) {
                break;
            }

            usleep($this->backoffMs($attempt) * 1000);
            $result = $this->attemptForwardAction($node, $action, $params);

            if ($result['success']) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Single attempt to forward an action to a node.
     */
    protected function attemptForwardAction(AINode $node, string $action, array $params): array
    {
        $startTime = microtime(true);

        try {
            $node->incrementConnections();

            $response = NodeHttpClient::makeForAction($node)
                ->post($node->getApiUrl('actions'), [
                    'action_type' => $action,
                    'data' => $params,
                    'session_id' => $params['session_id'] ?? null,
                    'user_id' => $params['user_id'] ?? null,
                ]);

            $node->decrementConnections();
            $duration = (int) ((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $this->circuitBreaker->recordSuccess($node);

                Log::channel('ai-engine')->info('NodeForwarder: action forwarded', [
                    'node' => $node->slug,
                    'action' => $action,
                    'duration_ms' => $duration,
                ]);

                return [
                    'success' => true,
                    'node' => $node->slug,
                    'node_name' => $node->name,
                    'status_code' => $response->status(),
                    'data' => $response->json(),
                    'duration_ms' => $duration,
                ];
            }

            $this->circuitBreaker->recordFailure($node);

            return [
                'success' => false,
                'node' => $node->slug,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body(),
                'duration_ms' => $duration,
            ];
        } catch (\Exception $e) {
            $node->decrementConnections();
            $this->circuitBreaker->recordFailure($node);

            return [
                'success' => false,
                'node' => $node->slug,
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    // ──────────────────────────────────────────────
    //  Node availability
    // ──────────────────────────────────────────────

    /**
     * Check if a node is available for requests.
     */
    public function isAvailable(AINode $node): bool
    {
        if ($this->circuitBreaker->isOpen($node)) {
            return false;
        }

        if (!$node->isHealthy()) {
            return false;
        }

        if ($node->isRateLimited()) {
            return false;
        }

        return true;
    }

    // ──────────────────────────────────────────────
    //  Failover helpers
    // ──────────────────────────────────────────────

    /**
     * Find alternate healthy nodes that own the same collection,
     * excluding the failed node.
     *
     * @return AINode[]
     */
    protected function findAlternateNodes(AINode $excludeNode, string $collection): array
    {
        $allNodes = $this->registry->getActiveNodes();

        return $allNodes->filter(function (AINode $node) use ($excludeNode, $collection) {
            if ($node->id === $excludeNode->id) {
                return false;
            }

            if (!$this->isAvailable($node)) {
                return false;
            }

            return $this->registry->nodeOwnsCollection($node, $collection);
        })->values()->all();
    }

    // ──────────────────────────────────────────────
    //  Config
    // ──────────────────────────────────────────────

    protected function maxRetries(): int
    {
        return max(0, (int) config('ai-engine.nodes.forwarding.max_retries', 1));
    }

    protected function backoffMs(int $attempt): int
    {
        $baseMs = (int) config('ai-engine.nodes.forwarding.backoff_base_ms', 200);
        // Exponential: 200ms, 400ms, 800ms, ...
        return $baseMs * (2 ** ($attempt - 1));
    }
}
