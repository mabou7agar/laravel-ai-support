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
        protected NodeForwarder $forwarder
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
        
        return $this->forwarder->forwardAction($node, $action, $params);
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
     * Execute action on multiple nodes (parallel via sequential NodeForwarder calls).
     *
     * True HTTP-level parallelism is not used here because NodeForwarder
     * handles retry/circuit-breaker per call. For high-throughput scenarios,
     * consider Laravel's HTTP pool at the NodeForwarder level.
     */
    protected function executeParallel(Collection $nodes, string $action, array $params): array
    {
        // Delegate to sequential — NodeForwarder already handles retry/CB per call.
        // True async can be added inside NodeForwarder later without changing this API.
        return $this->executeSequential($nodes, $action, $params);
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

            if (!$this->forwarder->isAvailable($node)) {
                Log::channel('ai-engine')->debug('Skipping node - unavailable', [
                    'node_slug' => $node->slug,
                ]);
                continue;
            }

            $result = $this->forwarder->forwardAction($node, $action, $params);
            $results[$node->slug] = $result;

            if ($result['success'] ?? false) {
                $successCount++;
            } else {
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
     * Execute transaction across nodes (all-or-nothing)
     */
    public function executeTransaction(array $actions): array
    {
        $results = [];
        $rollbacks = [];
        $executedNodes = [];

        try {
            foreach ($actions as $nodeSlug => $actionData) {
                $node = $this->registry->getNode($nodeSlug);
                if (!$node) {
                    throw new \Exception("Node not found: {$nodeSlug}");
                }

                $result = $this->forwarder->forwardAction($node, $actionData['action'], $actionData['params']);

                if (!($result['success'] ?? false)) {
                    throw new \Exception("Action failed on node {$nodeSlug}: " . ($result['error'] ?? 'unknown'));
                }

                $results[$nodeSlug] = $result;
                $executedNodes[] = $nodeSlug;

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

            foreach ($rollbacks as $nodeSlug => $rollbackAction) {
                if (!in_array($nodeSlug, $executedNodes)) {
                    continue;
                }

                try {
                    $rollbackNode = $this->registry->getNode($nodeSlug);
                    if ($rollbackNode) {
                        $this->forwarder->forwardAction($rollbackNode, $rollbackAction['action'], $rollbackAction['params']);
                    }

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
