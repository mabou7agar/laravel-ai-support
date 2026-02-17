<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

/**
 * Discovers available resources (tools, collectors, nodes, model configs)
 * for the AI orchestrator prompt.
 *
 * Extracted from MinimalAIOrchestrator to keep resource discovery
 * testable and reusable independently.
 */
class OrchestratorResourceDiscovery
{
    public function __construct(
        protected NodeRegistryService $nodeRegistry,
        protected AutonomousCollectorRegistry $collectorRegistry,
        protected ?RAGCollectionDiscovery $collectionDiscovery = null
    ) {
    }

    /**
     * Discover all available resources for the orchestrator.
     *
     * @param array $options  Options from the caller (may contain model_configs override)
     * @return array{tools: array, collectors: array, nodes: array}
     */
    public function discover(array $options = []): array
    {
        return [
            'tools' => $this->discoverTools($options),
            'collectors' => $this->discoverCollectors(),
            'collections' => $this->discoverCollections($options),
            'nodes' => $this->discoverNodes(),
        ];
    }

    /**
     * Discover RAG collections.
     *
     * Priority:
     *  1. Caller-provided collections via options['rag_collections'] (from ChatService)
     *  2. RAGCollectionDiscovery (canonical source: config + auto-discover)
     */
    public function discoverCollections(array $options = []): array
    {
        // 1. Caller-provided collections take priority
        $callerCollections = $options['rag_collections'] ?? [];
        if (!empty($callerCollections)) {
            return $this->formatCollections($callerCollections);
        }

        // 2. Fall back to canonical RAGCollectionDiscovery
        if (!$this->collectionDiscovery) {
            return [];
        }

        try {
            $raw = $this->collectionDiscovery->discover(useCache: true, includeFederated: false);

            return $this->formatCollections($raw);
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Failed to discover RAG collections for orchestrator', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Normalize collection data into a consistent format for the prompt.
     */
    protected function formatCollections(array $collections): array
    {
        return collect($collections)->map(function ($item) {
            if (is_array($item)) {
                return [
                    'name' => $item['name'] ?? class_basename($item['class'] ?? 'unknown'),
                    'description' => $item['description'] ?? '',
                ];
            }
            // Legacy format: plain class string
            return [
                'name' => strtolower(class_basename($item)),
                'description' => '',
            ];
        })->toArray();
    }

    /**
     * Discover tools from AutonomousModelConfig classes.
     */
    public function discoverTools(array $options = []): array
    {
        $tools = [];
        $modelConfigs = $options['model_configs'] ?? $this->discoverModelConfigs();

        foreach ($modelConfigs as $configClass) {
            if (!method_exists($configClass, 'getTools')) {
                continue;
            }

            try {
                $configTools = $configClass::getTools();

                $modelName = method_exists($configClass, 'getName')
                    ? $configClass::getName()
                    : class_basename($configClass);

                foreach ($configTools as $toolName => $toolDef) {
                    $tools[] = [
                        'name' => $toolName,
                        'model' => $modelName,
                        'description' => $toolDef['description'] ?? '',
                    ];
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->debug('Failed to get tools from config', [
                    'config' => $configClass,
                ]);
            }
        }

        return $tools;
    }

    /**
     * Discover collectors from local registry + remote nodes.
     */
    public function discoverCollectors(): array
    {
        $collectors = [];

        // 1. Discover local collectors from static registry
        try {
            $localCollectors = AutonomousCollectorRegistry::getConfigs();

            foreach ($localCollectors as $name => $configData) {
                $collectors[] = [
                    'name' => $name,
                    'goal' => $configData['goal'] ?? '',
                    'description' => $configData['description'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to discover local collectors', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Discover collectors from remote nodes
        try {
            $activeNodes = $this->nodeRegistry->getActiveNodes();

            foreach ($activeNodes as $node) {
                $autonomousCollectors = $node['autonomous_collectors'] ?? [];

                if (is_array($autonomousCollectors)) {
                    foreach ($autonomousCollectors as $collector) {
                        if (isset($collector['name'])) {
                            $collectors[] = [
                                'name' => $collector['name'],
                                'goal' => $collector['goal'] ?? '',
                                'description' => $collector['description'] ?? '',
                                'node' => $node['slug'] ?? 'unknown',
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to discover remote collectors', [
                'error' => $e->getMessage(),
            ]);
        }

        return $collectors;
    }

    /**
     * Discover active nodes from the registry.
     */
    public function discoverNodes(): array
    {
        $nodes = [];
        $activeNodes = $this->nodeRegistry->getActiveNodes();

        foreach ($activeNodes as $node) {
            // Handle both object and array access
            $slug = is_array($node) ? ($node['slug'] ?? '') : $node->slug;
            $name = is_array($node) ? ($node['name'] ?? '') : $node->name;
            $description = is_array($node) ? ($node['description'] ?? '') : ($node->description ?? '');
            $domains = is_array($node) ? ($node['domains'] ?? []) : ($node->domains ?? []);

            $nodes[] = [
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'domains' => $domains,
            ];
        }

        return $nodes;
    }

    /**
     * Discover AutonomousModelConfig classes from configured paths.
     *
     * @return string[] FQCN list
     */
    public function discoverModelConfigs(): array
    {
        $configs = [];
        $discoveryConfig = (array) config('ai-agent.model_config_discovery', []);
        $paths = $discoveryConfig['paths'] ?? [app_path('AI/Configs')];
        $defaultAppNamespace = rtrim((string) config('app.namespace', 'App\\'), '\\');
        $namespaces = $discoveryConfig['namespaces'] ?? [$defaultAppNamespace . '\\AI\\Configs'];

        foreach ($paths as $index => $configPath) {
            if (!is_string($configPath) || !is_dir($configPath)) {
                continue;
            }

            $files = glob(rtrim($configPath, '/\\') . '/*ModelConfig.php');
            $baseNamespace = $namespaces[$index] ?? ($namespaces[0] ?? null);
            if (!is_string($baseNamespace) || $baseNamespace === '') {
                continue;
            }

            foreach ($files as $file) {
                $className = rtrim($baseNamespace, '\\') . '\\' . basename($file, '.php');
                if (class_exists($className)) {
                    $configs[] = $className;
                }
            }
        }

        return array_values(array_unique($configs));
    }
}
