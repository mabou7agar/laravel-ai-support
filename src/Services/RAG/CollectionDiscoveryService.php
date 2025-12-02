<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\Node\NodeHttpClient;

/**
 * Collection Discovery Service
 * 
 * Discovers Vectorizable model collections from local and remote nodes
 */
class CollectionDiscoveryService
{
    protected $nodeRegistry = null;
    
    public function __construct()
    {
        // Lazy load node registry if available
        if (class_exists(\LaravelAIEngine\Services\Node\NodeRegistryService::class)) {
            $this->nodeRegistry = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class);
        }
    }
    
    /**
     * Get all available collections from all nodes
     */
    public function getAllCollections(): array
    {
        $collections = [];
        
        // Get local collections
        $localCollections = $this->discoverLocal();
        foreach ($localCollections as $collection) {
            $collections[] = $collection['class'];
        }
        
        // Get collections from remote nodes if federated search enabled
        if ($this->nodeRegistry && config('ai-engine.nodes.enabled', false)) {
            $nodes = $this->nodeRegistry->getActiveNodes();
            
            foreach ($nodes as $node) {
                try {
                    $response = NodeHttpClient::make()
                        ->get($node->url . '/api/ai-engine/collections');
                    
                    if ($response->successful()) {
                        $data = $response->json();
                        foreach ($data['collections'] ?? [] as $collection) {
                            $collections[] = $collection['class'];
                        }
                    }
                } catch (\Exception $e) {
                    // Skip failed nodes
                    Log::channel('ai-engine')->debug('Failed to get collections from node', [
                        'node' => $node->slug,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        return array_unique($collections);
    }
    
    /**
     * Discover local Vectorizable collections
     */
    public function discoverLocal(): array
    {
        $collections = [];
        $modelsPath = app_path('Models');
        
        if (!is_dir($modelsPath)) {
            return $collections;
        }
        
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($modelsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } catch (\Exception $e) {
            Log::error('Failed to iterate models directory', [
                'path' => $modelsPath,
                'error' => $e->getMessage(),
            ]);
            return $collections;
        }
        
        foreach ($files as $file) {
            try {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }
                
                $relativePath = str_replace($modelsPath . '/', '', $file->getPathname());
                $className = 'App\\Models\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);
                
                // Try to check if class exists without triggering fatal errors
                try {
                    if (!class_exists($className, true)) {
                        continue;
                    }
                } catch (\Error $e) {
                    // Fatal error during class loading (e.g., static method conflicts)
                    Log::debug('Cannot load class during collection discovery', [
                        'class' => $className,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
                
                // Check if class uses Vectorizable trait
                try {
                    $uses = class_uses_recursive($className);
                    $hasVectorizable = in_array(\LaravelAIEngine\Traits\Vectorizable::class, $uses) ||
                                      in_array(\LaravelAIEngine\Traits\VectorizableWithMedia::class, $uses);
                } catch (\Error $e) {
                    Log::debug('Cannot check traits for class', [
                        'class' => $className,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
                
                if ($hasVectorizable) {
                    // Safely get table name
                    try {
                        $instance = new $className;
                        $tableName = $instance->getTable();
                    } catch (\Exception | \Error $e) {
                        $tableName = 'unknown';
                        Log::debug('Cannot instantiate class for table name', [
                            'class' => $className,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    
                    $collections[] = [
                        'class' => $className,
                        'name' => class_basename($className),
                        'table' => $tableName,
                    ];
                }
            } catch (\Exception | \Error $e) {
                // Skip problematic files
                Log::debug('Skipped model file during collection discovery', [
                    'file' => $file->getPathname(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }
        
        return $collections;
    }
}
