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
    protected $nodeRegistry = null;

    public function __construct()
    {
        $this->cacheTtl = config('ai-engine.intelligent_rag.discovery_cache_ttl', 3600);
        $this->autoDiscover = config('ai-engine.intelligent_rag.auto_discover', true);
        
        // Lazy load node registry if available
        if (class_exists(\LaravelAIEngine\Services\Node\NodeRegistryService::class)) {
            $this->nodeRegistry = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class);
        }
    }

    /**
     * Discover all RAG collections (local + federated)
     *
     * @param bool $useCache Whether to use cached results
     * @param bool $includeFederated Whether to include collections from remote nodes
     * @return array Array of model class names
     */
    public function discover(bool $useCache = true, bool $includeFederated = true): array
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

        // Discover local collections
        $collections = $this->discoverFromModels();
        
        // Discover from remote nodes if enabled
        if ($includeFederated && $this->nodeRegistry && config('ai-engine.nodes.enabled', false)) {
            $federatedCollections = $this->discoverFromNodes();
            $collections = array_unique(array_merge($collections, $federatedCollections));
        }

        // Cache results
        Cache::put($this->cacheKey, $collections, $this->cacheTtl);

        return $collections;
    }
    
    /**
     * Discover collections from remote nodes
     *
     * @return array
     */
    protected function discoverFromNodes(): array
    {
        $collections = [];
        
        try {
            $nodes = $this->nodeRegistry->getActiveNodes();
            
            foreach ($nodes as $node) {
                try {
                    $response = \LaravelAIEngine\Services\Node\NodeHttpClient::make()
                        ->get($node->url . '/api/ai-engine/collections');
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        foreach ($data['collections'] ?? [] as $collection) {
                            $collections[] = $collection['class'];
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug('Failed to get collections from node', [
                        'node' => $node->slug,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to discover collections from nodes', [
                'error' => $e->getMessage(),
            ]);
        }
        
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
            app_path('Models'),
        ]);

        foreach ($discoveryPaths as $path) {
            // Handle glob patterns (e.g., modules/*/Models)
            if (str_contains($path, '*')) {
                $collections = array_merge($collections, $this->discoverFromGlobPath($path));
            } else {
                $collections = array_merge($collections, $this->discoverFromSinglePath($path));
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
     * @param string|null $namespace (optional, will auto-detect if not provided)
     * @return array
     */
    protected function discoverFromSinglePath(string $path, ?string $namespace = null): array
    {
        $collections = [];

        if (!File::isDirectory($path)) {
            return [];
        }

        try {
            // Get all PHP files recursively (including subdirectories)
            $files = File::allFiles($path);

            foreach ($files as $file) {
                // Auto-detect the class name from the file content
                $className = $this->extractClassNameFromFile($file);

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
     * @return array
     */
    protected function discoverFromGlobPath(string $globPath): array
    {
        $collections = [];
        $matchedPaths = glob($globPath, GLOB_ONLYDIR);

        foreach ($matchedPaths as $matchedPath) {
            $collections = array_merge($collections, $this->discoverFromSinglePath($matchedPath));
        }

        return $collections;
    }

    /**
     * Extract the fully qualified class name from a PHP file
     * Reads the file content to get the actual namespace and class name
     * 
     * @param \SplFileInfo $file
     * @return string|null
     */
    protected function extractClassNameFromFile(\SplFileInfo $file): ?string
    {
        try {
            $content = file_get_contents($file->getRealPath());
            
            // Extract namespace
            $namespace = null;
            if (preg_match('/namespace\s+([^;]+);/i', $content, $matches)) {
                $namespace = trim($matches[1]);
            }
            
            // Extract class name
            $className = null;
            if (preg_match('/class\s+(\w+)/i', $content, $matches)) {
                $className = trim($matches[1]);
            }
            
            // Build fully qualified class name
            if ($className) {
                return $namespace ? $namespace . '\\' . $className : $className;
            }
            
        } catch (\Exception $e) {
            Log::debug('Failed to extract class name from file', [
                'file' => $file->getRealPath(),
                'error' => $e->getMessage(),
            ]);
        }
        
        return null;
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
