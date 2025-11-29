<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RAG Collection Discovery Service
 * 
 * Automatically discovers models that can be used for RAG:
 * 1. Models with Vectorizable trait
 * 2. Models with RAGgable trait
 */
class RAGCollectionDiscovery
{
    protected string $cacheKey = 'ai_engine:rag_collections';
    protected int $cacheTtl;
    protected bool $autoDiscover;

    public function __construct()
    {
        $this->cacheTtl = config('ai-engine.intelligent_rag.discovery_cache_ttl', 3600);
        $this->autoDiscover = config('ai-engine.intelligent_rag.auto_discover', true);
    }

    /**
     * Discover all RAG collections
     * 
     * @param bool $useCache Whether to use cached results
     * @return array Array of model class names
     */
    public function discover(bool $useCache = true): array
    {
        // Check cache first
        if ($useCache) {
            $cached = Cache::get($this->cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Get from config first
        $configCollections = config('ai-engine.intelligent_rag.default_collections', []);
        if (!empty($configCollections)) {
            Cache::put($this->cacheKey, $configCollections, $this->cacheTtl);
            return $configCollections;
        }

        // Auto-discover if enabled
        if (!$this->autoDiscover) {
            return [];
        }

        $collections = $this->discoverFromModels();

        // Cache results
        Cache::put($this->cacheKey, $collections, $this->cacheTtl);

        return $collections;
    }

    /**
     * Discover RAG collections from app/Models directory (including subdirectories)
     * 
     * @return array
     */
    protected function discoverFromModels(): array
    {
        $collections = [];
        $modelsPath = app_path('Models');

        if (!File::isDirectory($modelsPath)) {
            return [];
        }

        try {
            // Get all PHP files recursively (including subdirectories)
            $files = File::allFiles($modelsPath);

            foreach ($files as $file) {
                // Build the full class name from the file path
                $className = $this->getClassNameFromFile($file, $modelsPath);

                if (!$className || !class_exists($className)) {
                    continue;
                }

                // Check if model uses Vectorizable or RAGgable traits
                if ($this->isRAGgable($className)) {
                    $collections[] = $className;
                }
            }

            // Sort by priority if RAGgable
            $collections = $this->sortByPriority($collections);

        } catch (\Exception $e) {
            Log::warning('Failed to discover RAG collections', [
                'error' => $e->getMessage(),
            ]);
        }

        return $collections;
    }

    /**
     * Get the fully qualified class name from a file path
     * Handles nested directories (e.g., App\Models\Blog\Post)
     * 
     * @param \SplFileInfo $file
     * @param string $basePath
     * @return string|null
     */
    protected function getClassNameFromFile(\SplFileInfo $file, string $basePath): ?string
    {
        // Get relative path from base Models directory
        $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getRealPath());
        
        // Remove .php extension
        $relativePath = str_replace('.php', '', $relativePath);
        
        // Convert directory separators to namespace separators
        $namespacePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        
        // Build full class name
        return 'App\\Models\\' . $namespacePath;
    }

    /**
     * Check if a class is RAGgable
     * 
     * @param string $className
     * @return bool
     */
    protected function isRAGgable(string $className): bool
    {
        $uses = class_uses_recursive($className);

        // Only check for Vectorizable trait (all-in-one trait)
        return isset($uses['LaravelAIEngine\Traits\Vectorizable']);
    }

    /**
     * Sort collections by RAG priority
     * 
     * @param array $collections
     * @return array
     */
    protected function sortByPriority(array $collections): array
    {
        usort($collections, function ($a, $b) {
            $priorityA = $this->getModelPriority($a);
            $priorityB = $this->getModelPriority($b);

            return $priorityB <=> $priorityA; // Descending order
        });

        return $collections;
    }

    /**
     * Get priority for a model class
     * 
     * @param string $className
     * @return int
     */
    protected function getModelPriority(string $className): int
    {
        try {
            $instance = new $className();
            
            if (method_exists($instance, 'getRAGPriority')) {
                return $instance->getRAGPriority();
            }
        } catch (\Exception $e) {
            // Ignore errors
        }

        return 50; // Default priority
    }

    /**
     * Clear the discovery cache
     * 
     * @return void
     */
    public function clearCache(): void
    {
        Cache::forget($this->cacheKey);
    }

    /**
     * Get statistics about discovered collections
     * 
     * @return array
     */
    public function getStatistics(): array
    {
        $collections = $this->discover();

        return [
            'total' => count($collections),
            'collections' => $collections,
            'cached' => Cache::has($this->cacheKey),
            'cache_ttl' => $this->cacheTtl,
        ];
    }

    /**
     * Check if a specific model is discoverable
     * 
     * @param string $className
     * @return bool
     */
    public function isDiscoverable(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        return $this->isRAGgable($className);
    }
}
