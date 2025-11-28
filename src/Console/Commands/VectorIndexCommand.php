<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use Illuminate\Support\Facades\DB;

class VectorIndexCommand extends Command
{
    protected $signature = 'ai-engine:vector-index
                            {model? : The model class to index (optional - indexes all vectorizable models if not provided)}
                            {--id=* : Specific model IDs to index}
                            {--batch=100 : Batch size for indexing}
                            {--force : Force re-indexing of already indexed models}
                            {--queue : Queue the indexing jobs}';

    protected $description = 'Index models in the vector database. If no model specified, indexes all vectorizable models';

    public function handle(VectorSearchService $vectorSearch): int
    {
        $modelClass = $this->argument('model');
        
        // If no model specified, index all vectorizable models
        if (!$modelClass) {
            return $this->indexAllVectorizableModels($vectorSearch);
        }
        
        if (!class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");
            return self::FAILURE;
        }

        return $this->indexModel($modelClass, $vectorSearch);
    }

    protected function indexAllVectorizableModels(VectorSearchService $vectorSearch): int
    {
        $this->info('🔍 Discovering vectorizable models...');
        
        $models = $this->discoverVectorizableModels();
        
        if (empty($models)) {
            $this->warn('No vectorizable models found.');
            $this->line('');
            $this->line('To make a model vectorizable, add the Vectorizable trait:');
            $this->line('  use LaravelAIEngine\Traits\Vectorizable;');
            return self::SUCCESS;
        }
        
        $this->info("Found " . count($models) . " vectorizable model(s):");
        foreach ($models as $model) {
            $this->line("  • {$model}");
        }
        $this->newLine();
        
        $totalIndexed = 0;
        $totalFailed = 0;
        
        foreach ($models as $modelClass) {
            $this->info("📦 Indexing {$modelClass}...");
            $result = $this->indexModel($modelClass, $vectorSearch, false);
            
            if ($result === self::SUCCESS) {
                $totalIndexed++;
            } else {
                $totalFailed++;
            }
            $this->newLine();
        }
        
        $this->info("✅ Summary:");
        $this->info("  • Successfully indexed: {$totalIndexed} model(s)");
        if ($totalFailed > 0) {
            $this->warn("  • Failed: {$totalFailed} model(s)");
        }
        
        return self::SUCCESS;
    }

    protected function discoverVectorizableModels(): array
    {
        // Discover all vectorizable models by scanning multiple paths
        // This logic is also available as a helper: discover_vectorizable_models()
        
        $models = [];
        
        // Define paths to scan for models
        $searchPaths = [
            app_path('Models'),           // app/Models
            base_path('modules'),         // modules/*/Models
            base_path('Modules'),         // Modules/*/Models (capitalized)
            app_path(),                   // app/* (for custom structures)
        ];
        
        foreach ($searchPaths as $basePath) {
            if (!is_dir($basePath)) {
                continue;
            }
            
            try {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
            } catch (\Exception $e) {
                continue;
            }
            
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    // Read file content and check for Vectorizable trait usage
                    $content = file_get_contents($file->getPathname());
                    
                    // Check if file uses Vectorizable trait
                    if (preg_match('/use\s+LaravelAIEngine\\\\Traits\\\\Vectorizable\s*;/i', $content) ||
                        preg_match('/use\s+Vectorizable\s*;/i', $content)) {
                        
                        // Extract namespace and class name
                        if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatches) &&
                            preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                            
                            $namespace = trim($nsMatches[1]);
                            $className = trim($classMatches[1]);
                            $models[] = $namespace . '\\' . $className;
                        }
                    }
                }
            }
        }
        
        return array_unique($models);
    }

    protected function indexModel(string $modelClass, VectorSearchService $vectorSearch, bool $showHeader = true): int
    {
        if ($showHeader) {
            $this->info("Indexing {$modelClass}...");
        }

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
