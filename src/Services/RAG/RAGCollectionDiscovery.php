<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\Node\NodeHttpClient;

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
        //if ($useCache) {
        //    $cached = Cache::get($this->cacheKey);
        //    if ($cached !== null) {
        //        return $cached;
        //    }
        //}

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

                        foreach ($node->collections ?? [] as $collection) {
                            $collections[] = $collection;
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
     * Get all collections with their RAG descriptions from all nodes
     *
     * @param bool $useCache
     * @return array
     */
    public function discoverWithDescriptions(bool $useCache = true): array
    {
        $cacheKey = 'ai_engine:rag_collections_with_descriptions';

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $allCollections = [];

        // Get local collections with descriptions
        $localCollections = $this->discover(useCache: $useCache, includeFederated: false);
        foreach ($localCollections as $className) {
            if (!isset($allCollections[$className])) {
                $allCollections[$className] = $this->getCollectionInfo($className, 'local');
            }
        }

        // Get remote collections with descriptions
        if ($this->nodeRegistry && config('ai-engine.nodes.enabled', false)) {
            try {
                $nodes = $this->nodeRegistry->getActiveNodes();

                foreach ($nodes as $node) {
                    try {
                        $response = NodeHttpClient::makeAuthenticated($node)
                            ->get($node->getApiUrl('collections'));

                        if ($response->successful()) {
                            $data = $response->json();
                            foreach ($data['collections'] ?? [] as $collection) {
                                $className = $collection['class'];

                                if (!isset($allCollections[$className])) {
                                    $description = $collection['description'] ?? '';

                                    // If no description provided, generate a default one with a warning
                                    if (empty($description)) {
                                        $name = $collection['name'];
                                        $description = "Search through {$name} collection";

                                        Log::warning('Remote RAG collection missing description - using auto-generated description', [
                                            'class' => $className,
                                            'node' => $node->name,
                                            'auto_description' => $description,
                                            'recommendation' => "Add getRAGDescription() method to {$className} on remote node for better AI selection",
                                        ]);
                                    }

                                    $allCollections[$className] = [
                                        'class' => $className,
                                        'name' => $collection['name'],
                                        'display_name' => $collection['display_name'] ?? $collection['name'],
                                        'description' => $description,
                                        'nodes' => [],
                                    ];
                                }

                                $allCollections[$className]['nodes'][] = [
                                    'node_id' => $node->id,
                                    'node_slug' => $node->slug,
                                    'node_name' => $node->name,
                                ];
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
        }

        $result = array_values($allCollections);
        Cache::put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get collection info for a local class
     *
     * @param string $className
     * @param string $nodeType
     * @return array
     */
    protected function getCollectionInfo(string $className, string $nodeType = 'local'): array
    {
        $name = class_basename($className);
        $description = '';
        $displayName = $name;

        try {
            // Try static method first (preferred)
            $reflection = new \ReflectionClass($className);

            if ($reflection->hasMethod('getRAGDescription')) {
                $method = $reflection->getMethod('getRAGDescription');
                if ($method->isStatic()) {
                    $description = $className::getRAGDescription();
                } else {
                    // Fallback to instance method
                    $instance = new $className();
                    $description = $instance->getRAGDescription();
                }
            }

            if ($reflection->hasMethod('getRAGDisplayName')) {
                $method = $reflection->getMethod('getRAGDisplayName');
                if ($method->isStatic()) {
                    $displayName = $className::getRAGDisplayName();
                } else {
                    // Fallback to instance method
                    if (!isset($instance)) {
                        $instance = new $className();
                    }
                    $displayName = $instance->getRAGDisplayName();
                }
            }
        } catch (\Exception $e) {
            // Ignore
        }

        // If no description provided, generate a default one with a warning
        if (empty($description)) {
            $description = "Search through {$name} collection";

            Log::warning('RAG collection missing description - using auto-generated description', [
                'class' => $className,
                'auto_description' => $description,
                'recommendation' => "Add getRAGDescription() method to {$className} for better AI selection",
            ]);
        }

        return [
            'class' => $className,
            'name' => $name,
            'display_name' => $displayName,
            'description' => $description,
            'nodes' => [
                [
                    'node_id' => null,
                    'node_slug' => 'master',
                    'node_name' => 'Master Node',
                ],
            ],
        ];
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
                try {
                    // Check if file contains Vectorizable trait BEFORE loading the class
                    $fileContent = file_get_contents($file->getRealPath());
                    if (!str_contains($fileContent, 'use LaravelAIEngine\Traits\Vectorizable') &&
                        !str_contains($fileContent, 'use LaravelAIEngine\Traits\VectorizableWithMedia')) {
                        // Skip files that don't use Vectorizable traits
                        continue;
                    }

                    // Auto-detect the class name from the file content
                    $className = $this->extractClassNameFromFile($file);

                    if (!$className) {
                        continue;
                    }

                    // Try to check if class exists - catch fatal errors
                    try {
                        if (!class_exists($className)) {
                            continue;
                        }
                    } catch (\Error $e) {
                        Log::debug('Cannot load class during RAG discovery', [
                            'class' => $className,
                            'file' => $file->getPathname(),
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }

                    // Double-check if model uses Vectorizable trait (in case of false positive from file content check)
                    if ($this->isRAGgable($className)) {
                        $collections[] = $className;
                    }
                } catch (\Exception | \Error $e) {
                    Log::debug('Skipped file during RAG discovery', [
                        'file' => $file->getPathname(),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
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

        // Check for both Vectorizable and VectorizableWithMedia traits
        return isset($uses['LaravelAIEngine\Traits\Vectorizable'])
            || isset($uses['LaravelAIEngine\Traits\VectorizableWithMedia']);
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
