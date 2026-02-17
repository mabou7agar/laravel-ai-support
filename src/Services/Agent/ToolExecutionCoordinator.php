<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Handlers\ToolParameterExtractor;
use Illuminate\Support\Facades\Log;

class ToolExecutionCoordinator
{
    public function execute(
        string $toolName,
        string $message,
        UnifiedActionContext $context,
        array $modelConfigs,
        ?array $selectedEntity = null
    ): ?AgentResponse {
        Log::channel('ai-engine')->info('ToolExecutionCoordinator: searching for tool', [
            'tool_name' => $toolName,
            'config_count' => count($modelConfigs),
            'configs' => array_map('class_basename', $modelConfigs),
        ]);

        foreach ($modelConfigs as $configClass) {
            if (!method_exists($configClass, 'getTools')) {
                Log::channel('ai-engine')->debug('ToolExecutionCoordinator: Config has no getTools method', [
                    'config' => class_basename($configClass),
                ]);
                continue;
            }

            try {
                $tools = $configClass::getTools();

                Log::channel('ai-engine')->debug('ToolExecutionCoordinator: Checking config tools', [
                    'config' => class_basename($configClass),
                    'tool_names' => array_keys((array) $tools),
                    'looking_for' => $toolName,
                ]);

                if (!isset($tools[$toolName])) {
                    continue;
                }

                $tool = $tools[$toolName];
                $handler = $tool['handler'] ?? null;
                $modelName = method_exists($configClass, 'getName')
                    ? $configClass::getName()
                    : null;

                if (!$handler || !is_callable($handler)) {
                    Log::channel('ai-engine')->warning('ToolExecutionCoordinator: Tool handler is not callable', [
                        'tool_name' => $toolName,
                        'config' => class_basename($configClass),
                    ]);
                    continue;
                }

                $params = $this->resolveToolParams($toolName, $message, $context, (array) ($tool['parameters'] ?? []), $modelName);
                $params = $this->bindSelectedEntityToToolParams(
                    $toolName,
                    $params,
                    $selectedEntity,
                    (array) ($tool['parameters'] ?? [])
                );

                Log::channel('ai-engine')->info('ToolExecutionCoordinator: executing tool handler', [
                    'tool_name' => $toolName,
                    'params' => $params,
                    'selected_entity_id' => $selectedEntity['entity_id'] ?? null,
                ]);

                $result = $handler($params);

                if ($result['success'] ?? false) {
                    return AgentResponse::success(
                        message: $result['message'] ?? 'Operation completed successfully',
                        context: $context,
                        data: $result
                    );
                }

                return AgentResponse::failure(
                    message: $result['error'] ?? 'Operation failed',
                    context: $context
                );
            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('ToolExecutionCoordinator: Tool execution failed', [
                    'tool_name' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    protected function resolveToolParams(
        string $toolName,
        string $message,
        UnifiedActionContext $context,
        array $toolSchema,
        ?string $modelName = null
    ): array {
        $params = [];
        $suggestedActions = $context->metadata['suggested_actions'] ?? [];

        foreach ($suggestedActions as $action) {
            if (($action['tool'] ?? $action['action'] ?? null) === $toolName) {
                $params = $action['params'] ?? [];
                Log::channel('ai-engine')->info('ToolExecutionCoordinator: using params from suggested_actions', [
                    'tool_name' => $toolName,
                    'params' => $params,
                ]);
                break;
            }
        }

        if (!empty($params)) {
            return $params;
        }

        return ToolParameterExtractor::extractWithMetadata(
            $message,
            $context,
            $toolSchema,
            $modelName
        );
    }

    protected function bindSelectedEntityToToolParams(
        string $toolName,
        array $params,
        ?array $selectedEntity,
        array $toolSchema = []
    ): array {
        if (!$selectedEntity || empty($selectedEntity['entity_id'])) {
            return $params;
        }

        $selectedId = (int) $selectedEntity['entity_id'];
        if ($selectedId <= 0) {
            return $params;
        }

        $singleIdKey = $this->findSingleEntityIdParamKey($toolSchema);
        if ($singleIdKey && ($params[$singleIdKey] ?? null) !== $selectedId) {
            Log::channel('ai-engine')->info('ToolExecutionCoordinator: overriding tool entity id from selected context', [
                'tool_name' => $toolName,
                'param_key' => $singleIdKey,
                'old_value' => $params[$singleIdKey] ?? null,
                'selected_entity_id' => $selectedId,
            ]);
            $params[$singleIdKey] = $selectedId;
        }

        if (isset($toolSchema['email_ids'])) {
            $existing = $params['email_ids'] ?? [];
            if (!is_array($existing) || $existing !== [$selectedId]) {
                $params['email_ids'] = [$selectedId];
            }
        }

        if (!empty($selectedEntity['suggested_action_content'])) {
            $params['suggested_action_content'] = $selectedEntity['suggested_action_content'];
        }

        if (!empty($selectedEntity['entity_data'])) {
            $params['entity_data'] = $selectedEntity['entity_data'];
        }

        return $params;
    }

    protected function findSingleEntityIdParamKey(array $toolSchema): ?string
    {
        if (empty($toolSchema)) {
            return null;
        }

        $excludedKeys = ['user_id', 'mailbox_id', 'session_id', 'node_id'];
        $candidates = [];

        foreach (array_keys($toolSchema) as $key) {
            if (!is_string($key)) {
                continue;
            }

            if (in_array($key, $excludedKeys, true)) {
                continue;
            }

            if ($key === 'id' || str_ends_with($key, '_id')) {
                $candidates[] = $key;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if (in_array('email_id', $candidates, true)) {
            return 'email_id';
        }

        if (in_array('id', $candidates, true)) {
            return 'id';
        }

        return null;
    }
}
