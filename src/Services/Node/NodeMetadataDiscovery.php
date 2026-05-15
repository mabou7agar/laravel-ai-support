<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Auto-discover node metadata from application structure.
 */
class NodeMetadataDiscovery
{
    /**
     * Discover all node metadata
     */
    public function discover(): array
    {
        return [
            'description' => $this->generateDescription(),
            'capabilities' => $this->discoverCapabilities(),
            'domains' => $this->discoverDomains(),
            'data_types' => $this->discoverDataTypes(),
            'keywords' => $this->discoverKeywords(),
            'collections' => $this->discoverCollections(),
        ];
    }

    /**
     * Generate description from discovered metadata
     */
    protected function generateDescription(): string
    {
        $models = $this->discoverCollections();
        
        if (empty($models)) {
            return config('ai-engine.nodes.description', '');
        }

        $parts = [];
        
        $entities = [];

        foreach ($models as $model) {
            $entities[] = $model['name'];
        }
        
        $entities = array_values(array_unique($entities));
        
        if (!empty($entities)) {
            $entityList = $this->formatList($entities);
            $parts[] = "Manages {$entityList}";
        }
        
        // Add search capability if vectorizable models exist
        if (!empty($models)) {
            $searchableEntities = array_map(function($m) {
                return $m['name'];
            }, $models);
            $parts[] = "Supports semantic search across " . $this->formatList($searchableEntities);
        }
        
        $parts[] = "Handles business operations and data management";
        
        return implode('. ', $parts) . '.';
    }

    /**
     * Discover available capabilities
     */
    protected function discoverCapabilities(): array
    {
        $capabilities = [];
        
        // Check for vectorizable models
        if (!empty($this->discoverCollections())) {
            $capabilities[] = 'rag';
            $capabilities[] = 'search';
        }
        
        // Check for actions
        if ($this->hasActions()) {
            $capabilities[] = 'actions';
        }
        
        return array_values(array_unique(array_merge(
            $capabilities,
            config('ai-engine.nodes.capabilities', [])
        )));
    }

    /**
     * Discover business domains
     */
    protected function discoverDomains(): array
    {
        $domains = [];
        $models = $this->discoverCollections();
        
        $domainMap = (array) config('ai-engine.nodes.model_domain_map', []);
        
        foreach ($models as $model) {
            $modelName = $model['name'];
            if (isset($domainMap[$modelName])) {
                $domains = array_merge($domains, $domainMap[$modelName]);
            }
        }
        
        return array_values(array_unique(array_merge(
            $domains,
            config('ai-engine.nodes.domains', [])
        )));
    }

    /**
     * Discover data types from models
     */
    protected function discoverDataTypes(): array
    {
        $dataTypes = [];
        $models = $this->discoverCollections();
        
        foreach ($models as $model) {
            $dataTypes[] = $model['name'];
        }
        
        return array_values(array_unique(array_merge(
            $dataTypes,
            config('ai-engine.nodes.data_types', [])
        )));
    }

    /**
     * Discover keywords from models.
     */
    protected function discoverKeywords(): array
    {
        $keywords = [];
        
        // Add model names
        $models = $this->discoverCollections();
        foreach ($models as $model) {
            $keywords[] = $model['name'];
            $keywords[] = strtolower(Str::plural($model['name']));
        }
        
        return array_values(array_unique(array_merge(
            $keywords,
            config('ai-engine.nodes.keywords', [])
        )));
    }

