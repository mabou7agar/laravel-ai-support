<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;

class RAGModelMetadataService
{
    public function __construct(
        protected ?RAGCollectionDiscovery $discovery = null,
        protected ?RAGDecisionStateService $stateService = null,
        protected ?AutonomousCollectorDiscoveryService $collectorDiscovery = null
    ) {
        $this->discovery = $discovery ?? (app()->bound(RAGCollectionDiscovery::class) ? app(RAGCollectionDiscovery::class) : null);
        $this->stateService = $stateService ?? new RAGDecisionStateService(new RAGDecisionPolicy());
        $this->collectorDiscovery = $collectorDiscovery ?? (app()->bound(AutonomousCollectorDiscoveryService::class)
            ? app(AutonomousCollectorDiscoveryService::class)
            : null);
    }

    public function getAvailableModels(array $options): array
    {
        $collections = $this->resolveCollections($options);
        $models = [];

        foreach ($collections as $collection) {
            if (is_array($collection)) {
                $collectionClass = $collection['class'];
                $isLocal = class_exists($collectionClass);

                $models[] = [
                    'name' => $collection['name'],
                    'class' => $collectionClass,
                    'display_name' => $collection['display_name'] ?? $collection['name'],
                    'table' => $collection['table'] ?? $collection['name'] . 's',
                    'description' => $collection['description'] ?? "Model for {$collection['name']} data",
                    'aliases' => array_values((array) ($collection['aliases'] ?? [])),
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
                continue;
            }

            if (!class_exists($collection)) {
                continue;
            }

            try {
                $instance = new $collection();
                $name = class_basename($collection);
                $hasVectorSearch = method_exists($instance, 'toVector')
                    || in_array('LaravelAIEngine\\Traits\\Vectorizable', class_uses_recursive($collection));

                $models[] = [
                    'name' => strtolower($name),
                    'class' => $collection,
                    'display_name' => method_exists($instance, 'getRAGDisplayName')
                        ? $instance->getRAGDisplayName()
                        : $name,
                    'table' => $instance->getTable() ?? strtolower($name) . 's',
                    'schema' => method_exists($instance, 'getModelSchema') ? $instance->getModelSchema() : [],
                    'filter_config' => $this->getFilterConfigForModel($collection),
                    'tools' => $this->getToolsForModel($collection),
                    'capabilities' => [
                        'db_query' => true,
                        'db_count' => true,
                        'vector_search' => $hasVectorSearch,
                        'crud' => !empty($this->getToolsForModel($collection)),
                    ],
                    'description' => method_exists($instance, 'getModelDescription')
                        ? $instance->getModelDescription()
                        : "Model for {$name} data",
                    'aliases' => $this->getModelAliases($instance, $collection),
                ];
            } catch (\Throwable $e) {
                Log::channel('ai-engine')->warning('Failed to inspect model', [
                    'class' => $collection,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $models;
    }

    protected function getModelAliases(object $instance, string $collection): array
    {
        $aliases = [];

        if (method_exists($instance, 'getRAGAliases')) {
            $aliases = $instance->getRAGAliases();
        } elseif (method_exists($collection, 'getRAGAliases')) {
            $aliases = $collection::getRAGAliases();
        }

        if (!is_array($aliases)) {
            return [];
        }

        return array_values(array_filter(array_unique(array_map(static function ($alias): string {
            return trim((string) $alias);
        }, $aliases))));
    }

    public function findModelClass(string $modelName, array $options): ?string
    {
        if (class_exists($modelName)) {
            return $modelName;
        }

        $modelName = strtolower(trim($modelName));
        if ($modelName === '') {
            return null;
        }

        $configClass = $this->findModelConfigByName($modelName);
        if ($configClass && method_exists($configClass, 'getModelClass')) {
            return $configClass::getModelClass();
        }

        foreach ($this->resolveCollections($options) as $collection) {
            if (is_array($collection)) {
                $collectionName = $collection['name'] ?? '';
                $collectionClass = $collection['class'] ?? '';

                if ($this->matchesModelName($collectionName, $modelName)) {
                    return $collectionClass;
                }

                continue;
            }

            $baseName = strtolower(class_basename($collection));
            if ($this->matchesModelName($baseName, $modelName)) {
                return $collection;
            }
        }

        $collectorModelClass = $this->findModelClassFromCollectors($modelName);
        if ($collectorModelClass) {
            return $collectorModelClass;
        }

        $inferred = $this->inferLocalModelClass($modelName);
        if ($inferred) {
            return $inferred;
        }

        return null;
    }

    public function findModelConfigByName(string $modelName): ?string
    {
        $modelName = strtolower($modelName);
        $path = app_path('AI/Configs');
        if (!is_dir($path)) {
            return null;
        }

        foreach (glob($path . '/*ModelConfig.php') as $file) {
            $fullClass = 'App\\AI\\Configs\\' . basename($file, '.php');
            if (!class_exists($fullClass) || !is_subclass_of($fullClass, \LaravelAIEngine\Contracts\AutonomousModelConfig::class)) {
                continue;
            }

            try {
                if (strtolower($fullClass::getName()) === $modelName) {
                    return $fullClass;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    public function getToolsForModel(string $modelClass): array
    {
        $configClass = $this->findModelConfigClass($modelClass);
        if (!$configClass) {
            return [];
        }

        try {
            $formatted = [];
            foreach ($configClass::getTools() as $toolName => $toolConfig) {
                $formatted[$toolName] = [
                    'description' => $toolConfig['description'] ?? '',
                    'parameters' => $toolConfig['parameters'] ?? [],
                    'requires_confirmation' => $toolConfig['requires_confirmation'] ?? false,
                ];
            }

            return $formatted;
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('Failed to get tools for model', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function findModelConfigClass(string $modelClass): ?string
    {
        $modelName = class_basename($modelClass);
        $namespace = substr($modelClass, 0, strrpos($modelClass, '\\'));
        $baseNamespace = substr($namespace, 0, strpos($namespace, '\\'));

        foreach ([
            "{$baseNamespace}\\AI\\Configs\\{$modelName}ModelConfig",
            "{$baseNamespace}\\AI\\Configs\\{$modelName}Config",
            "App\\AI\\Configs\\{$modelName}ModelConfig",
            "App\\AI\\Configs\\{$modelName}Config",
        ] as $configClass) {
            if (class_exists($configClass) && is_subclass_of($configClass, \LaravelAIEngine\Contracts\AutonomousModelConfig::class)) {
                return $configClass;
            }
        }

        return null;
    }

    public function getFilterConfigForModel(string $modelClass): array
    {
        $configClass = $this->findModelConfigClass($modelClass);
        if ($configClass) {
            try {
                return $configClass::getFilterConfig();
            } catch (\Throwable) {
            }
        }

        if (!$this->collectorDiscovery) {
            return [];
        }

        foreach ($this->collectorDiscovery->discoverCollectors() as $collector) {
            if (($collector['model_class'] ?? null) === $modelClass) {
                return $collector['filter_config'] ?? [];
            }
        }

        return [];
    }

    public function applyFilters($query, array $filters, string $modelClass, array $options = [])
    {
        if (empty($filters)) {
            return $query;
        }

        $instance = new $modelClass();
        $table = $instance->getTable();

        if (!empty($filters['id'])) {
            $resolvedId = $this->stateService->resolveIdFilterValue($filters['id'], $options);
            if ($resolvedId !== null) {
                $query->where('id', $resolvedId);
            } else {
                Log::channel('ai-engine')->warning('Could not resolve ID filter value, skipping ID filter', [
                    'raw_id' => $filters['id'],
                    'session_id' => $options['session_id'] ?? null,
                ]);
            }

            return $query;
        }

        if (!empty($filters['date_field']) && !empty($filters['date_value']) && \Schema::hasColumn($table, $filters['date_field'])) {
            $operator = $filters['date_operator'] ?? '=';
            if ($operator === 'between' && !empty($filters['date_end'])) {
                $query->whereBetween($filters['date_field'], [$filters['date_value'], $filters['date_end']]);
            } else {
                $query->whereDate($filters['date_field'], $operator, $filters['date_value']);
            }
        }

        if (!empty($filters['status']) && \Schema::hasColumn($table, 'status')) {
            if (is_array($filters['status'])) {
                $statuses = array_values(array_filter($filters['status'], fn ($item) => $item !== null && $item !== ''));
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            } else {
                $query->where('status', $filters['status']);
            }
        }

        $amountField = $filters['amount_field'] ?? null;
        if (!empty($filters['amount_min']) && $amountField && \Schema::hasColumn($table, $amountField)) {
            $query->where($amountField, '>=', $filters['amount_min']);
        }
        if (!empty($filters['amount_max']) && $amountField && \Schema::hasColumn($table, $amountField)) {
            $query->where($amountField, '<=', $filters['amount_max']);
        }

        $reservedKeys = [
            'id',
            'date_field',
            'date_value',
            'date_operator',
            'date_end',
            'status',
            'amount_field',
            'amount_min',
            'amount_max',
        ];

        foreach ($filters as $field => $value) {
            if (!is_string($field) || in_array($field, $reservedKeys, true)) {
                continue;
            }

            if (!\Schema::hasColumn($table, $field)) {
                continue;
            }

            if (is_array($value)) {
                $values = array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
                if (!empty($values)) {
                    $query->whereIn($field, $values);
                }
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $query->where($field, $value);
        }

        return $query;
    }

    protected function resolveCollections(array $options): array
    {
        $collections = $options['rag_collections'] ?? [];

        if (empty($collections) && $this->discovery) {
            $collections = $this->discovery->discover();
        }

        return $collections;
    }

    protected function matchesModelName(string $candidate, string $modelName): bool
    {
        $candidate = strtolower(trim($candidate));
        $modelName = strtolower(trim($modelName));

        if ($candidate === '' || $modelName === '') {
            return false;
        }

        $candidateVariants = $this->normalizedModelNameVariants($candidate);
        $modelVariants = $this->normalizedModelNameVariants($modelName);

        return array_intersect($candidateVariants, $modelVariants) !== [];
    }

    protected function normalizedModelNameVariants(string $value): array
    {
        $forms = [
            $value,
            $this->toSingular($value),
            $this->toPlural($value),
        ];

        $variants = [];
        foreach ($forms as $form) {
            $normalized = Str::of($form)
                ->replace('\\', ' ')
                ->snake(' ')
                ->replace(['-', '_'], ' ')
                ->lower()
                ->squish()
                ->value();

            if ($normalized === '') {
                continue;
            }

            $variants[] = $normalized;
            $variants[] = str_replace(' ', '', $normalized);
        }

        return array_values(array_unique($variants));
    }

    protected function findModelClassFromCollectors(string $modelName): ?string
    {
        if (!$this->collectorDiscovery) {
            return null;
        }

        foreach ($this->collectorDiscovery->discoverCollectors(useCache: true, includeRemote: false) as $collectorName => $collector) {
            $class = $collector['model_class'] ?? null;
            if (!is_string($class) || trim($class) === '') {
                continue;
            }

            if (!class_exists($class)) {
                continue;
            }

            $collectorModelName = strtolower(class_basename($class));
            if (
                $this->matchesModelName((string) $collectorName, $modelName) ||
                $this->matchesModelName($collectorModelName, $modelName)
            ) {
                return $class;
            }
        }

        return null;
    }

    protected function inferLocalModelClass(string $modelName): ?string
    {
        $namespace = rtrim(app()->getNamespace(), '\\') . '\\Models\\';

        foreach ($this->modelClassCandidates($modelName) as $candidate) {
            $class = $namespace . $candidate;
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    protected function modelClassCandidates(string $modelName): array
    {
        $base = strtolower(class_basename($modelName));
        $slug = str_replace(['-', ' '], '_', $base);
        $singular = $this->toSingular($slug);
        $plural = $this->toPlural($slug);

        return array_values(array_unique(array_filter([
            Str::studly($singular),
            Str::studly($plural),
            Str::studly($base),
        ])));
    }

    protected function toSingular(string $value): string
    {
        return strtolower(Str::singular(trim($value)));
    }

    protected function toPlural(string $value): string
    {
        return strtolower(Str::plural(trim($value)));
    }
}
