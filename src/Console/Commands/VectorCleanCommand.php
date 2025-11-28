<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\Vector\VectorAnalyticsService;
use Illuminate\Support\Facades\DB;

class VectorCleanCommand extends Command
{
    protected $signature = 'ai-engine:vector-clean
                            {--model= : Model class to clean}
                            {--orphaned : Remove orphaned vector embeddings}
                            {--analytics= : Clean analytics older than N days}
                            {--dry-run : Show what would be deleted without deleting}
                            {--force : Skip confirmation}';

    protected $description = 'Clean up vector database and analytics';

    public function handle(
        VectorSearchService $vectorSearch,
        VectorAnalyticsService $analytics
    ): int {
        if ($this->option('orphaned')) {
            return $this->cleanOrphanedEmbeddings($vectorSearch);
        }

        if ($days = $this->option('analytics')) {
            return $this->cleanAnalytics($analytics, (int) $days);
        }

        if ($modelClass = $this->option('model')) {
            return $this->cleanModelVectors($vectorSearch, $modelClass);
        }

        $this->warn('Please specify what to clean:');
        $this->line('  --orphaned          Clean orphaned vector embeddings');
        $this->line('  --analytics=90      Clean analytics older than 90 days');
        $this->line('  --model=App\Models\Post  Clean vectors for specific model');

        return self::SUCCESS;
    }

    protected function cleanOrphanedEmbeddings(VectorSearchService $vectorSearch): int
    {
        $this->info('Scanning for orphaned vector embeddings...');

        try {
            $embeddings = DB::table('vector_embeddings')->get();
            $orphaned = [];

            $bar = $this->output->createProgressBar($embeddings->count());
            $bar->start();

            foreach ($embeddings as $embedding) {
                $modelClass = $embedding->model_type;
                
                if (!class_exists($modelClass)) {
                    $orphaned[] = $embedding->id;
                    $bar->advance();
                    continue;
                }

                // Check if model still exists
                $exists = $modelClass::where('id', $embedding->model_id)->exists();
                
                if (!$exists) {
                    $orphaned[] = $embedding->id;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            if (empty($orphaned)) {
                $this->info('✓ No orphaned embeddings found');
                return self::SUCCESS;
            }

            $this->warn("Found " . count($orphaned) . " orphaned embeddings");

            if ($this->option('dry-run')) {
                $this->info('Dry run - no changes made');
                return self::SUCCESS;
            }

            if (!$this->option('force') && !$this->confirm('Delete orphaned embeddings?')) {
                $this->info('Cancelled');
                return self::SUCCESS;
            }

            $deleted = DB::table('vector_embeddings')
                ->whereIn('id', $orphaned)
                ->delete();

            $this->info("✓ Deleted {$deleted} orphaned embeddings");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Cleanup failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function cleanAnalytics(VectorAnalyticsService $analytics, int $days): int
    {
        $this->info("Cleaning analytics older than {$days} days...");

        if ($this->option('dry-run')) {
            $count = DB::table('vector_search_logs')
                ->where('created_at', '<', now()->subDays($days))
                ->count();

            $this->info("Would delete {$count} analytics records");
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Delete analytics older than {$days} days?")) {
            $this->info('Cancelled');
            return self::SUCCESS;
        }

        try {
            $deleted = $analytics->cleanOldData($days);
            $this->info("✓ Deleted {$deleted} analytics records");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Cleanup failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function cleanModelVectors(VectorSearchService $vectorSearch, string $modelClass): int
    {
        if (!class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");
            return self::FAILURE;
        }

        $count = DB::table('vector_embeddings')
            ->where('model_type', $modelClass)
            ->count();

        $this->warn("Found {$count} vector embeddings for {$modelClass}");

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} embeddings");
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Delete all vectors for this model?')) {
            $this->info('Cancelled');
            return self::SUCCESS;
        }

        try {
            $deleted = DB::table('vector_embeddings')
                ->where('model_type', $modelClass)
                ->delete();

            $this->info("✓ Deleted {$deleted} vector embeddings");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Cleanup failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