    /**
     * Discover vectorizable model collections with metadata
     * Returns array of collection info for node advertisement
     */
    protected function discoverCollections(): array
    {
        $collections = [];
        
        // Get paths from config (supports modular architecture)
        $discoveryPaths = config('ai-engine.rag.discovery_paths', [
            app_path('Models'),
        ]);
        
        foreach ($discoveryPaths as $pathPattern) {
            // Support glob patterns like 'modules/*/Models'
            $paths = glob($pathPattern);
            if (empty($paths)) {
                $paths = [$pathPattern]; // Not a glob, use as-is
            }
            
            foreach ($paths as $modelsPath) {
                if (!File::exists($modelsPath)) {
                    continue;
                }
                
                $files = File::allFiles($modelsPath);
                
                foreach ($files as $file) {
                    $className = $this->getClassFromFile($file->getPathname());
                    
                    if ($className && $this->isVectorizable($className)) {
                        try {
                            $instance = new $className;
                            $modelName = class_basename($className);
                            $displayName = method_exists($instance, 'getRAGDisplayName')
                                ? $instance->getRAGDisplayName()
                                : $modelName;
                            
                            // Get description from model if available
                            $description = method_exists($instance, 'getModelDescription')
                                ? $instance->getModelDescription()
                                : "Model for {$modelName} data";
                            $aliases = $this->discoverCollectionAliases($instance, $className);
                            
                            // Get table name
                            $table = method_exists($instance, 'getTable')
                                ? $instance->getTable()
                                : strtolower(Str::snake(Str::plural($modelName)));
                            
                            // Check for CRUD tools
                            $hasTools = $this->hasModelTools($className);
                            
                            $collections[] = [
                                'name' => strtolower($modelName),
                                'class' => $className,
                                'display_name' => $displayName,
                                'table' => $table,
                                'description' => $description,
                                'aliases' => $aliases,
                                'capabilities' => [
                                    'db_query' => true,
                                    'db_count' => true,
                                    'vector_search' => true,
                                    'crud' => $hasTools,
                                ],
                            ];
                        } catch (\Exception $e) {
                            // Skip models that can't be instantiated
                            continue;
                        }
                    }
                }
            }
        }

        return $collections;
    }

    protected function discoverCollectionAliases(object $instance, string $className): array
    {
        $aliases = [];

        if (method_exists($instance, 'getRAGAliases')) {
            $aliases = $instance->getRAGAliases();
        } elseif (method_exists($className, 'getRAGAliases')) {
            $aliases = $className::getRAGAliases();
        }

        if (!is_array($aliases)) {
            return [];
        }

        return array_values(array_filter(array_unique(array_map(static function ($alias): string {
            return trim((string) $alias);
        }, $aliases))));
    }
    
    /**
     * Check if model has CRUD tools via AutonomousModelConfig
     */
    protected function hasModelTools(string $modelClass): bool
    {
        $modelName = class_basename($modelClass);
        $namespace = substr($modelClass, 0, strrpos($modelClass, '\\'));
        $baseNamespace = substr($namespace, 0, strpos($namespace, '\\'));
        
        $possibleConfigs = [
            "{$baseNamespace}\\AI\\Configs\\{$modelName}ModelConfig",
            "App\\AI\\Configs\\{$modelName}ModelConfig",
        ];
        
        foreach ($possibleConfigs as $configClass) {
            if (class_exists($configClass) && 
                is_subclass_of($configClass, \LaravelAIEngine\Contracts\AutonomousModelConfig::class)) {
                try {
                    $tools = $configClass::getTools();
                    return !empty($tools);
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if application has actions
     */
    protected function hasActions(): bool
    {
        $actionPaths = [
            app_path('Actions'),
            app_path('AI/Actions'),
            app_path('Domain/Actions'),
        ];
        
        foreach ($actionPaths as $path) {
            if (File::exists($path) && count(File::allFiles($path)) > 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get class name from file path
     */
    protected function getClassFromFile(string $filePath): ?string
    {
        $content = File::get($filePath);
        
        // Extract namespace
        if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            return null;
        }
        
        // Extract class name
        if (!preg_match('/class\s+(\w+)/', $content, $classMatch)) {
            return null;
        }
        
        return $namespaceMatch[1] . '\\' . $classMatch[1];
    }

    /**
     * Check if class is vectorizable
     */
    protected function isVectorizable(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }
        
        try {
            $reflection = new ReflectionClass($className);
            $traits = $reflection->getTraitNames();
            
            return in_array('LaravelAIEngine\Traits\Vectorizable', $traits) ||
                   in_array('LaravelAIEngine\Traits\HasVectorSearch', $traits);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Format list of items for description
     */
    protected function formatList(array $items, int $max = 5): string
    {
        $items = array_slice($items, 0, $max);
        
        if (count($items) === 1) {
            return $items[0];
        }
        
        if (count($items) === 2) {
            return implode(' and ', $items);
        }
        
        $last = array_pop($items);
        return implode(', ', $items) . ', and ' . $last;
    }
}
