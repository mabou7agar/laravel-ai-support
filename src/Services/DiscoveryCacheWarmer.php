<?php

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

/**
 * Discovery Cache Warmer
 * 
 * Pre-populates discovery caches on application boot to eliminate
 * redundant discovery calls during requests.
 */
class DiscoveryCacheWarmer
{
    public function __construct(
        protected AutonomousCollectorDiscoveryService $collectorDiscovery,
        protected RAGCollectionDiscovery $ragDiscovery
    ) {}

    /**
     * Warm all discovery caches
     * 
     * @param bool $force Force re-discovery even if cache exists
     * @return array Statistics about what was cached
     */
    public function warmAll(bool $force = false): array
    {
        $stats = [
            'autonomous_collectors' => 0,
            'rag_collections' => 0,
            'duration_ms' => 0,
        ];

        $startTime = microtime(true);

        try {
            // Warm autonomous collectors cache
            $collectors = $this->collectorDiscovery->discoverCollectors(
                useCache: !$force,
                includeRemote: true
            );
            $stats['autonomous_collectors'] = count($collectors);

            // Warm RAG collections cache
            $collections = $this->ragDiscovery->discover(
                useCache: !$force,
                includeFederated: true
            );
            $stats['rag_collections'] = count($collections);

            $stats['duration_ms'] = round((microtime(true) - $startTime) * 1000, 2);

            Log::channel('ai-engine')->info('Discovery caches warmed', $stats);

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Failed to warm discovery caches', [
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Warm autonomous collectors cache only
     */
    public function warmCollectors(bool $force = false): int
    {
        $collectors = $this->collectorDiscovery->discoverCollectors(
            useCache: !$force,
            includeRemote: true
        );

        return count($collectors);
    }

    /**
     * Warm RAG collections cache only
     */
    public function warmRAGCollections(bool $force = false): int
    {
        $collections = $this->ragDiscovery->discover(
            useCache: !$force,
            includeFederated: true
        );

        return count($collections);
    }

    /**
     * Check if caches are warm (populated)
     */
    public function areCachesWarm(): array
    {
        return [
            'autonomous_collectors' => \Illuminate\Support\Facades\Cache::has('ai-engine:discovered-autonomous-collectors'),
            'rag_collections' => \Illuminate\Support\Facades\Cache::has('ai_engine:rag_collections'),
        ];
    }
}
