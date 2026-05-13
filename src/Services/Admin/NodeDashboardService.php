<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Admin;

use Illuminate\Support\Collection;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Repositories\AINodeRepository;
use LaravelAIEngine\Services\Node\CircuitBreakerService;
use LaravelAIEngine\Services\Node\NodeCacheService;
use LaravelAIEngine\Services\Node\NodeConnectionPool;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class NodeDashboardService
{
    public function __construct(
        protected NodeRegistryService $registry,
        protected CircuitBreakerService $circuitBreaker,
        protected NodeCacheService $cache,
        protected NodeConnectionPool $connectionPool,
        protected AINodeRepository $nodes
    ) {}

    public function dashboard(bool $includeDetails = false): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'system' => $this->systemInfo(),
            'nodes' => $this->nodesInfo($includeDetails),
            'performance' => $this->performanceInfo(),
            'health' => $this->healthInfo(),
            'circuit_breakers' => $this->circuitBreakerInfo(),
            'cache' => $this->cacheInfo(),
            'connections' => $this->connectionInfo(),
        ];
    }

    public function nodeDetail(string $slug): array
    {
        $node = $this->nodes->findBySlugOrFail($slug);

        return [
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
        ];
    }

    protected function systemInfo(): array
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

    protected function nodesInfo(bool $includeDetails = false): array
    {
        $stats = $this->registry->getStatistics();

        $nodesData = [
            'total' => $stats['total'],
            'active' => $stats['active'],
            'inactive' => $stats['inactive'],
            'error' => $stats['error'],
            'healthy' => $stats['healthy'],
            'by_type' => $stats['by_type'],
        ];

        if ($includeDetails) {
            $nodesData['list'] = $this->nodes->all()->map(function (AINode $node): array {
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

    protected function performanceInfo(): array
    {
        $nodes = $this->nodes->active();
        $responseTimes = $nodes->pluck('avg_response_time')->filter()->values();

        return [
            'avg_response_time' => $responseTimes->avg() ?? 0,
            'min_response_time' => $responseTimes->min() ?? 0,
            'max_response_time' => $responseTimes->max() ?? 0,
            'total_active_connections' => $nodes->sum('active_connections'),
            'requests_per_minute' => 0,
            'cache_hit_rate' => $this->cacheHitRate(),
        ];
    }

    protected function healthInfo(): array
    {
        $nodes = $this->nodes->all();
        $healthyNodes = $nodes->filter(fn (AINode $node): bool => $node->isHealthy());
        $activeNodes = $this->nodes->active();
        $healthScore = $nodes->count() > 0
            ? ($healthyNodes->count() / $nodes->count()) * 100
            : 100;

        return [
            'overall_health' => round($healthScore, 2),
            'status' => $healthScore >= 80 ? 'healthy' : ($healthScore >= 50 ? 'degraded' : 'critical'),
            'healthy_nodes' => $healthyNodes->count(),
            'unhealthy_nodes' => $nodes->count() - $healthyNodes->count(),
            'active_nodes' => $activeNodes->count(),
            'issues' => $this->healthIssues($nodes),
        ];
    }

    protected function healthIssues(Collection $nodes): array
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

    protected function circuitBreakerInfo(): array
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

    protected function cacheInfo(): array
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

    protected function connectionInfo(): array
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

    protected function cacheHitRate(): float
    {
        $stats = $this->cache->getStatistics();
        $hits = $stats['total_hits'] ?? 0;
        $misses = $stats['total_misses'] ?? 0;
        $total = $hits + $misses;

        return $total > 0 ? round(($hits / $total) * 100, 2) : 0;
    }
}
