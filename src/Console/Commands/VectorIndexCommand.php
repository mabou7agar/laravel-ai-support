<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use Illuminate\Support\Facades\DB;

class VectorIndexCommand extends Command
{
    protected $signature = 'ai-engine:vector-index
                            {model : The model class to index}
                            {--id=* : Specific model IDs to index}
                            {--batch=100 : Batch size for indexing}
                            {--force : Force re-indexing of already indexed models}
                            {--queue : Queue the indexing jobs}';

    protected $description = 'Index models in the vector database';

    public function handle(VectorSearchService $vectorSearch): int
    {
        $modelClass = $this->argument('model');
        
        if (!class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");
            return self::FAILURE;
        }

        $this->info("Indexing {$modelClass}...");

        try {
            // Create collection if it doesn't exist
            $this->info('Creating vector collection...');
            $vectorSearch->createCollection($modelClass);

            // Get models to index
            $query = $modelClass::query();
            
            if ($ids = $this->option('id')) {
                $query->whereIn('id', $ids);
            }

            $total = $query->count();
            $this->info("Found {$total} models to index");

            if ($total === 0) {
                $this->warn('No models found to index');
                return self::SUCCESS;
            }

            $batchSize = (int) $this->option('batch');
            $indexed = 0;
            $failed = 0;

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $query->chunk($batchSize, function ($models) use ($vectorSearch, &$indexed, &$failed, $bar) {
                foreach ($models as $model) {
                    try {
                        // Check if should be indexed
                        if (method_exists($model, 'shouldBeIndexed') && !$model->shouldBeIndexed()) {
                            $bar->advance();
                            continue;
                        }

                        $vectorSearch->index($model);
                        $indexed++;
                    } catch (\Exception $e) {
                        $failed++;
                        $this->error("\nFailed to index model {$model->id}: {$e->getMessage()}");
                    }
                    
                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine(2);

            $this->info("✓ Indexed {$indexed} models");
            
            if ($failed > 0) {
                $this->warn("✗ Failed to index {$failed} models");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Indexing failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
