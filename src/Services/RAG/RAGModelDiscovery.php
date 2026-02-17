<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Contracts\AutonomousModelConfig;
use LaravelAIEngine\Services\Node\NodeRegistryService;

/**
 * Resolves model classes, config classes, and available model metadata
 * from AutonomousModelConfig definitions and RAG collection discovery.
 *
 * Single source of truth for "given a model name, what class/config/tools does it map to?"
 */
class RAGModelDiscovery
{
    /** @var array<string, string>|null Cached name→configClass map */
    protected ?array $configCache = null;

    public function __construct(
        protected ?RAGCollectionDiscovery $collectionDiscovery = null
    ) {
    }

    /**
     * Resolve a model name (e.g. "invoice") to its fully-qualified Eloquent class.
     *
     * Priority:
     *  1. AutonomousModelConfig::getModelClass() matched by getName()
     *  2. RAG collection discovery (array or legacy class-string format)
     */
    public function resolveModelClass(string $modelName, array $options = []): ?string
    {
        $modelName = strtolower(trim($modelName));

        // Priority 1: AutonomousModelConfig registry
        $configClass = $this->findConfigByName($modelName);
        if ($configClass && method_exists($configClass, 'getModelClass')) {
            return $configClass::getModelClass();
        }

        // Priority 2: RAG collections
        return $this->resolveFromCollections($modelName, $options);
    }

    /**
     * Find the AutonomousModelConfig class for a given model name.
     */
    public function findConfigByName(string $modelName): ?string
    {
        $modelName = strtolower(trim($modelName));
        $configs = $this->getAllConfigs();

        return $configs[$modelName] ?? null;
    }

