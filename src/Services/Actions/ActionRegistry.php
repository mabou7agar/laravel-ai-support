<?php

namespace LaravelAIEngine\Services\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

/**
 * Centralized Action Registry
 *
 * Single source of truth for all available actions in the system
 */
class ActionRegistry
{
    protected array $actions = [];
    protected bool $discovered = false;

    /**
     * Register an action
     */
    public function register(string $id, array $definition): self
    {
        $this->actions[$id] = array_merge([
            'id' => $id,
            'label' => $id,
            'description' => '',
            'executor' => null,
            'parameters' => [],
            'required_params' => [],
            'optional_params' => [],
            'triggers' => [],
            'permissions' => [],
            'version' => '1.0.0',
            'enabled' => true,
            'is_remote' => false,
        ], $definition);

        Log::channel('ai-engine')->debug('Action registered', [
            'id' => $id,
            'label' => $definition['label'] ?? $id,
        ]);

        return $this;
    }

    /**
     * Register multiple actions at once
     */
    public function registerBatch(array $actions): self
    {
        foreach ($actions as $id => $definition) {
            $this->register($id, $definition);
        }

        return $this;
    }

    /**
     * Discover actions from models with HasAIActions trait
     */
    public function discoverFromModels(): self
    {
        $cacheKey = 'action_registry:model_classes';

        // Cache only the list of model classes (not their configs with closures)
        $modelClasses = Cache::remember($cacheKey, 3600, function () {
            $classes = [];

            try {
                $ragDiscovery = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
                $collections = $ragDiscovery->discover();

                foreach ($collections as $modelClass) {
                    if (!class_exists($modelClass)) {
                        continue;
                    }

                    $reflection = new \ReflectionClass($modelClass);

                    // Check for HasAIActions trait or executeAI method
                    if ($reflection->hasMethod('executeAI') || $reflection->hasMethod('initializeAI')) {
                        $classes[] = $modelClass;
                    }
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to discover model classes', [
                    'error' => $e->getMessage(),
                ]);
            }

            return $classes;
        });

