<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreateQdrantIndexesCommand extends Command
{
    protected $signature = 'ai-engine:create-indexes 
                            {collection? : The Qdrant collection name (optional if using --all)}
                            {field? : The field name to create index for (optional if using --all)}
                            {--type=integer : The field type (integer, keyword, float, bool)}
                            {--all : Discover all Vectorizable models and create missing indexes}';

    protected $description = 'Create field indexes in Qdrant collections for efficient filtering';

    protected string $qdrantHost;
    protected ?string $qdrantApiKey;

    public function handle(): int
    {
        $this->qdrantHost = rtrim(config('ai-engine.vector.drivers.qdrant.host', env('QDRANT_HOST', 'http://localhost:6333')), '/');
        $this->qdrantApiKey = config('ai-engine.vector.drivers.qdrant.api_key', env('QDRANT_API_KEY'));

        if ($this->option('all')) {
            return $this->createAllIndexes();
        }

        $collection = $this->argument('collection');
        $field = $this->argument('field');

        if (!$collection || !$field) {
            $this->error('Please provide collection and field arguments, or use --all flag');
            return Command::FAILURE;
        }

        return $this->createIndex($collection, $field, $this->option('type'));
    }

    /**
     * Discover all Vectorizable models and create missing indexes
     */
    protected function createAllIndexes(): int
    {
        $this->info('🔍 Discovering Vectorizable models...');

        $models = $this->discoverVectorizableModels();

        if (empty($models)) {
            $this->warn('No Vectorizable models found.');
            return Command::SUCCESS;
        }

        $this->info("Found " . count($models) . " Vectorizable model(s)");
        $this->newLine();

        $totalCreated = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        foreach ($models as $modelClass) {
            $this->info("📦 Processing: {$modelClass}");

            try {
                $instance = new $modelClass();
                $collection = $instance->getVectorCollectionName();

                // Check if collection exists
                if (!$this->collectionExists($collection)) {
                    $this->warn("   ⏭️  Collection '{$collection}' does not exist, skipping");
                    continue;
                }

                // Get required indexes from getVectorMetadata()
                $indexes = $this->getRequiredIndexes($instance);

                if (empty($indexes)) {
                    $this->line("   No indexes defined for this model");
                    continue;
                }

                $this->line("   Collection: {$collection}");
                $this->line("   Indexes to check: " . implode(', ', array_keys($indexes)));

                foreach ($indexes as $field => $type) {
                    $result = $this->createIndex($collection, $field, $type, true);
                    
                    if ($result === 'created') {
                        $totalCreated++;
                    } elseif ($result === 'exists') {
                        $totalSkipped++;
                    } else {
                        $totalFailed++;
                    }
                }

                $this->newLine();

            } catch (\Exception $e) {
                $this->error("   ❌ Error: " . $e->getMessage());
                Log::channel('ai-engine')->error('Failed to create indexes for model', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
                $totalFailed++;
            }
        }

        $this->newLine();
        $this->info("📊 Summary:");
        $this->line("   ✅ Created: {$totalCreated}");
        $this->line("   ⏭️  Already existed: {$totalSkipped}");
        $this->line("   ❌ Failed: {$totalFailed}");

        Log::channel('ai-engine')->info('Qdrant indexes creation completed', [
            'created' => $totalCreated,
            'skipped' => $totalSkipped,
            'failed' => $totalFailed,
        ]);

        return $totalFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Discover all models with Vectorizable trait
     */
    protected function discoverVectorizableModels(): array
    {
        $models = [];
        $discoveryPaths = config('ai-engine.intelligent_rag.discovery_paths', [
            app_path('Models'),
            base_path('modules/*/Models'),
        ]);

        foreach ($discoveryPaths as $path) {
            if (str_contains($path, '*')) {
                $matchedPaths = glob($path, GLOB_ONLYDIR);
                foreach ($matchedPaths as $matchedPath) {
                    $models = array_merge($models, $this->discoverFromPath($matchedPath));
                }
            } else {
                $models = array_merge($models, $this->discoverFromPath($path));
            }
        }

        return array_unique($models);
    }

    /**
     * Discover Vectorizable models from a path
     */
    protected function discoverFromPath(string $path): array
    {
        $models = [];

        if (!File::isDirectory($path)) {
            return [];
        }

        $files = File::allFiles($path);

        foreach ($files as $file) {
            try {
                $content = file_get_contents($file->getRealPath());
                
                // Check if file uses Vectorizable trait
                if (!str_contains($content, 'use LaravelAIEngine\Traits\Vectorizable') &&
                    !str_contains($content, 'Vectorizable')) {
                    continue;
                }

                // Extract class name
                $className = $this->extractClassName($content);
                
                if ($className && class_exists($className)) {
                    $traits = class_uses_recursive($className);
                    if (isset($traits['LaravelAIEngine\Traits\Vectorizable'])) {
                        $models[] = $className;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $models;
    }

    /**
     * Extract fully qualified class name from file content
     */
    protected function extractClassName(string $content): ?string
    {
        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = $matches[1];
        }

        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $class = $matches[1];
        }

        if ($namespace && $class) {
            return $namespace . '\\' . $class;
        }

        return null;
    }

    /**
     * Get required indexes from model's getVectorMetadata()
     */
    protected function getRequiredIndexes($instance): array
    {
        $indexes = [];

        // Always add model_class and model_id as keyword indexes
        $indexes['model_class'] = 'keyword';
        $indexes['model_id'] = 'integer';

        // Get indexes from getVectorMetadata() if available
        if (method_exists($instance, 'getVectorMetadata')) {
            $metadata = $instance->getVectorMetadata();
            
            foreach ($metadata as $key => $value) {
                // Infer type from value
                $type = $this->inferFieldType($key, $value);
                $indexes[$key] = $type;
            }
        }

        // Get custom indexes from getQdrantIndexes() if available
        if (method_exists($instance, 'getQdrantIndexes')) {
            $customIndexes = $instance->getQdrantIndexes();
            foreach ($customIndexes as $field => $type) {
                $indexes[$field] = $type;
            }
        }

        return $indexes;
    }

    /**
     * Infer Qdrant field type from key name and value
     */
    protected function inferFieldType(string $key, $value): string
    {
        // Check by value type
        if (is_int($value)) {
            return 'integer';
        }
        if (is_float($value)) {
            return 'float';
        }
        if (is_bool($value)) {
            return 'bool';
        }

        // Check by key name patterns
        if (str_ends_with($key, '_id') || $key === 'id') {
            return 'integer';
        }
        if (str_contains($key, 'date') || str_contains($key, 'time')) {
            return 'keyword'; // Store dates as keywords for range queries
        }
        if (str_contains($key, 'count') || str_contains($key, 'amount') || str_contains($key, 'price')) {
            return 'integer';
        }
        if (str_contains($key, 'is_') || str_contains($key, 'has_')) {
            return 'bool';
        }

        // Default to keyword for strings
        return 'keyword';
    }

    /**
     * Check if a collection exists in Qdrant
     */
    protected function collectionExists(string $collection): bool
    {
        try {
            $response = Http::withHeaders($this->getHeaders())
                ->get("{$this->qdrantHost}/collections/{$collection}");

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a single index
     * 
     * @return string|int 'created', 'exists', 'failed', or Command constant
     */
    protected function createIndex(string $collection, string $field, string $type, bool $silent = false): string|int
    {
        if (!$silent) {
            $this->info("Creating index for field '{$field}' (type: {$type}) in collection '{$collection}'...");
        }

        try {
            $response = Http::withHeaders($this->getHeaders())
                ->put("{$this->qdrantHost}/collections/{$collection}/index", [
                    'field_name' => $field,
                    'field_schema' => $type,
                ]);

            if ($response->successful()) {
                if ($silent) {
                    $this->line("   ✅ Created index: {$field} ({$type})");
                } else {
                    $this->info("✅ Successfully created index for '{$field}' in '{$collection}'");
                }
                
                Log::channel('ai-engine')->info('Created Qdrant index', [
                    'collection' => $collection,
                    'field' => $field,
                    'type' => $type,
                ]);
                
                return $silent ? 'created' : Command::SUCCESS;
            }

            // Check if index already exists
            if ($response->status() === 400 && str_contains($response->body(), 'already exists')) {
                if ($silent) {
                    $this->line("   ⏭️  Index exists: {$field}");
                } else {
                    $this->warn("⚠️ Index for '{$field}' already exists in '{$collection}'");
                }
                return $silent ? 'exists' : Command::SUCCESS;
            }

            if ($silent) {
                $this->line("   ❌ Failed: {$field} - " . $response->body());
            } else {
                $this->error("❌ Failed to create index: " . $response->body());
            }
            
            Log::channel('ai-engine')->error('Failed to create Qdrant index', [
                'collection' => $collection,
                'field' => $field,
                'type' => $type,
                'response' => $response->body(),
            ]);
            
            return $silent ? 'failed' : Command::FAILURE;

        } catch (\Exception $e) {
            if ($silent) {
                $this->line("   ❌ Error: {$field} - " . $e->getMessage());
            } else {
                $this->error("❌ Error: " . $e->getMessage());
            }
            
            Log::channel('ai-engine')->error('Exception creating Qdrant index', [
                'collection' => $collection,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
            
            return $silent ? 'failed' : Command::FAILURE;
        }
    }

    /**
     * Get HTTP headers for Qdrant API
     */
    protected function getHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->qdrantApiKey) {
            $headers['api-key'] = $this->qdrantApiKey;
        }
        return $headers;
    }
}
