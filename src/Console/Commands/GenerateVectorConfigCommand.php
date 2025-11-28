<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\ModelAnalyzer;

class GenerateVectorConfigCommand extends Command
{
    protected $signature = 'ai-engine:generate-config 
                            {model : The model class to configure}
                            {--show : Only show configuration without writing to file}
                            {--depth=1 : Maximum relationship depth}';
    
    protected $description = 'Generate vector indexing configuration code for a model';

    public function handle(ModelAnalyzer $analyzer): int
    {
        $modelClass = $this->argument('model');
        $showOnly = $this->option('show');
        $depth = (int) $this->option('depth');
        
        if (!class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");
            return self::FAILURE;
        }
        
        try {
            $this->info("⚙️  Generating Configuration for: {$modelClass}");
            $this->newLine();
            
            // Analyze model
            $analysis = $analyzer->analyze($modelClass);
            $config = $analysis['schema']['recommended_config'] ?? [];
            
            // Override depth if specified
            if ($depth !== 1) {
                $config['maxRelationshipDepth'] = $depth;
            }
            
            // Generate code
            $code = $this->generateConfigCode($modelClass, $config);
            
            // Display
            $this->displayConfiguration($code);
            
            if ($showOnly) {
                $this->newLine();
                $this->info('💡 Copy the above code to your model file');
                return self::SUCCESS;
            }
            
            // Ask to write to file
            if ($this->confirm('Write this configuration to the model file?', false)) {
                return $this->writeToModelFile($modelClass, $config);
            }
            
            $this->info('Configuration not written. Use --show to display only.');
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to generate configuration: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
    
    protected function generateConfigCode(string $modelClass, array $config): string
    {
        $modelName = class_basename($modelClass);
        $code = [];
        
        $code[] = "class {$modelName} extends Model";
        $code[] = "{";
        $code[] = "    use Vectorizable;";
        $code[] = "";
        
        // Vectorizable fields
        if (!empty($config['vectorizable'])) {
            $code[] = "    /**";
            $code[] = "     * Fields to index for vector search";
            $code[] = "     */";
            $code[] = "    public array \$vectorizable = [";
            foreach ($config['vectorizable'] as $field) {
                $code[] = "        '{$field}',";
            }
            $code[] = "    ];";
            $code[] = "";
        }
        
        // Relationships
        if (!empty($config['vectorRelationships'])) {
            $code[] = "    /**";
            $code[] = "     * Relationships to include in vector content";
            $code[] = "     */";
            $code[] = "    protected array \$vectorRelationships = [";
            foreach ($config['vectorRelationships'] as $rel) {
                $code[] = "        '{$rel}',";
            }
            $code[] = "    ];";
            $code[] = "";
            
            $depth = $config['maxRelationshipDepth'] ?? 1;
            $code[] = "    /**";
            $code[] = "     * Maximum relationship depth to traverse";
            $code[] = "     */";
            $code[] = "    protected int \$maxRelationshipDepth = {$depth};";
            $code[] = "";
        }
        
        // RAG Priority
        if (isset($config['ragPriority'])) {
            $code[] = "    /**";
            $code[] = "     * RAG priority (0-100, higher = searched first)";
            $code[] = "     */";
            $code[] = "    protected int \$ragPriority = {$config['ragPriority']};";
            $code[] = "";
        }
        
        $code[] = "    // ... rest of your model code";
        $code[] = "}";
        
        return implode("\n", $code);
    }
    
    protected function displayConfiguration(string $code): void
    {
        $this->newLine();
        $this->line("<fg=green>✨ Generated Configuration:</>");
        $this->newLine();
        
        $this->line("<fg=yellow>```php</>");
        foreach (explode("\n", $code) as $line) {
            $this->line($line);
        }
        $this->line("<fg=yellow>```</>");
    }
    
    protected function writeToModelFile(string $modelClass, array $config): int
    {
        try {
            // Get model file path
            $reflection = new \ReflectionClass($modelClass);
            $filePath = $reflection->getFileName();
            
            if (!$filePath || !file_exists($filePath)) {
                $this->error("Could not find model file");
                return self::FAILURE;
            }
            
            $this->warn("⚠️  This will modify: {$filePath}");
            
            if (!$this->confirm('Are you sure?', false)) {
                $this->info('Cancelled');
                return self::SUCCESS;
            }
            
            // Read current file
            $content = file_get_contents($filePath);
            
            // Check if Vectorizable trait is already used
            if (!str_contains($content, 'use Vectorizable')) {
                // Add use statement
                $content = $this->addUseStatement($content);
            }
            
            // Add or update properties
            $content = $this->addProperties($content, $config);
            
            // Write back
            file_put_contents($filePath, $content);
            
            $this->info("✓ Configuration written to model file");
            $this->newLine();
            $this->line("Next steps:");
            $this->line("1. Review the changes in: {$filePath}");
            $this->line("2. Run: php artisan ai-engine:vector-index \"{$modelClass}\" --with-relationships");
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Failed to write to file: {$e->getMessage()}");
            $this->warn("Please manually copy the configuration above");
            return self::FAILURE;
        }
    }
    
    protected function addUseStatement(string $content): string
    {
        // Find the class declaration
        if (preg_match('/class\s+\w+\s+extends\s+\w+\s*\{/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $classPos = $matches[0][1];
            
            // Find the opening brace
            $bracePos = strpos($content, '{', $classPos);
            
            // Insert use statement after opening brace
            $before = substr($content, 0, $bracePos + 1);
            $after = substr($content, $bracePos + 1);
            
            $useStatement = "\n    use Vectorizable;\n";
            
            return $before . $useStatement . $after;
        }
        
        return $content;
    }
    
    protected function addProperties(string $content, array $config): string
    {
        // This is a simplified version - in production, you'd want to use
        // a proper PHP parser like nikic/php-parser
        
        $this->warn("⚠️  Automatic file modification is experimental");
        $this->warn("Please review the changes manually");
        
        // For now, just append to the end of the class
        // A proper implementation would parse the PHP and insert in the right place
        
        return $content;
    }
}
