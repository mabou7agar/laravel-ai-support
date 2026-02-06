<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

/**
 * Clear all discovery caches
 * 
 * Usage: php artisan ai-engine:clear-discovery-cache
 */
class ClearDiscoveryCacheCommand extends Command
{
    protected $signature = 'ai-engine:clear-discovery-cache
                          {--warm : Warm the cache after clearing}';

    protected $description = 'Clear all AI Engine discovery caches (collectors, RAG collections, metadata)';

    public function handle(): int
    {
        $this->info('Clearing AI Engine discovery caches...');

        // Clear autonomous collector cache
        $collectorDiscovery = app(AutonomousCollectorDiscoveryService::class);
        $collectorDiscovery->clearCache();
        $this->line('✓ Autonomous collector cache cleared');

        // Clear RAG collection cache
        $ragDiscovery = app(RAGCollectionDiscovery::class);
        $ragDiscovery->clearCache();
        $this->line('✓ RAG collection cache cleared');

        // Clear node metadata cache if exists
        if (class_exists(\LaravelAIEngine\Services\Node\NodeMetadataDiscovery::class)) {
            \Illuminate\Support\Facades\Cache::forget('ai-engine:node-metadata');
            $this->line('✓ Node metadata cache cleared');
        }

        $this->info('All discovery caches cleared successfully!');

        // Warm cache if requested
        if ($this->option('warm')) {
            $this->info('');
            $this->info('Warming caches...');
            
            // Warm autonomous collectors
            $collectors = $collectorDiscovery->discoverCollectors(useCache: false);
            $this->line("✓ Discovered {$collectors->count()} autonomous collectors");
            
            // Warm RAG collections
            $collections = $ragDiscovery->discover(useCache: false);
            $this->line("✓ Discovered " . count($collections) . " RAG collections");
            
            $this->info('Cache warming completed!');
        }

        return self::SUCCESS;
    }
}
