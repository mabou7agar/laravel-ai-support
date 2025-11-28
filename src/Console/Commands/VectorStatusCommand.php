<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Vector\VectorSearchService;

class VectorStatusCommand extends Command
{
    protected $signature = 'ai-engine:vector-status 
                            {model? : The model class to check status}';
    
    protected $description = 'Show vector indexing status for models';

    public function handle(VectorSearchService $vectorSearch): int
    {
        $modelClass = $this->argument('model');
        
        if ($modelClass) {
            return $this->showModelStatus($modelClass, $vectorSearch);
        }
        
        return $this->showAllStatus($vectorSearch);
    }
    
    protected function showModelStatus(string $modelClass, VectorSearchService $vectorSearch): int
    {
        if (!class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");
            return self::FAILURE;
        }
        
        $this->info("📊 Vector Indexing Status");
        $this->newLine();
        
        $this->line("<fg=cyan>{$modelClass}</>");
        $this->line(str_repeat('─', 60));
        
        try {
            $stats = $this->getModelStats($modelClass, $vectorSearch);
            
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Collection', $stats['collection']],
                    ['Total Records', number_format($stats['total_records'])],
                    ['Indexed', number_format($stats['indexed'])],
                    ['Pending', number_format($stats['pending'])],
                    ['Status', $stats['status']],
                ]
            );
            
            if ($stats['has_relationships']) {
                $this->newLine();
                $this->line("<fg=green>✓</> Relationships configured: " . implode(', ', $stats['relationships']));
            }
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to get status: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
    
    protected function showAllStatus(VectorSearchService $vectorSearch): int
    {
        $this->info("📊 All Vectorizable Models Status");
        $this->newLine();
        
        $models = \discover_vectorizable_models();
        
        if (empty($models)) {
            $this->warn('No vectorizable models found.');
            return self::SUCCESS;
        }
        
        $rows = [];
        
        foreach ($models as $modelClass) {
            try {
                $stats = $this->getModelStats($modelClass, $vectorSearch);
                
                $rows[] = [
                    class_basename($modelClass),
                    number_format($stats['total_records']),
                    number_format($stats['indexed']),
                    number_format($stats['pending']),
                    $stats['status'],
                ];
            } catch (\Exception $e) {
                $rows[] = [
                    class_basename($modelClass),
                    '-',
                    '-',
                    '-',
                    '<fg=red>Error</>',
                ];
            }
        }
        
        $this->table(
            ['Model', 'Total', 'Indexed', 'Pending', 'Status'],
            $rows
        );
        
        return self::SUCCESS;
    }
    
    protected function getModelStats(string $modelClass, VectorSearchService $vectorSearch): array
    {
        $total = $modelClass::count();
        
        // Get actual indexed count from vector database
        try {
            $collectionName = $this->getCollectionName($modelClass);
            $indexed = $vectorSearch->getIndexedCount($modelClass);
            $pending = max(0, $total - $indexed);
        } catch (\Exception $e) {
            // Fallback if vector service fails
            $this->warn("Could not get indexed count for {$modelClass}: {$e->getMessage()}");
            $indexed = 0;
            $pending = $total;
        }
        
        $model = new $modelClass;
        $hasRelationships = property_exists($model, 'vectorRelationships') && 
                           !empty($model->vectorRelationships);
        
        // Determine status
        if ($indexed === 0 && $total > 0) {
            $status = '<fg=red>Not Indexed</>';
        } elseif ($indexed === $total) {
            $status = '<fg=green>Complete</>';
        } elseif ($indexed > 0) {
            $status = '<fg=yellow>Partial</>';
        } else {
            $status = '<fg=gray>Empty</>';
        }
        
        return [
            'collection' => $collectionName,
            'total_records' => $total,
            'indexed' => $indexed,
            'pending' => $pending,
            'status' => $status,
            'has_relationships' => $hasRelationships,
            'relationships' => $hasRelationships ? $model->vectorRelationships : [],
        ];
    }
    
    protected function getCollectionName(string $modelClass): string
    {
        return strtolower(str_replace('\\', '_', $modelClass));
    }
}