    /**
     * Find the AutonomousModelConfig class for a given Eloquent model class.
     *
     * Priority:
     *  1. Scan the cached config registry (already discovered via getAllConfigs)
     *  2. Fall back to convention-based candidates using configured namespaces
     */
    public function findConfigByClass(string $modelClass): ?string
    {
        // Priority 1: check the cached registry — any config whose getModelClass() matches
        foreach ($this->getAllConfigs() as $configClass) {
            try {
                if (method_exists($configClass, 'getModelClass') && $configClass::getModelClass() === $modelClass) {
                    return $configClass;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Priority 2: convention-based candidates from configured namespaces
        $modelName = class_basename($modelClass);
        $discoveryConfig = (array) config('ai-agent.model_config_discovery', []);
        $defaultNs = rtrim((string) config('app.namespace', 'App\\'), '\\');
        $namespaces = $discoveryConfig['namespaces'] ?? [$defaultNs . '\\AI\\Configs'];

        $suffixes = ['ModelConfig', 'Config'];

        foreach ($namespaces as $ns) {
            foreach ($suffixes as $suffix) {
                $candidate = rtrim($ns, '\\') . '\\' . $modelName . $suffix;
                if (class_exists($candidate) && is_subclass_of($candidate, AutonomousModelConfig::class)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Get filter config for a model class (from AutonomousModelConfig or legacy collectors).
     */
    public function getFilterConfig(string $modelClass): array
    {
        $configClass = $this->findConfigByClass($modelClass);
        if ($configClass) {
            try {
                return $configClass::getFilterConfig();
            } catch (\Exception $e) {
                // Fall through
            }
        }

        // Fallback: legacy collector discovery
        try {
            $discoveryService = app(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class);
            $collectors = $discoveryService->discoverCollectors();

            foreach ($collectors as $collector) {
                if (($collector['model_class'] ?? null) === $modelClass) {
                    return $collector['filter_config'] ?? [];
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('RAGModelDiscovery: legacy filter config lookup failed', [
                'model_class' => $modelClass,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Get CRUD tools defined on the AutonomousModelConfig for a model class.
     */
    public function getToolsForModel(string $modelClass): array
    {
        $configClass = $this->findConfigByClass($modelClass);
        if (!$configClass) {
            return [];
        }

        try {
            $tools = $configClass::getTools();
            $formatted = [];

            foreach ($tools as $toolName => $toolConfig) {
                $formatted[$toolName] = [
                    'description' => $toolConfig['description'] ?? '',
                    'parameters' => $toolConfig['parameters'] ?? [],
                    'requires_confirmation' => $toolConfig['requires_confirmation'] ?? false,
                ];
            }

            return $formatted;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('RAGModelDiscovery: failed to get tools', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build the full model metadata array used by the decision service prompt.
     */
    public function getAvailableModels(array $options = []): array
    {
        $collections = $options['rag_collections'] ?? [];

        if (empty($collections) && $this->collectionDiscovery) {
            $collections = $this->collectionDiscovery->discover();
        }

        $models = [];

        foreach ($collections as $collection) {
            if (is_array($collection)) {
                $models[] = $this->buildModelFromArrayCollection($collection);
                continue;
            }

            $model = $this->buildModelFromClassCollection($collection);
            if ($model !== null) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * Get available remote nodes for routing context.
     */
    public function getAvailableNodes(): array
    {
        $nodes = collect();

        if (app()->bound(NodeRegistryService::class)) {
            try {
                $nodes = app(NodeRegistryService::class)->getActiveNodes();
            } catch (\Throwable $e) {
                Log::channel('ai-engine')->warning('RAGModelDiscovery: failed loading nodes', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($nodes->isEmpty()) {
            $nodes = \LaravelAIEngine\Models\AINode::active()->healthy()->get();
        }

        return $nodes->map(function ($node) {
            $collections = $node->collections ?? [];
            $models = [];

            if (!empty($collections) && is_array($collections)) {
                $first = reset($collections);
                if (is_array($first) && isset($first['name'])) {
                    $models = collect($collections)->map(fn($c) => [
                        'name' => $c['name'],
                        'description' => $c['description'] ?? "Model for {$c['name']} data",
                        'capabilities' => $c['capabilities'] ?? [],
                    ])->toArray();
                } else {
                    $models = collect($collections)->map(fn($c) => [
                        'name' => strtolower(class_basename($c)),
                        'description' => 'Model for ' . class_basename($c) . ' data',
                        'capabilities' => [],
                    ])->toArray();
                }
            }

            return [
                'slug' => $node->slug,
                'name' => $node->name,
                'description' => $node->description,
                'models' => $models,
                'collections' => $collections,
            ];
        })->toArray();
    }

    // ──────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────

    /**
     * Scan all AutonomousModelConfig classes and cache name→class mapping.
     */
    protected function getAllConfigs(): array
    {
        if ($this->configCache !== null) {
            return $this->configCache;
        }

        $this->configCache = [];
        $discoveryConfig = (array) config('ai-agent.model_config_discovery', []);
        $paths = $discoveryConfig['paths'] ?? [app_path('AI/Configs')];
        $defaultNs = rtrim((string) config('app.namespace', 'App\\'), '\\');
        $namespaces = $discoveryConfig['namespaces'] ?? [$defaultNs . '\\AI\\Configs'];

        foreach ($paths as $index => $path) {
            if (!is_string($path) || !is_dir($path)) {
                continue;
            }

            $files = glob(rtrim($path, '/\\') . '/*ModelConfig.php');
            $baseNamespace = $namespaces[$index] ?? ($namespaces[0] ?? null);
            if (!is_string($baseNamespace) || $baseNamespace === '') {
                continue;
            }

            foreach ($files as $file) {
                $className = rtrim($baseNamespace, '\\') . '\\' . basename($file, '.php');
                if (class_exists($className) && is_subclass_of($className, AutonomousModelConfig::class)) {
                    try {
                        $name = strtolower($className::getName());
                        $this->configCache[$name] = $className;
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }

        return $this->configCache;
    }

    protected function resolveFromCollections(string $modelName, array $options): ?string
    {
        $collections = $options['rag_collections'] ?? [];

        if (empty($collections) && $this->collectionDiscovery) {
            $collections = $this->collectionDiscovery->discover();
        }

        foreach ($collections as $collection) {
            if (is_array($collection)) {
                $collectionName = strtolower($collection['name'] ?? '');
                if ($this->nameMatches($collectionName, $modelName)) {
                    return $collection['class'] ?? null;
                }
            } elseif (is_string($collection)) {
                $baseName = strtolower(class_basename($collection));
                if ($this->nameMatches($baseName, $modelName)) {
                    return $collection;
                }
            }
        }

        return null;
    }

    protected function nameMatches(string $candidate, string $requested): bool
    {
        if ($candidate === '' || $requested === '') {
            return false;
        }

        return $candidate === $requested
            || $candidate === $requested . 's'
            || $candidate . 's' === $requested
            || str_contains($candidate, $requested);
    }

    protected function buildModelFromArrayCollection(array $collection): array
    {
        $collectionClass = $collection['class'] ?? '';
        $isLocal = $collectionClass !== '' && class_exists($collectionClass);

        return [
            'name' => $collection['name'] ?? 'unknown',
            'class' => $collectionClass,
            'table' => $collection['table'] ?? ($collection['name'] ?? 'item') . 's',
            'description' => $collection['description'] ?? "Model for {$collection['name']} data",
            'location' => $isLocal ? 'local' : 'remote',
            'capabilities' => $collection['capabilities'] ?? [
                'db_query' => true,
                'db_count' => true,
                'vector_search' => false,
                'crud' => false,
            ],
            'schema' => [],
            'filter_config' => [],
            'tools' => [],
        ];
    }

    protected function buildModelFromClassCollection(string $collection): ?array
    {
        if (!class_exists($collection)) {
            return null;
        }

        try {
            $instance = new $collection;
            $name = class_basename($collection);

            $hasVectorSearch = method_exists($instance, 'toVector')
                || in_array('LaravelAIEngine\Traits\Vectorizable', class_uses_recursive($collection));

            $schema = method_exists($instance, 'getModelSchema') ? $instance->getModelSchema() : [];
            $filterConfig = $this->getFilterConfig($collection);
            $tools = $this->getToolsForModel($collection);
            $description = method_exists($instance, 'getModelDescription')
                ? $instance->getModelDescription()
                : "Model for {$name} data";

            return [
                'name' => strtolower($name),
                'class' => $collection,
                'table' => $instance->getTable() ?? strtolower($name) . 's',
                'schema' => $schema,
                'filter_config' => $filterConfig,
                'tools' => $tools,
                'capabilities' => [
                    'db_query' => true,
                    'db_count' => true,
                    'vector_search' => $hasVectorSearch,
                    'crud' => !empty($tools),
                ],
                'description' => $description,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('RAGModelDiscovery: failed to inspect model', [
                'class' => $collection,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
