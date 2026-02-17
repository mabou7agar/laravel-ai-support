<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Executes local tools from AutonomousModelConfig.
 *
 * Responsibilities:
 *  - Build a flat tool registry from model config classes
 *  - Execute a tool handler with parameter binding
 *  - Format tool schemas for prompt consumption
 *
 * Does NOT handle remote/cross-node tools — that's CrossNodeToolResolver.
 */
class AgentToolHandler
{
    /**
     * Build a flat tool registry from AutonomousModelConfig classes.
     *
     * Each entry: [handler, description, parameters, model, config_class, source => 'local']
     *
     * @param array $modelConfigs FQCN list of AutonomousModelConfig subclasses
     * @return array<string, array>
     */
    public function buildRegistry(array $modelConfigs): array
    {
        $registry = [];

        foreach ($modelConfigs as $configClass) {
            if (!method_exists($configClass, 'getTools')) {
                continue;
            }

            try {
                $tools = $configClass::getTools();
                $modelName = method_exists($configClass, 'getName')
                    ? $configClass::getName()
                    : class_basename($configClass);

                foreach ($tools as $toolName => $toolDef) {
                    $registry[$toolName] = [
                        'handler' => $toolDef['handler'] ?? null,
                        'description' => $toolDef['description'] ?? '',
                        'parameters' => $toolDef['parameters'] ?? [],
                        'model' => $modelName,
                        'config_class' => $configClass,
                        'source' => 'local',
                        'node_slug' => null,
                    ];
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->debug('AgentToolHandler: failed loading tools', [
                    'config' => $configClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $registry;
    }

    /**
     * Execute a local tool handler.
     *
     * @param array                $toolDef  Tool definition from registry
     * @param array                $params   Parameters from the agent
     * @param UnifiedActionContext $context  Session context
     * @param array                $options  Extra options (selected_entity, etc.)
     * @return string Observation text for the reasoning loop
     */
    public function execute(array $toolDef, array $params, UnifiedActionContext $context, array $options = []): string
    {
        $handler = $toolDef['handler'] ?? null;

        if (!$handler || !is_callable($handler)) {
            return 'Error: Tool handler is not callable.';
        }

        // Merge selected entity data if available
        $selectedEntity = $options['selected_entity'] ?? null;
        if ($selectedEntity && !empty($selectedEntity['entity_data'])) {
            $params['entity_data'] = $selectedEntity['entity_data'];
        }

        try {
            $result = $handler($params);

            if (is_array($result)) {
                return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }

            return (string) $result;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('AgentToolHandler: tool failed', [
                'tool' => $toolDef['description'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Format tool schemas into a compact text block for the agent prompt.
     *
     * @param array $toolRegistry Full registry (local + remote)
     */
    public function formatSchemaForPrompt(array $toolRegistry): string
    {
        $lines = [];
        foreach ($toolRegistry as $name => $tool) {
            $source = $tool['source'] ?? 'local';
            $nodeTag = $source === 'remote' ? " @{$tool['node_slug']}" : '';

            $params = [];
            foreach ($tool['parameters'] as $pName => $pDef) {
                $type = $pDef['type'] ?? 'string';
                $required = !empty($pDef['required']) ? '*' : '';
                $desc = $pDef['description'] ?? '';
                $params[] = "{$pName}{$required}({$type}): {$desc}";
            }
            $paramStr = !empty($params) ? "\n    Params: " . implode(', ', $params) : '';
            $lines[] = "- {$name} [{$tool['model']}{$nodeTag}]: {$tool['description']}{$paramStr}";
        }
        return implode("\n", $lines);
    }
}
