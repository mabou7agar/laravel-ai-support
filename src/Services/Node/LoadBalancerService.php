<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Models\AINodeRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LoadBalancerService
{
    const STRATEGY_ROUND_ROBIN = 'round_robin';
    const STRATEGY_LEAST_CONNECTIONS = 'least_connections';
    const STRATEGY_WEIGHTED = 'weighted';
    const STRATEGY_RESPONSE_TIME = 'response_time';
    const STRATEGY_RANDOM = 'random';
    
    /**
     * Select nodes based on load balancing strategy
     */
    public function selectNodes(
        Collection $availableNodes,
        ?int $count = null,
        string $strategy = self::STRATEGY_RESPONSE_TIME
    ): Collection {
        if ($availableNodes->isEmpty()) {
            return collect();
        }
        
        $selected = match($strategy) {
            self::STRATEGY_ROUND_ROBIN => $this->roundRobin($availableNodes, $count),
            self::STRATEGY_LEAST_CONNECTIONS => $this->leastConnections($availableNodes, $count),
            self::STRATEGY_WEIGHTED => $this->weighted($availableNodes, $count),
            self::STRATEGY_RESPONSE_TIME => $this->fastestResponseTime($availableNodes, $count),
            self::STRATEGY_RANDOM => $this->random($availableNodes, $count),
            default => $availableNodes,
        };
        
        return $count ? $selected->take($count) : $selected;
    }
    
    /**
     * Select single best node
     */
    public function selectBestNode(Collection $availableNodes, string $strategy = self::STRATEGY_RESPONSE_TIME): ?AINode
    {
        $selected = $this->selectNodes($availableNodes, 1, $strategy);
        return $selected->first();
    }
    
    /**
     * Round-robin strategy
     */
    protected function roundRobin(Collection $nodes, ?int $count): Collection
    {
        $key = 'load_balancer:round_robin:index';
        $index = Cache::get($key, 0);
        
        // Rotate nodes
        $rotated = $nodes->slice($index)->concat($nodes->slice(0, $index));
        
        // Update index for next call
        Cache::put($key, ($index + ($count ?? 1)) % $nodes->count(), 3600);
        
        return $rotated;
    }
    
    /**
     * Least connections strategy
     */
    protected function leastConnections(Collection $nodes, ?int $count): Collection
    {
        return $nodes->sortBy('active_connections');
    }
    
    /**
     * Weighted strategy (based on node weight)
     */
    protected function weighted(Collection $nodes, ?int $count): Collection
    {
        return $nodes->sortByDesc('weight');
    }
    
    /**
     * Fastest response time strategy
     */
    protected function fastestResponseTime(Collection $nodes, ?int $count): Collection
    {
        // Calculate load score for each node
        $nodesWithScore = $nodes->map(function ($node) {
            $node->load_score = $node->getLoadScore();
            return $node;
        });
        
        // Sort by load score (lower is better)
        return $nodesWithScore->sortBy('load_score');
    }
    
    /**
     * Random strategy
     */
    protected function random(Collection $nodes, ?int $count): Collection
    {
        return $nodes->shuffle();
    }
    
    /**
     * Get node with least load
     */
    public function getLeastLoadedNode(Collection $nodes): ?AINode
    {
        if ($nodes->isEmpty()) {
            return null;
        }
        
        return $nodes->sortBy(function ($node) {
            return $node->getLoadScore();
        })->first();
    }
    
    /**
     * Distribute load across nodes
     */
    public function distributeLoad(Collection $nodes, int $totalRequests): array
    {
        if ($nodes->isEmpty()) {
            return [];
        }
        
        $totalWeight = $nodes->sum('weight');
        $distribution = [];
        
        foreach ($nodes as $node) {
            $weight = $node->weight ?? 1;
            $allocation = (int) round(($weight / $totalWeight) * $totalRequests);
            
            $distribution[$node->slug] = [
                'node_id' => $node->id,
                'node_name' => $node->name,
                'weight' => $weight,
                'allocated_requests' => $allocation,
                'percentage' => round(($weight / $totalWeight) * 100, 2),
            ];
        }
        
        return $distribution;
    }
    
    /**
     * Get load balancing statistics
     */
    public function getStatistics(Collection $nodes): array
    {
        $stats = [];
        
        foreach ($nodes as $node) {
            $stats[$node->slug] = [
                'node_id' => $node->id,
                'node_name' => $node->name,
                'active_connections' => $node->active_connections,
                'avg_response_time' => $node->avg_response_time,
                'load_score' => $node->getLoadScore(),
                'weight' => $node->weight,
                'is_healthy' => $node->isHealthy(),
                'success_rate' => $node->getSuccessRate(),
            ];
        }
        
        return $stats;
    }
}
