<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class RemoteActionService
{
    public function __construct(
        protected NodeRegistryService $registry,
        protected CircuitBreakerService $circuitBreaker,
        protected NodeAuthService $authService
    ) {}
    
    /**
     * Execute action on specific node
     */
    public function executeOn(string $nodeSlug, string $action, array $params = []): array
    {
        $node = $this->registry->getNode($nodeSlug);
        
        if (!$node) {
            throw new \Exception("Node not found: {$nodeSlug}");
        }
        
        if (!$node->hasCapability('actions')) {
            throw new \Exception("Node does not support actions: {$nodeSlug}");
        }
        
        // Check circuit breaker
        if ($this->circuitBreaker->isOpen($node)) {
            throw new \Exception("Node is unavailable (circuit breaker open): {$nodeSlug}");
        }
        
        return $this->sendAction($node, $action, $params);
    }
    
    /**
     * Execute action on all nodes
     */
    public function executeOnAll(
        string $action,
        array $params = [],
        bool $parallel = true,
        ?array $nodeIds = null
    ): array {
        $nodes = $this->getActionableNodes($nodeIds);
        
        if ($nodes->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No actionable nodes available',
                'results' => [],
            ];
        }
        
        if ($parallel) {
            return $this->executeParallel($nodes, $action, $params);
        }
        
        return $this->executeSequential($nodes, $action, $params);
    }
    
    /**
     * Execute action on multiple nodes (parallel)
     */
    protected function executeParallel(Collection $nodes, string $action, array $params): array
    {
        $promises = [];
        $traceId = \Str::random(16);
        
        foreach ($nodes as $node) {
            // Check circuit breaker
            if ($this->circuitBreaker->isOpen($node)) {
                Log::channel('ai-engine')->debug('Skipping node - circuit breaker open', [
                    'node_slug' => $node->slug,
                    'action' => $action,
                ]);
                continue;
            }
            
            $node->incrementConnections();
            
            $promises[$node->slug] = [
                'node' => $node,
                'promise' => NodeHttpClient::makeForAction($node, $traceId)
                    ->post($node->getApiUrl('actions'), [
                        'action' => $action,
                        'params' => $params,
                    ])
            ];
        }
        
        // Wait for all responses
        $responses = [];
        foreach ($promises as $slug => $data) {
            try {
                $response = $data['promise']->wait();
                $responses[$slug] = [
                    'node' => $data['node'],
                    'response' => $response,
                ];
            } catch (\Exception $e) {
                $responses[$slug] = [
                    'node' => $data['node'],
                    'error' => $e,
                ];
            }
        }
        
        // Process responses
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        
        foreach ($responses as $slug => $data) {
            $node = $data['node'];
            $node->decrementConnections();
            
            if (isset($data['error'])) {
                $this->circuitBreaker->recordFailure($node);
                
                $results[$slug] = [
                    'node' => $slug,
                    'node_name' => $node->name,
                    'success' => false,
                    'error' => $data['error']->getMessage(),
                ];
                
                $failureCount++;
                continue;
            }
            
            $response = $data['response'];
            
            if ($response->successful()) {
                $this->circuitBreaker->recordSuccess($node);
                
                $results[$slug] = [
                    'node' => $slug,
                    'node_name' => $node->name,
                    'success' => true,
                    'status_code' => $response->status(),
                    'data' => $response->json(),
                ];
                
                $successCount++;
            } else {
                $this->circuitBreaker->recordFailure($node);
                
                $results[$slug] = [
                    'node' => $slug,
                    'node_name' => $node->name,
                    'success' => false,
                    'status_code' => $response->status(),
                    'error' => $response->body(),
                ];
                
                $failureCount++;
            }
        }
        
        return [
            'success' => $failureCount === 0,
            'action' => $action,
            'nodes_executed' => count($results),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results,
        ];
    }
    
    /**
     * Execute action on multiple nodes (sequential)
     */
    protected function executeSequential(Collection $nodes, string $action, array $params): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;
        
        foreach ($nodes as $node) {
            if (!$node->hasCapability('actions')) {
                continue;
            }
            
            // Check circuit breaker
            if ($this->circuitBreaker->isOpen($node)) {
                Log::channel('ai-engine')->debug('Skipping node - circuit breaker open', [
                    'node_slug' => $node->slug,
                ]);
                continue;
            }
            
            try {
                $result = $this->sendAction($node, $action, $params);
                $results[$node->slug] = array_merge($result, ['success' => true]);
                $successCount++;
            } catch (\Exception $e) {
                $results[$node->slug] = [
                    'node' => $node->slug,
                    'node_name' => $node->name,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $failureCount++;
            }
        }
        
        return [
            'success' => $failureCount === 0,
            'action' => $action,
            'nodes_executed' => count($results),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results,
        ];
    }
    
    /**
     * Send action to node
     */
    protected function sendAction(AINode $node, string $action, array $params): array
    {
        $startTime = microtime(true);
        
        $response = NodeHttpClient::makeForAction($node)
            ->post($node->getApiUrl('actions'), [
                'action' => $action,
                'params' => $params,
            ]);
        
        $duration = (int) ((microtime(true) - $startTime) * 1000);
        
        if (!$response->successful()) {
            $this->circuitBreaker->recordFailure($node);
            throw new \Exception("Action failed on node {$node->slug}: " . $response->body());
        }
        
        $this->circuitBreaker->recordSuccess($node);
        
        Log::channel('ai-engine')->info('Action executed successfully', [
            'node' => $node->slug,
            'action' => $action,
            'duration_ms' => $duration,
        ]);
        
        return [
            'node' => $node->slug,
            'node_name' => $node->name,
            'status_code' => $response->status(),
            'data' => $response->json(),
            'duration_ms' => $duration,
        ];
    }
    
    /**
     * Execute transaction across nodes (all-or-nothing)
     */
    public function executeTransaction(array $actions): array
    {
        $results = [];
        $rollbacks = [];
        $executedNodes = [];
        
        try {
            // Execute all actions
            foreach ($actions as $nodeSlug => $actionData) {
                $result = $this->executeOn($nodeSlug, $actionData['action'], $actionData['params']);
                $results[$nodeSlug] = $result;
                $executedNodes[] = $nodeSlug;
                
                // Store rollback action if provided
                if (isset($actionData['rollback'])) {
                    $rollbacks[$nodeSlug] = $actionData['rollback'];
                }
            }
            
            Log::channel('ai-engine')->info('Transaction completed successfully', [
                'nodes' => $executedNodes,
                'action_count' => count($actions),
            ]);
            
            return [
                'success' => true,
                'nodes_executed' => $executedNodes,
                'results' => $results,
            ];
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Transaction failed, rolling back', [
                'error' => $e->getMessage(),
                'executed_nodes' => $executedNodes,
            ]);
            
            // Rollback all successful actions
            foreach ($rollbacks as $nodeSlug => $rollbackAction) {
                if (!in_array($nodeSlug, $executedNodes)) {
                    continue;
                }
                
                try {
                    $this->executeOn($nodeSlug, $rollbackAction['action'], $rollbackAction['params']);
                    
                    Log::channel('ai-engine')->info('Rollback successful', [
                        'node' => $nodeSlug,
                    ]);
                } catch (\Exception $rollbackError) {
                    Log::channel('ai-engine')->error('Rollback failed', [
                        'node' => $nodeSlug,
                        'error' => $rollbackError->getMessage(),
                    ]);
                }
            }
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'nodes_executed' => $executedNodes,
                'partial_results' => $results,
                'rolled_back' => array_keys($rollbacks),
            ];
        }
    }
    
    /**
     * Get actionable nodes
     */
    protected function getActionableNodes(?array $nodeIds): Collection
    {
        $query = AINode::active()->healthy()->child()->withCapability('actions');
        
        if ($nodeIds) {
            $query->whereIn('id', $nodeIds);
        }
        
        return $query->get();
    }
}
