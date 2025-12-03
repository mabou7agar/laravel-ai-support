<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\SchemaAnalyzer;

class AnalyzeModelCommand extends Command
{
    protected $signature = 'ai-engine:analyze-model 
                            {model? : The model class to analyze}
                            {--all : Analyze all vectorizable models}';
    
    protected $description = 'Analyze a model and suggest vector indexing configuration';

    public function handle(SchemaAnalyzer $analyzer): int
    {
        $modelClass = $this->argument('model');
        $analyzeAll = $this->option('all');
        
        if ($analyzeAll || !$modelClass) {
            return $this->analyzeAllModels($analyzer);
        }
        
        return $this->analyzeSingleModel($modelClass, $analyzer);
    }
    
    protected function analyzeSingleModel(string $modelClass, SchemaAnalyzer $analyzer): int
    {
        try {
            $this->info("📊 Analyzing {$modelClass}...");
            $this->newLine();
            
            $analysis = $analyzer->analyzeModel($modelClass);
            
            $this->displayAnalysis($analysis);
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Analysis failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
    
    protected function analyzeAllModels(SchemaAnalyzer $analyzer): int
    {
        $this->info('🔍 Discovering vectorizable models...');
        $this->newLine();
        
        $models = $analyzer->discoverVectorizableModels();
        
        if (empty($models)) {
            $this->warn('No vectorizable models found.');
            return self::SUCCESS;
        }
        
        $this->info("Found " . count($models) . " vectorizable models");
        $this->newLine();
        
        foreach ($models as $modelClass) {
            try {
                $analysis = $analyzer->analyzeModel($modelClass);
                $this->displayAnalysis($analysis, false);
                $this->newLine();
            } catch (\Exception $e) {
                $this->error("Failed to analyze {$modelClass}: {$e->getMessage()}");
            }
        }
        
        return self::SUCCESS;
    }
    
    protected function displayAnalysis(array $analysis, bool $verbose = true): void
    {
        // Model Info
        $this->line("<fg=cyan>Model:</> <fg=yellow>{$analysis['model']}</>");
        $this->line("<fg=cyan>Table:</> {$analysis['table']}");
        
        if (isset($analysis['estimated_size'])) {
            $size = $analysis['estimated_size'];
            $this->line("<fg=cyan>Records:</> {$size['record_count']} (~{$size['estimated_mb']} MB)");
        }
        
        $this->newLine();
        
        // Text Fields
        if (!empty($analysis['text_fields'])) {
            $this->line("<fg=green>📝 Text Fields:</>");
            
            $headers = ['Field', 'Type', 'Recommended'];
            $rows = [];
            
            foreach ($analysis['text_fields'] as $field) {
                $rows[] = [
                    $field['name'],
                    $field['type'],
                    $field['recommended'] ? '✓' : '',
                ];
            }
            
            $this->table($headers, $rows);
        } else {
            $this->warn('No text fields found');
        }
        
        $this->newLine();
        
        // Relationships
        if (!empty($analysis['relationships'])) {
            $this->line("<fg=green>🔗 Relationships:</>");
            
            $headers = ['Relationship', 'Type', 'Recommended'];
            $rows = [];
            
            foreach ($analysis['relationships'] as $rel) {
                $rows[] = [
                    $rel['name'],
                    $rel['type'],
                    $rel['recommended'] ? '✓' : '',
                ];
            }
            
            $this->table($headers, $rows);
        } else {
            $this->line('No relationships detected');
        }
        
        $this->newLine();
        
        // Recommended Configuration
        if ($verbose) {
            $this->line("<fg=green>✨ Recommended Configuration:</>");
            $this->newLine();
            
            $config = $analysis['recommended_config'];
            $modelName = class_basename($analysis['model']);
            
            $this->line('<fg=yellow>class ' . $modelName . ' extends Model</>');
            $this->line('{');
            $this->line('    <fg=cyan>use Vectorizable;</>');
            $this->line('');
            
            // Vectorizable fields
            if (!empty($config['vectorizable'])) {
                $this->line('    <fg=gray>// Fields to index</>');
                $this->line('    <fg=yellow>public array $vectorizable = [</>');
                foreach ($config['vectorizable'] as $field) {
                    $this->line("        <fg=green>'{$field}'</>,");
                }
                $this->line('    <fg=yellow>];</>');
                $this->line('');
            }
            
            // Relationships
            if (!empty($config['vectorRelationships'])) {
                $this->line('    <fg=gray>// Relationships to include</>');
                $this->line('    <fg=yellow>protected array $vectorRelationships = [</>');
                foreach ($config['vectorRelationships'] as $rel) {
                    $this->line("        <fg=green>'{$rel}'</>,");
                }
                $this->line('    <fg=yellow>];</>');
                $this->line('');
                $this->line("    <fg=yellow>protected int \$maxRelationshipDepth = {$config['maxRelationshipDepth']};</>");
                $this->line('');
            }
            
            $this->line('}');
            $this->newLine();
            
            // Usage example
            $this->line("<fg=green>💡 Usage:</>");
            $this->line("<fg=gray>php artisan ai-engine:vector-index \"{$analysis['model']}\" --with-relationships</>");
        }
    }
}
