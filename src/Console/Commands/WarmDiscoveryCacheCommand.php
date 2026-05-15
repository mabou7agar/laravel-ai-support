<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\DiscoveryCacheWarmer;

/**
 * Warm discovery caches
 * 
 * Usage: php artisan ai:warm-discovery-cache
 */
class WarmDiscoveryCacheCommand extends Command
{
    protected $signature = 'ai:warm-discovery-cache
                          {--force : Force re-discovery even if cache exists}';

    protected $description = 'Pre-populate AI Engine discovery caches to improve performance';

    public function handle(DiscoveryCacheWarmer $warmer): int
    {
        $this->info('Warming AI Engine discovery caches...');
        $this->newLine();

        $force = $this->option('force');

        if ($force) {
            $this->warn('Force mode enabled - re-discovering even if cache exists');
        }

        // Check current cache status
        $status = $warmer->areCachesWarm();
        if (!$force && $status['rag_collections']) {
            $this->info('✓ All caches are already warm');
            $this->line('  Use --force to re-warm');
            return self::SUCCESS;
        }

        // Warm all caches
        $stats = $warmer->warmAll($force);

        // Display results
        $this->info('Cache warming completed!');
        $this->newLine();
        $this->table(
            ['Cache Type', 'Items Cached'],
            [
                ['RAG Collections', $stats['rag_collections']],
            ]
        );
        $this->line("Duration: {$stats['duration_ms']}ms");

        return self::SUCCESS;
    }
}
