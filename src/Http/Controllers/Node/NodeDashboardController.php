<?php

namespace LaravelAIEngine\Http\Controllers\Node;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\CircuitBreakerService;
use LaravelAIEngine\Services\Node\NodeCacheService;
use LaravelAIEngine\Services\Node\NodeConnectionPool;
use LaravelAIEngine\Models\AINode;

class NodeDashboardController extends Controller
{
    public function __construct(
        protected NodeRegistryService $registry,
        protected CircuitBreakerService $circuitBreaker,
        protected NodeCacheService $cache,
        protected NodeConnectionPool $connectionPool
    ) {}
    
    /**
     * Get comprehensive dashboard data
     */
    public function index(Request $request)
    {
        $includeDetails = $request->boolean('details', false);
        
        $dashboard = [
            'timestamp' => now()->toIso8601String(),
            'system' => $this->getSystemInfo(),
            'nodes' => $this->getNodesInfo($includeDetails),
            'performance' => $this->getPerformanceInfo(),
            'health' => $this->getHealthInfo(),
            'circuit_breakers' => $this->getCircuitBreakerInfo(),
            'cache' => $this->getCacheInfo(),
            'connections' => $this->getConnectionInfo(),
        ];
        
        return response()->json($dashboard);
    }
    
    /**
     * Get system information
     */
    protected function getSystemInfo(): array
    {
        return [
            'is_master' => config('ai-engine.nodes.is_master', true),
            'master_url' => config('ai-engine.nodes.master_url'),
            'version' => config('ai-engine.version', '1.0.0'),
            'environment' => app()->environment(),
            'nodes_enabled' => config('ai-engine.nodes.enabled', true),
            'capabilities' => config('ai-engine.nodes.capabilities', []),
        ];
    }
    
    /**
     * Get nodes information
     */
    protected function getNodesInfo(bool $includeDetails = false): array
    {
        $stats = $this->registry->getStatistics();
        $nodes = AINode::all();
        
        $nodesData = [
            'total' => $stats['total'],
            'active' => $stats['active'],
            'inactive' => $stats['inactive'],
            'error' => $stats['error'],
            'healthy' => $stats['healthy'],
            'by_type' => $stats['by_type'],
        ];
        
        if ($includeDetails) {
            $nodesData['list'] = $nodes->map(function ($node) {
                return [
                    'id' => $node->id,
                    'slug' => $node->slug,
                    'name' => $node->name,
                    'type' => $node->type,
                    'status' => $node->status,
                    'is_healthy' => $node->isHealthy(),
                    'capabilities' => $node->capabilities,
                    'avg_response_time' => $node->avg_response_time,
                    'success_rate' => $node->getSuccessRate(),
                    'active_connections' => $node->active_connections,
                    'load_score' => $node->getLoadScore(),
                    'last_ping_at' => $node->last_ping_at?->toIso8601String(),
                    'is_rate_limited' => $node->isRateLimited(),
                    'remaining_rate_limit' => $node->remainingRateLimitAttempts(),
                ];
            })->values();
        }
        
        return $nodesData;
    }
    
    /**
     * Get performance information
     */
    protected function getPerformanceInfo(): array
    {
        $nodes = AINode::active()->get();
        $responseTimes = $nodes->pluck('avg_response_time')->filter()->values();
        
        return [
            'avg_response_time' => $responseTimes->avg() ?? 0,
            'min_response_time' => $responseTimes->min() ?? 0,
            'max_response_time' => $responseTimes->max() ?? 0,
            'total_active_connections' => $nodes->sum('active_connections'),
            'requests_per_minute' => $this->getRequestsPerMinute(),
            'cache_hit_rate' => $this->getCacheHitRate(),
        ];
    }
    
    /**
     * Get health information
     */
    protected function getHealthInfo(): array
    {
        $nodes = AINode::all();
        $healthyNodes = $nodes->filter(fn($n) => $n->isHealthy());
        $activeNodes = AINode::active()->get();
        
        $healthScore = $nodes->count() > 0 
            ? ($healthyNodes->count() / $nodes->count()) * 100 
            : 100;
        
        return [
            'overall_health' => round($healthScore, 2),
            'status' => $healthScore >= 80 ? 'healthy' : ($healthScore >= 50 ? 'degraded' : 'critical'),
            'healthy_nodes' => $healthyNodes->count(),
            'unhealthy_nodes' => $nodes->count() - $healthyNodes->count(),
            'active_nodes' => $activeNodes->count(),
            'issues' => $this->getHealthIssues($nodes),
        ];
    }
    
