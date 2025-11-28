<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\SchemaAnalyzer;
use LaravelAIEngine\Services\ModelAnalyzer;

class ConfigureAllModelsCommand extends Command
{
    protected $signature = 'ai-engine:configure-all
                            {--analyze : Show detailed analysis for each model}
                            {--export= : Export configurations to a file}
                            {--format=php : Export format (php, json, markdown)}';

    protected $description = 'Analyze and generate configuration for all vectorizable models';

    public function handle(SchemaAnalyzer $schemaAnalyzer, ModelAnalyzer $modelAnalyzer): int
    {
        $this->displayHeader();
        
        // Discover all vectorizable models
        $models = $schemaAnalyzer->discoverVectorizableModels();
        
        if (empty($models)) {
            $this->warn('No vectorizable models found.');
            $this->newLine();
            $this->info('💡 Add the Vectorizable trait to your models to get started:');
            $this->line('   use LaravelAIEngine\Traits\Vectorizable;');
            return self::SUCCESS;
        }
        
        $this->info("Found " . count($models) . " vectorizable model(s)");
        $this->newLine();
        
        $configurations = [];
        $showAnalysis = $this->option('analyze');
        
        foreach ($models as $modelClass) {
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->info("📦 Analyzing: " . class_basename($modelClass));
            $this->newLine();
            
            try {
                $analysis = $modelAnalyzer->analyze($modelClass);
                
                if ($showAnalysis) {
                    $this->displayAnalysis($analysis);
                }
                
                $config = $this->generateConfiguration($analysis);
                $configurations[$modelClass] = $config;
                
                $this->displayConfiguration($modelClass, $config);
                
            } catch (\Exception $e) {
                $this->error("❌ Failed to analyze {$modelClass}: {$e->getMessage()}");
            }
            
            $this->newLine();
        }
        
        // Export if requested
        if ($exportPath = $this->option('export')) {
            $this->exportConfigurations($configurations, $exportPath);
        }
        
        $this->displaySummary(count($models), count($configurations));
        
        return self::SUCCESS;
    }
    
