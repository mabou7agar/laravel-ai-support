<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\SchemaAnalyzer;

class ListVectorizableModelsCommand extends Command
{
    protected $signature = 'ai-engine:list-models 
                            {--stats : Show statistics for each model}
                            {--detailed : Show detailed information}';
    
    protected $description = 'List all vectorizable models in the application';

    public function handle(SchemaAnalyzer $analyzer): int
    {
        $this->info('🔍 Discovering vectorizable models...');
        $this->newLine();
        
        $models = $analyzer->discoverVectorizableModels();
        
        if (empty($models)) {
            $this->warn('No vectorizable models found.');
            $this->newLine();
            $this->line('To make a model vectorizable, add the Vectorizable trait:');
            $this->line('  <fg=cyan>use LaravelAIEngine\Traits\Vectorizable;</>');
            return self::SUCCESS;
        }
        
        $this->info("Found " . count($models) . " vectorizable models");
        $this->newLine();
        
        if ($this->option('detailed')) {
            return $this->showDetailed($models, $analyzer);
        }
        
        if ($this->option('stats')) {
            return $this->showWithStats($models, $analyzer);
        }
        
        return $this->showSimple($models);
    }
    
    protected function showSimple(array $models): int
    {
        foreach ($models as $index => $modelClass) {
            $this->line(($index + 1) . ". <fg=cyan>{$modelClass}</>");
        }
        
        $this->newLine();
        $this->line("<fg=gray>Use --stats to see statistics or --detailed for full analysis</>");
        
        return self::SUCCESS;
    }
    
    protected function showWithStats(array $models, SchemaAnalyzer $analyzer): int
    {
        $rows = [];
        
        foreach ($models as $modelClass) {
            try {
                $analysis = $analyzer->analyzeModel($modelClass);
                
                $textFieldCount = count($analysis['text_fields'] ?? []);
                $relationshipCount = count($analysis['relationships'] ?? []);
                $recordCount = $analysis['estimated_size']['record_count'] ?? 0;
                
                $rows[] = [
                    class_basename($modelClass),
                    $textFieldCount,
                    $relationshipCount,
                    number_format($recordCount),
                ];
            } catch (\Exception $e) {
                $rows[] = [
                    class_basename($modelClass),
                    '-',
                    '-',
                    'Error',
                ];
            }
        }
        
        $this->table(
            ['Model', 'Text Fields', 'Relationships', 'Records'],
            $rows
        );
        
        return self::SUCCESS;
    }
    
    protected function showDetailed(array $models, SchemaAnalyzer $analyzer): int
    {
        foreach ($models as $index => $modelClass) {
            $this->line("<fg=cyan>" . ($index + 1) . ". {$modelClass}</>");
            
            try {
                $analysis = $analyzer->analyzeModel($modelClass);
                
                // Text fields
                $textFields = $analysis['text_fields'] ?? [];
                if (!empty($textFields)) {
                    $recommended = array_filter($textFields, fn($f) => $f['recommended']);
                    $this->line("   <fg=green>Text Fields:</> " . count($textFields) . " (" . count($recommended) . " recommended)");
                }
                
                // Relationships
                $relationships = $analysis['relationships'] ?? [];
                if (!empty($relationships)) {
                    $recommended = array_filter($relationships, fn($r) => $r['recommended']);
                    $this->line("   <fg=green>Relationships:</> " . count($relationships) . " (" . count($recommended) . " recommended)");
                }
                
                // Size
                $size = $analysis['estimated_size'] ?? [];
                if (isset($size['record_count'])) {
                    $this->line("   <fg=green>Records:</> " . number_format($size['record_count']) . " (~{$size['estimated_mb']} MB)");
                }
                
                $this->newLine();
            } catch (\Exception $e) {
                $this->line("   <fg=red>Error:</> {$e->getMessage()}");
                $this->newLine();
            }
        }
        
        return self::SUCCESS;
    }
}
