<?php

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Auto-discover node metadata from application structure
 * Scans for workflows, models, and capabilities
 */
class NodeMetadataDiscovery
{
    protected const CACHE_KEY = 'node_local_metadata';

    /**
     * Discover all node metadata (cached to avoid filesystem scanning every message).
     */
    public function discover(): array
    {
        $ttl = max(1, (int) config('ai-engine.nodes.local_metadata_cache_ttl_minutes', 30));

        return Cache::remember(self::CACHE_KEY, now()->addMinutes($ttl), function () {
            return $this->discoverFresh();
        });
    }

    /**
     * Force a fresh discovery (bypasses cache).
     */
    public function discoverFresh(): array
    {
        return [
            'description' => $this->generateDescription(),
            'capabilities' => $this->discoverCapabilities(),
            'domains' => $this->discoverDomains(),
            'data_types' => $this->discoverDataTypes(),
            'keywords' => $this->discoverKeywords(),
            'collections' => $this->discoverCollections(),
            'workflows' => $this->discoverWorkflows(),
        ];
    }

    /**
     * Invalidate the cached metadata (call after deploy or config change).
     */
    public function refresh(): array
    {
        Cache::forget(self::CACHE_KEY);
        return $this->discover();
    }

    /**
     * Generate description from discovered metadata
     */
    protected function generateDescription(): string
    {
        $workflows = $this->discoverWorkflows();
        $models = $this->discoverCollections();
        
        if (empty($workflows) && empty($models)) {
            return config('ai-engine.nodes.description', '');
        }

        $parts = [];
        
        // Extract entity types from workflows
        $entities = [];
        foreach ($workflows as $workflow) {
            $className = class_basename($workflow);
            // Extract entity from workflow name (e.g., CreateInvoiceWorkflow -> invoice)
            if (preg_match('/(Create|Update|Delete|Manage)(\w+)Workflow/i', $className, $matches)) {
                $entities[] = strtolower($matches[2]);
            }
        }
        
        // Extract entity types from models
        foreach ($models as $model) {
            // Handle new format (array with metadata) or legacy format (class string)
            if (is_array($model)) {
                $entities[] = $model['name']; // Already lowercase from discovery
            } else {
                $className = class_basename($model);
                $entities[] = strtolower(Str::snake($className));
            }
        }
        
        $entities = array_values(array_unique($entities));
        
        if (!empty($entities)) {
            $entityList = $this->formatList($entities);
            $parts[] = "Manages {$entityList}";
        }
        
        if (!empty($workflows)) {
            $parts[] = "Provides " . count($workflows) . " automated workflows";
        }
        
        // Add search capability if vectorizable models exist
        if (!empty($models)) {
            $searchableEntities = array_map(function($m) {
                // Handle new format (array with metadata) or legacy format (class string)
                if (is_array($m)) {
                    return $m['name'];
                }
                return strtolower(class_basename($m));
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
        
        // Check for workflows
        if (!empty($this->discoverWorkflows())) {
            $capabilities[] = 'workflows';
        }
        
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
        
        // Domain map: model name → business domains.
        // Merge config overrides with sensible defaults.
        $defaultDomainMap = [
            'invoice' => ['business', 'finance', 'accounting'],
            'bill' => ['business', 'finance', 'accounting'],
            'payment' => ['business', 'finance'],
            'customer' => ['business', 'crm'],
            'vendor' => ['business', 'procurement'],
            'product' => ['business', 'inventory', 'e-commerce'],
            'order' => ['business', 'e-commerce'],
            'user' => ['authentication', 'user-management'],
            'employee' => ['hr', 'business'],
            'course' => ['education', 'learning'],
            'student' => ['education', 'learning'],
            'patient' => ['healthcare', 'medical'],
            'appointment' => ['scheduling', 'business'],
        ];
        $domainMap = array_merge(
            $defaultDomainMap,
            (array) config('ai-engine.nodes.domain_map', [])
        );
        
        foreach ($models as $model) {
            // Handle new format (array with metadata) or legacy format (class string)
            $modelName = is_array($model) ? $model['name'] : strtolower(class_basename($model));
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
            // Handle new format (array with metadata) or legacy format (class string)
            if (is_array($model)) {
                $dataTypes[] = $model['name'];
            } else {
                $modelName = class_basename($model);
                $dataTypes[] = strtolower(Str::snake($modelName));
            }
        }
        
        return array_values(array_unique(array_merge(
            $dataTypes,
            config('ai-engine.nodes.data_types', [])
        )));
    }

    /**
     * Discover keywords from models and workflows
     */
    protected function discoverKeywords(): array
    {
        $keywords = [];
        
        // Add model names
        $models = $this->discoverCollections();
        foreach ($models as $model) {
            // Handle new format (array with metadata) or legacy format (class string)
            if (is_array($model)) {
                $keywords[] = $model['name'];
                $keywords[] = strtolower(Str::plural($model['name']));
            } else {
                $modelName = class_basename($model);
                $keywords[] = strtolower($modelName);
                $keywords[] = strtolower(Str::plural($modelName));
            }
        }
        
        // Add workflow action keywords
        $workflows = $this->discoverWorkflows();
        foreach ($workflows as $workflow) {
            $className = class_basename($workflow);
            // Extract action and entity
            if (preg_match('/(Create|Update|Delete|Manage|Search|List)(\w+)/i', $className, $matches)) {
                $keywords[] = strtolower($matches[1]); // action
                $keywords[] = strtolower($matches[2]); // entity
            }
        }
        
        return array_values(array_unique(array_merge(
            $keywords,
            config('ai-engine.nodes.keywords', [])
        )));
    }

    /**
     * Discover vectorizable model collections with metadata.
     * Delegates to RAGCollectionDiscovery for the actual model list,
     * then enriches each entry with metadata for node advertisement.
     */
    protected function discoverCollections(): array
    {
        $collections = [];

        try {
            $discovery = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
            $modelClasses = $discovery->discover(useCache: true, includeFederated: false);
        } catch (\Exception $e) {
            $modelClasses = [];
        }

        foreach ($modelClasses as $className) {
            // RAGCollectionDiscovery may return class strings or arrays
            if (is_array($className)) {
                $collections[] = $className;
                continue;
            }

            if (!class_exists($className)) {
                continue;
            }

            try {
                $instance = new $className;
                $modelName = class_basename($className);

                $description = method_exists($instance, 'getModelDescription')
                    ? $instance->getModelDescription()
                    : "Model for {$modelName} data";

                $table = method_exists($instance, 'getTable')
                    ? $instance->getTable()
                    : strtolower(Str::snake(Str::plural($modelName)));

                $hasTools = $this->hasModelTools($className);

                $collections[] = [
                    'name' => strtolower($modelName),
                    'class' => $className,
                    'table' => $table,
                    'description' => $description,
                    'capabilities' => [
                        'db_query' => true,
                        'db_count' => true,
                        'vector_search' => true,
                        'crud' => $hasTools,
                    ],
                ];
            } catch (\Exception $e) {
                continue;
            }
        }

        return $collections;
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
     * Discover workflow classes
     */
    protected function discoverWorkflows(): array
    {
        $workflows = [];
        
        // Check common workflow paths
        $paths = [
            app_path('AI/Workflows'),
            app_path('Workflows'),
            app_path('Domain/Workflows'),
        ];
        
        foreach ($paths as $path) {
            if (!File::exists($path)) {
                continue;
            }
            
            $files = File::allFiles($path);
            
            foreach ($files as $file) {
                $className = $this->getClassFromFile($file->getPathname());
                
                if ($className && $this->isWorkflow($className)) {
                    $workflows[] = $className;
                }
            }
        }
        
        return array_values(array_unique($workflows));
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
     * Check if class is a workflow
     */
    protected function isWorkflow(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }
        
        try {
            $reflection = new ReflectionClass($className);
            
            // Check if it implements BaseWorkflow or has workflow-like methods
            return $reflection->hasMethod('handle') || 
                   $reflection->hasMethod('execute') ||
                   $reflection->hasMethod('process') ||
                   Str::endsWith($className, 'Workflow');
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
