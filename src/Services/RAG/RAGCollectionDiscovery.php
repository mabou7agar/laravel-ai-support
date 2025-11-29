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
     * Discover RAG collections from configured paths (including subdirectories)
     * Supports multiple paths and glob patterns for modular architectures
     *
     * @return array
     */
    protected function discoverFromModels(): array
    {
        $collections = [];
        $discoveryPaths = config('ai-engine.intelligent_rag.discovery_paths', [
            [
                'path' => app_path('Models'),
                'namespace' => 'App\\Models',
            ],
        ]);

        foreach ($discoveryPaths as $pathConfig) {
            $path = $pathConfig['path'];
            $namespace = $pathConfig['namespace'];

            // Handle glob patterns (e.g., modules/*/Models)
            if (str_contains($path, '*')) {
                $collections = array_merge($collections, $this->discoverFromGlobPath($path, $namespace));
            } else {
                $collections = array_merge($collections, $this->discoverFromSinglePath($path, $namespace));
            }
        }

        // Remove duplicates and sort by priority
        $collections = array_unique($collections);
        $collections = $this->sortByPriority($collections);

        return $collections;
    }

    /**
     * Discover models from a single path
     *
     * @param string $path
     * @param string $namespace
     * @return array
     */
    protected function discoverFromSinglePath(string $path, string $namespace): array
    {
        $collections = [];

        if (!File::isDirectory($path)) {
            return [];
        }

        try {
            // Get all PHP files recursively (including subdirectories)
            $files = File::allFiles($path);

            foreach ($files as $file) {
                // Build the full class name from the file path
                $className = $this->getClassNameFromFile($file, $path, $namespace);

                if (!$className || !class_exists($className)) {
                    continue;
                }

                // Check if model uses Vectorizable trait
                if ($this->isRAGgable($className)) {
                    $collections[] = $className;
                }
            }

        } catch (\Exception $e) {
            Log::warning('Failed to discover RAG collections from path', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $collections;
    }

    /**
     * Discover models from glob pattern
     * Example: modules/star/Models where star is wildcard
     * 
     * @param string $globPath
     * @param string $namespacePattern
     * @return array
     */
    protected function discoverFromGlobPath(string $globPath, string $namespacePattern): array
    {
        $collections = [];
        $matchedPaths = glob($globPath, GLOB_ONLYDIR);

        foreach ($matchedPaths as $matchedPath) {
            // Extract module name from path for namespace replacement
            $moduleName = $this->extractModuleName($matchedPath, $globPath);
            $namespace = str_replace('{module}', $moduleName, $namespacePattern);

            $collections = array_merge($collections, $this->discoverFromSinglePath($matchedPath, $namespace));
        }

        return $collections;
    }

    /**
     * Extract module name from matched glob path
     *
     * @param string $matchedPath
     * @param string $globPattern
     * @return string
     */
    protected function extractModuleName(string $matchedPath, string $globPattern): string
    {
        // Remove base path and trailing /Models to get module name
        $pattern = str_replace('*', '([^/]+)', $globPattern);

        if (preg_match('#' . $pattern . '#', $matchedPath, $matches)) {
            return $matches[1] ?? 'Unknown';
        }

        return 'Unknown';
    }

    /**
     * Get the fully qualified class name from a file path
     * Handles nested directories (e.g., App\Models\Blog\Post, Modules\MailBox\Models\EmailCache)
     *
     * @param \SplFileInfo $file
     * @param string $basePath
     * @param string $baseNamespace
     * @return string|null
     */
    protected function getClassNameFromFile(\SplFileInfo $file, string $basePath, string $baseNamespace): ?string
    {
        // Get relative path from base directory
        $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getRealPath());

        // Remove .php extension
        $relativePath = str_replace('.php', '', $relativePath);

        // Convert directory separators to namespace separators
        $namespacePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

        // Build full class name
        // If there's a sub-path, append it; otherwise just use the filename
        if (!empty($namespacePath)) {
            return rtrim($baseNamespace, '\\') . '\\' . $namespacePath;
        }

        return $baseNamespace;
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