    protected function displayHeader(): void
    {
        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║                                                            ║');
        $this->line('║     🔧 Configure All Vectorizable Models 🔧               ║');
        $this->line('║                                                            ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }
    
    protected function displayAnalysis(array $analysis): void
    {
        $schema = $analysis['schema'] ?? [];
        $relationships = $analysis['relationships'] ?? [];
        
        // Text fields
        if (!empty($schema['text_fields'])) {
            $this->line("📝 Text Fields:");
            foreach ($schema['text_fields'] as $field) {
                $recommended = $field['recommended'] ? '✓' : ' ';
                $this->line("   [{$recommended}] {$field['name']} ({$field['type']})");
            }
            $this->newLine();
        }
        
        // Relationships
        if (!empty($relationships['relationships'])) {
            $this->line("🔗 Relationships:");
            foreach ($relationships['relationships'] as $rel) {
                $recommended = $rel['recommended'] ? '✓' : ' ';
                $this->line("   [{$recommended}] {$rel['name']} ({$rel['type']})");
            }
            $this->newLine();
        }
    }
    
    protected function generateConfiguration(array $analysis): array
    {
        $schema = $analysis['schema'] ?? [];
        $relationships = $analysis['relationships'] ?? [];
        
        // Get recommended text fields
        $vectorizable = [];
        foreach ($schema['text_fields'] ?? [] as $field) {
            if ($field['recommended']) {
                $vectorizable[] = $field['name'];
            }
        }
        
        // Get recommended relationships
        $vectorRelationships = [];
        foreach ($relationships['relationships'] ?? [] as $rel) {
            if ($rel['recommended']) {
                $vectorRelationships[] = $rel['name'];
            }
        }
        
        return [
            'vectorizable' => $vectorizable,
            'vectorRelationships' => $vectorRelationships,
            'maxRelationshipDepth' => !empty($vectorRelationships) ? 1 : 0,
            'ragPriority' => 50,
        ];
    }
    
    protected function displayConfiguration(string $modelClass, array $config): void
    {
        $this->line("✨ Generated Configuration:");
        $this->newLine();
        
        $this->line("```php");
        $this->line("class " . class_basename($modelClass) . " extends Model");
        $this->line("{");
        $this->line("    use Vectorizable;");
        $this->newLine();
        
        // Vectorizable fields
        if (!empty($config['vectorizable'])) {
            $fields = "'" . implode("', '", $config['vectorizable']) . "'";
            $this->line("    public array \$vectorizable = [{$fields}];");
        }
        
        // Relationships
        if (!empty($config['vectorRelationships'])) {
            $this->newLine();
            $rels = "'" . implode("', '", $config['vectorRelationships']) . "'";
            $this->line("    protected array \$vectorRelationships = [{$rels}];");
            $this->line("    protected int \$maxRelationshipDepth = {$config['maxRelationshipDepth']};");
        }
        
        // RAG priority
        $this->newLine();
        $this->line("    protected int \$ragPriority = {$config['ragPriority']};");
        
        $this->newLine();
        $this->line("    // ... rest of your model code");
        $this->line("}");
        $this->line("```");
    }
    
    protected function exportConfigurations(array $configurations, string $path): void
    {
        $format = $this->option('format');
        
        switch ($format) {
            case 'json':
                $content = json_encode($configurations, JSON_PRETTY_PRINT);
                break;
                
            case 'markdown':
                $content = $this->generateMarkdown($configurations);
                break;
                
            case 'php':
            default:
                $content = $this->generatePhpFile($configurations);
                break;
        }
        
        file_put_contents($path, $content);
        
        $this->newLine();
        $this->info("✅ Configurations exported to: {$path}");
    }
    
    protected function generateMarkdown(array $configurations): string
    {
        $md = "# Vector Indexing Configuration\n\n";
        $md .= "Generated on: " . now()->toDateTimeString() . "\n\n";
        
        foreach ($configurations as $modelClass => $config) {
            $md .= "## " . class_basename($modelClass) . "\n\n";
            $md .= "```php\n";
            $md .= "class " . class_basename($modelClass) . " extends Model\n";
            $md .= "{\n";
            $md .= "    use Vectorizable;\n\n";
            
            if (!empty($config['vectorizable'])) {
                $fields = "'" . implode("', '", $config['vectorizable']) . "'";
                $md .= "    public array \$vectorizable = [{$fields}];\n";
            }
            
            if (!empty($config['vectorRelationships'])) {
                $rels = "'" . implode("', '", $config['vectorRelationships']) . "'";
                $md .= "    protected array \$vectorRelationships = [{$rels}];\n";
                $md .= "    protected int \$maxRelationshipDepth = {$config['maxRelationshipDepth']};\n";
            }
            
            $md .= "    protected int \$ragPriority = {$config['ragPriority']};\n";
            $md .= "}\n";
            $md .= "```\n\n";
        }
        
        return $md;
    }
    
    protected function generatePhpFile(array $configurations): string
    {
        $php = "<?php\n\n";
        $php .= "/**\n";
        $php .= " * Vector Indexing Configuration\n";
        $php .= " * Generated on: " . now()->toDateTimeString() . "\n";
        $php .= " */\n\n";
        $php .= "return [\n";
        
        foreach ($configurations as $modelClass => $config) {
            $php .= "    '{$modelClass}' => [\n";
            $php .= "        'vectorizable' => ['" . implode("', '", $config['vectorizable']) . "'],\n";
            
            if (!empty($config['vectorRelationships'])) {
                $php .= "        'vectorRelationships' => ['" . implode("', '", $config['vectorRelationships']) . "'],\n";
                $php .= "        'maxRelationshipDepth' => {$config['maxRelationshipDepth']},\n";
            }
            
            $php .= "        'ragPriority' => {$config['ragPriority']},\n";
            $php .= "    ],\n\n";
        }
        
        $php .= "];\n";
        
        return $php;
    }
    
    protected function displaySummary(int $totalModels, int $configured): void
    {
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║                        SUMMARY                             ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();
        
        $this->info("  Total Models Found:     {$totalModels}");
        $this->info("  Configurations Generated: {$configured}");
        $this->newLine();
        
        $this->line("💡 Next Steps:");
        $this->line("   1. Copy the generated configurations to your model files");
        $this->line("   2. Customize the configurations as needed");
        $this->line("   3. Run: php artisan ai-engine:vector-index --with-relationships");
        $this->newLine();
    }
}