        // Build actions from cached model classes (configs generated fresh each time)
        $actions = [];
        foreach ($modelClasses as $modelClass) {
            try {
                $modelName = class_basename($modelClass);
                $actionId = 'create_' . strtolower($modelName);

                // Get expected format fresh (may contain closures)
                $expectedFormat = $this->getModelExpectedFormat($modelClass);
                
                // Check if model has a workflow configured
                $workflowClass = null;
                $reflection = new \ReflectionClass($modelClass);
                
                \Log::info('ActionRegistry: Checking workflow for model', [
                    'model' => $modelClass,
                    'has_initializeAI' => $reflection->hasMethod('initializeAI'),
                    'has_getAIConfig' => $reflection->hasMethod('getAIConfig'),
                ]);
                
                // Check for initializeAI method
                if ($reflection->hasMethod('initializeAI')) {
                    $method = $reflection->getMethod('initializeAI');
                    $aiConfig = $method->isStatic() 
                        ? $modelClass::initializeAI() 
                        : (new $modelClass())->initializeAI();
                    
                    $workflowClass = $aiConfig['workflow'] ?? null;
                    
                    \Log::info('ActionRegistry: initializeAI result', [
                        'model' => $modelClass,
                        'workflow' => $workflowClass,
                        'has_fields' => isset($aiConfig['fields']),
                    ]);
                } elseif ($reflection->hasMethod('getAIConfig')) {
                    $method = $reflection->getMethod('getAIConfig');
                    $aiConfig = $method->isStatic() 
                        ? $modelClass::getAIConfig() 
                        : (new $modelClass())->getAIConfig();
                    
                    $workflowClass = $aiConfig['workflow'] ?? null;
                    
                    \Log::info('ActionRegistry: getAIConfig result', [
                        'model' => $modelClass,
                        'workflow' => $workflowClass,
                        'has_fields' => isset($aiConfig['fields']),
                        'fields' => array_keys($aiConfig['fields'] ?? []),
                    ]);
                }

                // If workflow is configured, register as workflow action
                \Log::info('ActionRegistry: Workflow check result', [
                    'model' => $modelClass,
                    'workflow_class' => $workflowClass,
                    'workflow_exists' => $workflowClass && class_exists($workflowClass),
                ]);
                
                if ($workflowClass && class_exists($workflowClass)) {
                    $actions[$actionId] = [
                        'label' => "🧾 Create {$modelName} (Guided Workflow)",
                        'description' => "Create a new {$modelName} with intelligent guided conversation",
                        'executor' => 'workflow',
                        'workflow_class' => $workflowClass,
                        'model_class' => $modelClass,
                        'required_params' => [],
                        'optional_params' => [],
                        'parameters' => $expectedFormat,
                        'triggers' => $this->generateTriggersForModel($modelName),
                        'type' => 'workflow_action',
                        'auto_execute' => true,
                        'skip_confirmation' => true,
                        'ready_to_execute' => true, // Workflows handle their own data collection
                    ];
                } else {
                    // No workflow - register as model.dynamic action
                    $actions[$actionId] = [
                        'label' => "🎯 Create {$modelName}",
                        'description' => "Create a new {$modelName} from conversation",
                        'executor' => 'model.dynamic',
                        'model_class' => $modelClass,
                        'required_params' => $expectedFormat['required'] ?? [],
                        'optional_params' => $expectedFormat['optional'] ?? [],
                        'parameters' => $expectedFormat,
                        'triggers' => $this->generateTriggersForModel($modelName),
                        'type' => 'model_action',
                        'auto_execute' => true,
                        'skip_confirmation' => true,
                    ];
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to build action for model', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->registerBatch($actions);
    }

    /**
     * Discover actions from remote nodes
     */
    public function discoverFromRemoteNodes(): self
    {
        $cacheKey = 'action_registry:remote_actions';

        $remoteActions = Cache::remember($cacheKey, 300, function () {
            $actions = [];

            try {
                if (!class_exists(\LaravelAIEngine\Models\AINode::class)) {
                    return $actions;
                }

                $nodes = \LaravelAIEngine\Models\AINode::where('status', 'active')->get();

                foreach ($nodes as $node) {
                    try {
                        $response = \Illuminate\Support\Facades\Http::withoutVerifying()
                            ->timeout(5)
                            ->get($node->url . '/api/ai-engine/collections');

                        if (!$response->successful()) {
                            continue;
                        }

                        $data = $response->json();
                        $collections = $data['collections'] ?? [];

                        foreach ($collections as $collection) {
                            $className = $collection['class'] ?? null;
                            $methods = $collection['methods'] ?? [];

                            if ($className &&
                                in_array('executeAI', $methods) &&
                                in_array('initializeAI', $methods)) {

                                $format = $collection['format'] ?? null;
                                $modelName = class_basename($className);
                                $actionId = 'create_' . strtolower($modelName);

                                if ($format) {
                                    // Extract critical fields from format for remote validation
                                    $criticalFields = [];
                                    if (isset($format['critical_fields'])) {
                                        $criticalFields = array_keys($format['critical_fields']);
                                    }

                                    $actions[$actionId] = [
                                        'label' => $format['label'] ?? "🎯 Create {$modelName}",
                                        'description' => $format['description'] ?? "Create a new {$modelName}",
                                        'executor' => 'model.remote',
                                        'model_class' => $className,
                                        'required_params' => array_merge($format['required'] ?? [], $criticalFields),
                                        'optional_params' => $format['optional'] ?? [],
                                        'parameters' => $format,
                                        'triggers' => $format['triggers'] ?? $this->generateTriggersForModel($modelName),
                                        'is_remote' => true,
                                        'node_url' => $node->url,
                                        'node_name' => $node->name,
                                        'node_slug' => $node->slug,
                                        'type' => 'remote_model_action',
                                    ];
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::channel('ai-engine')->debug('Failed to discover actions from node', [
                            'node' => $node->name,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to discover remote actions', [
                    'error' => $e->getMessage(),
                ]);
            }

            return $actions;
        });

        return $this->registerBatch($remoteActions);
    }

    /**
     * Load actions from configuration file
     */
    public function loadFromConfig(string $path): self
    {
        if (!File::exists($path)) {
            return $this;
        }

        $config = require $path;
        $actions = $config['actions'] ?? [];

        return $this->registerBatch($actions);
    }

    /**
     * Get all registered actions
     */
    public function all(): array
    {
        $this->ensureDiscovered();
        return $this->actions;
    }

    /**
     * Get action by ID
     */
    public function get(string $id): ?array
    {
        $this->ensureDiscovered();
        return $this->actions[$id] ?? null;
    }

    /**
     * Check if action exists
     */
    public function has(string $id): bool
    {
        $this->ensureDiscovered();
        return isset($this->actions[$id]);
    }

    /**
     * Find actions by trigger keywords
     */
    public function findByTrigger(string $keyword): array
    {
        $this->ensureDiscovered();
        $keyword = strtolower($keyword);

        return array_filter($this->actions, function ($action) use ($keyword) {
            $triggers = $action['triggers'] ?? [];
            foreach ($triggers as $trigger) {
                if (str_contains(strtolower($trigger), $keyword)) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Find actions by model class
     */
    public function findByModel(string $modelClass): array
    {
        $this->ensureDiscovered();

        return array_filter($this->actions, function ($action) use ($modelClass) {
            return ($action['model_class'] ?? null) === $modelClass;
        });
    }

    /**
     * Get actions by type
     */
    public function getByType(string $type): array
    {
        $this->ensureDiscovered();

        return array_filter($this->actions, function ($action) use ($type) {
            return ($action['type'] ?? null) === $type;
        });
    }

    /**
     * Get enabled actions only
     */
    public function getEnabled(): array
    {
        $this->ensureDiscovered();

        return array_filter($this->actions, function ($action) {
            return $action['enabled'] ?? true;
        });
    }

    /**
     * Unregister an action
     */
    public function unregister(string $id): self
    {
        unset($this->actions[$id]);
        return $this;
    }

    /**
     * Clear all actions
     */
    public function clear(): self
    {
        $this->actions = [];
        $this->discovered = false;
        return $this;
    }

    /**
     * Clear cache
     */
    public function clearCache(): self
    {
        Cache::forget('action_registry:model_actions');
        Cache::forget('action_registry:remote_actions');
        return $this;
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        $this->ensureDiscovered();

        $stats = [
            'total' => count($this->actions),
            'enabled' => count($this->getEnabled()),
            'by_type' => [],
            'local' => 0,
            'remote' => 0,
        ];

        foreach ($this->actions as $action) {
            $type = $action['type'] ?? 'unknown';
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

            if ($action['is_remote'] ?? false) {
                $stats['remote']++;
            } else {
                $stats['local']++;
            }
        }

        return $stats;
    }

    /**
     * Ensure actions are discovered
     */
    protected function ensureDiscovered(): void
    {
        if ($this->discovered) {
            return;
        }

        $this->discoverFromModels();

        if (config('ai-engine.nodes.enabled', true)) {
            $this->discoverFromRemoteNodes();
        }

        // Load from config if exists
        $configPath = config_path('ai-actions.php');
        if (File::exists($configPath)) {
            $this->loadFromConfig($configPath);
        }

        $this->discovered = true;
    }

    /**
     * Get model expected format
     */
    protected function getModelExpectedFormat(string $modelClass): array
    {
        try {
            $reflection = new \ReflectionClass($modelClass);
            $config = null;

            // Check for initializeAI method
            if ($reflection->hasMethod('initializeAI')) {
                $method = $reflection->getMethod('initializeAI');
                $config = $method->isStatic() 
                    ? $modelClass::initializeAI() 
                    : (new $modelClass())->initializeAI();
            }
            // Check for getAIConfig method
            elseif ($reflection->hasMethod('getAIConfig')) {
                $method = $reflection->getMethod('getAIConfig');
                $config = $method->isStatic() 
                    ? $modelClass::getAIConfig() 
                    : (new $modelClass())->getAIConfig();
            }

            // If we have config, convert fields format to required/optional
            if ($config && isset($config['fields'])) {
                $required = [];
                $optional = [];

                foreach ($config['fields'] as $fieldName => $fieldConfig) {
                    if ($fieldConfig['required'] ?? false) {
                        $required[] = $fieldName;
                    } else {
                        $optional[] = $fieldName;
                    }
                }

                return [
                    'required' => $required,
                    'optional' => $optional,
                    'fields' => $config['fields'],
                ];
            }

            if ($config) {
                return $config;
            }

            // Fallback to fillable
            $model = new $modelClass();
            $fillable = $model->getFillable();

            return [
                'required' => array_slice($fillable, 0, min(2, count($fillable))),
                'optional' => array_slice($fillable, min(2, count($fillable))),
            ];
        } catch (\Exception $e) {
            return ['required' => [], 'optional' => []];
        }
    }

    /**
     * Generate triggers for model
     */
    protected function generateTriggersForModel(string $modelName): array
    {
        $lower = strtolower($modelName);
        return [
            $lower,
            "create {$lower}",
            "add {$lower}",
            "new {$lower}",
        ];
    }
}
