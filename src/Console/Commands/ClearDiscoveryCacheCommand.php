<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

/**
 * Clear all discovery caches
 * 
 * Usage: php artisan ai:clear-discovery-cache
 */
class ClearDiscoveryCacheCommand extends Command
{
    protected $signature = 'ai:clear-discovery-cache
                          {--warm : Warm the cache after clearing}';

    protected $description = 'Clear AI Engine discovery caches (vector collections and metadata)';

    public function handle(): int
    {
        $this->info('Clearing AI Engine discovery caches...');

        // Clear vector collection cache
        $ragDiscovery = app(RAGCollectionDiscovery::class);
        $ragDiscovery->clearCache();
        $this->line('✓ Vector collection cache cleared');

        // Clear node metadata cache if exists
        if (class_exists(\LaravelAIEngine\Services\Node\NodeMetadataDiscovery::class)) {
            \Illuminate\Support\Facades\Cache::forget('ai:node-metadata');
            $this->line('✓ Node metadata cache cleared');
        }

        $this->info('All discovery caches cleared successfully!');

        // Warm cache if requested
        if ($this->option('warm')) {
            $this->info('');
            $this->info('Warming caches...');
            
            // Warm vector collections
            $collections = $ragDiscovery->discover(useCache: false);
            $this->line("✓ Discovered " . count($collections) . " vector collections");
            
            $this->info('Cache warming completed!');
        }

        return self::SUCCESS;
    }
}
