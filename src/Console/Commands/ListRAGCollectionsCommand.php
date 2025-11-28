<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

class ListRAGCollectionsCommand extends Command
{
    protected $signature = 'ai-engine:list-rag-collections
                            {--refresh : Refresh the cache}';

    protected $description = 'List all discovered RAG collections';

    public function handle(RAGCollectionDiscovery $discovery): int
    {
        $this->info('🔍 Discovering RAG Collections...');
        $this->newLine();

        if ($this->option('refresh')) {
            $discovery->clearCache();
            $this->warn('Cache cleared. Discovering fresh...');
        }

        $collections = $discovery->discover(!$this->option('refresh'));

        if (empty($collections)) {
            $this->warn('⚠️  No RAG collections found!');
            $this->newLine();
            $this->line('To make a model RAG-enabled, add one of these traits:');
            $this->line('  - use LaravelAIEngine\Traits\Vectorizable;');
            $this->line('  - use LaravelAIEngine\Traits\RAGgable;');
            $this->newLine();
            $this->line('Example:');
            $this->line('  class Document extends Model');
            $this->line('  {');
            $this->line('      use Vectorizable, RAGgable;');
            $this->line('  }');
            return self::SUCCESS;
        }

        $this->info("✅ Found {count($collections)} RAG collection(s):");
        $this->newLine();

        $tableData = [];
        foreach ($collections as $index => $collection) {
            $priority = $this->getModelPriority($collection);
            $isIndexed = $this->checkIfIndexed($collection);
            
            $tableData[] = [
                $index + 1,
                class_basename($collection),
                $collection,
                $priority,
                $isIndexed ? '✅' : '❌',
            ];
        }

        $this->table(
            ['#', 'Model', 'Class', 'Priority', 'Indexed'],
            $tableData
        );

        $this->newLine();
        $stats = $discovery->getStatistics();
        $this->info('📊 Statistics:');
        $this->line("  Total Collections: {$stats['total']}");
        $this->line("  Cached: " . ($stats['cached'] ? 'Yes' : 'No'));
        $this->line("  Cache TTL: {$stats['cache_ttl']} seconds");

        $this->newLine();
        $this->info('💡 Tips:');
        $this->line('  - Index models: php artisan ai-engine:vector-index "App\Models\YourModel"');
        $this->line('  - Set priority: Add protected $ragPriority = 80; to your model');
        $this->line('  - Refresh cache: php artisan ai-engine:list-rag-collections --refresh');

        return self::SUCCESS;
    }

    protected function getModelPriority(string $className): int
    {
        try {
            $instance = new $className();
            if (method_exists($instance, 'getRAGPriority')) {
                return $instance->getRAGPriority();
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return 50;
    }

    protected function checkIfIndexed(string $className): bool
    {
        try {
            $instance = new $className();
            $tableName = $instance->getTable();
            $collectionName = config('ai-engine.vector.collection_prefix', 'vec_') . $tableName;
            
            // Check if collection exists in vector database
            $driver = app(\LaravelAIEngine\Services\Vector\VectorDriverManager::class)->driver();
            return $driver->collectionExists($collectionName);
        } catch (\Exception $e) {
            return false;
        }
    }
}