    /**
     * Get health issues
     */
    protected function getHealthIssues($nodes): array
    {
        $issues = [];
        
        foreach ($nodes as $node) {
            if (!$node->isHealthy()) {
                $issues[] = [
                    'node' => $node->slug,
                    'type' => 'unhealthy',
                    'message' => "Node {$node->name} is unhealthy",
                    'details' => [
                        'status' => $node->status,
                        'ping_failures' => $node->ping_failures,
                        'last_ping' => $node->last_ping_at?->diffForHumans(),
                    ],
                ];
            }
            
            if ($node->isRateLimited()) {
                $issues[] = [
                    'node' => $node->slug,
                    'type' => 'rate_limited',
                    'message' => "Node {$node->name} is rate limited",
                    'details' => [
                        'remaining_attempts' => $node->remainingRateLimitAttempts(),
                    ],
                ];
            }
        }
        
        return $issues;
    }
    
    /**
     * Get circuit breaker information
     */
    protected function getCircuitBreakerInfo(): array
    {
        $openCircuits = $this->circuitBreaker->getOpenCircuits();
        $halfOpenCircuits = $this->circuitBreaker->getHalfOpenCircuits();
        
        return [
            'open_circuits' => $openCircuits->count(),
            'half_open_circuits' => $halfOpenCircuits->count(),
            'open_circuit_nodes' => $openCircuits->pluck('slug')->values(),
            'half_open_circuit_nodes' => $halfOpenCircuits->pluck('slug')->values(),
        ];
    }
    
    /**
     * Get cache information
     */
    protected function getCacheInfo(): array
    {
        $stats = $this->cache->getStatistics();
        
        return [
            'enabled' => config('ai-engine.nodes.cache.enabled', true),
            'driver' => config('ai-engine.nodes.cache.driver'),
            'total_entries' => $stats['total_entries'] ?? 0,
            'total_hits' => $stats['total_hits'] ?? 0,
            'total_misses' => $stats['total_misses'] ?? 0,
            'hit_rate' => $stats['hit_rate'] ?? 0,
            'avg_response_time' => $stats['avg_response_time'] ?? 0,
        ];
    }
    
    /**
     * Get connection pool information
     */
    protected function getConnectionInfo(): array
    {
        $stats = $this->connectionPool->getStatistics();
        
        return [
            'enabled' => config('ai-engine.nodes.connection_pool.enabled', true),
            'max_per_node' => config('ai-engine.nodes.connection_pool.max_per_node', 5),
            'ttl' => config('ai-engine.nodes.connection_pool.ttl', 300),
            'total_connections' => $stats['total_connections'],
            'by_node' => $stats['by_node'],
            'by_type' => $stats['by_type'],
        ];
    }
    
    /**
     * Get requests per minute
     */
    protected function getRequestsPerMinute(): int
    {
        // This would need to be tracked in a separate metrics system
        // For now, return 0 as placeholder
        return 0;
    }
    
    /**
     * Get cache hit rate
     */
    protected function getCacheHitRate(): float
    {
        $stats = $this->cache->getStatistics();
        $hits = $stats['total_hits'] ?? 0;
        $misses = $stats['total_misses'] ?? 0;
        $total = $hits + $misses;
        
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0;
    }
    
    /**
     * Get node details
     */
    public function node(Request $request, string $slug)
    {
        $node = AINode::where('slug', $slug)->firstOrFail();
        
        return response()->json([
            'node' => [
                'id' => $node->id,
                'slug' => $node->slug,
                'name' => $node->name,
                'type' => $node->type,
                'url' => $node->url,
                'status' => $node->status,
                'version' => $node->version,
                'capabilities' => $node->capabilities,
                'collections' => $node->collections,
                'metadata' => $node->metadata,
            ],
            'health' => $this->registry->getHealthReport($node),
            'circuit_breaker' => $this->circuitBreaker->getStatistics($node),
            'rate_limit' => [
                'is_limited' => $node->isRateLimited(),
                'remaining_attempts' => $node->remainingRateLimitAttempts(),
            ],
        ]);
    }
    
    /**
     * Get metrics over time
     */
    public function metrics(Request $request)
    {
        $period = $request->input('period', '1h'); // 1h, 24h, 7d, 30d
        
        // This would need a proper metrics storage system
        // For now, return current snapshot
        return response()->json([
            'period' => $period,
            'metrics' => [
                'response_times' => [],
                'request_counts' => [],
                'error_rates' => [],
                'cache_hit_rates' => [],
            ],
            'note' => 'Historical metrics require a metrics storage system',
        ]);
    }
}
