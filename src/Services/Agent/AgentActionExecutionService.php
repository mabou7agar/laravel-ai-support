<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Handlers\ToolParameterExtractor;
use LaravelAIEngine\Services\Agent\AgentManifestService;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class AgentActionExecutionService
{
    public function __construct(
        protected SelectedEntityContextService $selectedEntityContext,
        protected ?LocaleResourceService $localeResources = null,
        protected ?AgentManifestService $manifestService = null,
        protected ?ToolRegistry $toolRegistry = null
    ) {
    }

    public function executeUseTool(
        string $toolName,
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $searchRag
    ): AgentResponse {
        Log::channel('ai-engine')->debug('LaravelAgentProcessor: executeUseTool called', [
            'tool_name' => $toolName,
            'message' => $message,
        ]);

        if ($toolName === '') {
            return AgentResponse::failure(
                message: $this->runtimeText('ai-engine::runtime.agent_action_execution.no_tool_specified', 'No tool specified'),
                context: $context
            );
        }

        $modelConfigs = $options['model_configs'] ?? $this->discoverModelConfigs();

        foreach ($modelConfigs as $configClass) {
            if (!method_exists($configClass, 'getTools')) {
                continue;
            }

            try {
                $tools = $configClass::getTools();
                if (!isset($tools[$toolName])) {
                    continue;
                }

                $tool = $tools[$toolName];
                $handler = $tool['handler'] ?? null;
                $modelName = method_exists($configClass, 'getName') ? $configClass::getName() : null;

                if (!$handler || !is_callable($handler)) {
                    continue;
                }

                $params = is_array($options['tool_params'] ?? null) ? $options['tool_params'] : [];
                $suggestedActions = $context->metadata['suggested_actions'] ?? [];
                if (empty($params)) {
                    foreach ($suggestedActions as $action) {
                        if (($action['tool'] ?? $action['action'] ?? null) === $toolName) {
                            $params = $action['params'] ?? [];
                            break;
                        }
                    }
                }

                if (empty($params)) {
                    $params = ToolParameterExtractor::extractWithMetadata(
                        $message,
                        $context,
                        $tool['parameters'] ?? [],
                        $modelName
                    );
                }

                $selectedEntity = $this->selectedEntityContext->getFromContext($context);
                $params = $this->selectedEntityContext->bindToToolParams(
                    $toolName,
                    $params,
                    $selectedEntity,
                    $tool['parameters'] ?? []
                );

                $context->metadata['latest_user_message'] = $message;
                $result = $handler($params, $context);

                if ($result['success'] ?? false) {
                    $response = AgentResponse::success(
                        message: $result['message'] ?? $this->runtimeText(
                            'ai-engine::runtime.agent_action_execution.operation_completed',
                            'Operation completed successfully'
                        ),
                        context: $context,
                        data: $result
                    );
                    $response->strategy = $result['metadata']['agent_strategy'] ?? $result['agent_strategy'] ?? null;
                    $response->metadata = array_filter(array_merge((array) ($result['metadata'] ?? []), [
                        'agent_strategy' => $response->strategy,
                        'flow_data' => $result,
                        'tool_name' => $toolName,
                    ]));

                    return $response;
                }

                if ($result['needs_user_input'] ?? false) {
                    $response = AgentResponse::needsUserInput(
                        message: $result['message'] ?? $result['error'] ?? $this->runtimeText(
                            'ai-engine::runtime.agent_action_execution.input_required',
                            'More information is required.'
                        ),
                        data: $result,
                        context: $context,
                        requiredInputs: $result['missing_fields'] ?? null
                    );
                    $response->strategy = $result['metadata']['agent_strategy'] ?? $result['agent_strategy'] ?? null;
                    $response->metadata = array_filter(array_merge((array) ($result['metadata'] ?? []), [
                        'agent_strategy' => $response->strategy,
                        'flow_data' => $result,
                        'tool_name' => $toolName,
                    ]));

                    return $response;
                }

                return AgentResponse::failure(
                    message: $result['error'] ?? $this->runtimeText(
                        'ai-engine::runtime.agent_action_execution.operation_failed',
                        'Operation failed'
                    ),
                    context: $context
                );
            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('Tool execution failed', [
                    'tool_name' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $registryResponse = $this->executeRegistryTool($toolName, $message, $context, $options);
        if ($registryResponse) {
            return $registryResponse;
        }

        $availableTools = $this->availableToolNames($modelConfigs);

        Log::channel('ai-engine')->warning(
            'Routing chose a tool that is not registered; silently falling back to RAG. ' .
            'This usually means the AI router picked a tool name that does not exist (no matching tool registered).',
            [
                'requested_tool' => $toolName,
                'available_tools' => $availableTools,
                'falling_back_to' => 'rag',
            ]
        );

        $fallbackMeta = [
            'tool_not_found' => true,
            'requested_tool' => $toolName,
            'available_tools' => $availableTools,
        ];

        if ($this->isStructuredDataFallback($message)) {
            return $searchRag($message, $context, array_merge($options, $fallbackMeta, [
                'preclassified_route_mode' => 'structured_query',
                'target_model' => $toolName,
                'decision_path' => 'tool_fallback_structured_query',
                'decision_source' => 'tool_fallback',
            ]));
        }

        return $searchRag($message, $context, array_merge($options, $fallbackMeta, [
            'decision_path' => 'tool_fallback',
            'decision_source' => 'tool_fallback',
        ]));
    }

    /**
     * Collect the tool names actually registered (model configs + tool registry),
     * used to make a tool-not-found fallback diagnosable.
     *
     * @param array<int, mixed> $modelConfigs
     * @return array<int, string>
     */
    protected function availableToolNames(array $modelConfigs): array
    {
        $names = [];

        foreach ($modelConfigs as $configClass) {
            if (!is_string($configClass) || !method_exists($configClass, 'getTools')) {
                continue;
            }

            try {
                $names = array_merge($names, array_keys((array) $configClass::getTools()));
            } catch (\Throwable) {
                // ignore a misbehaving config when only listing names
            }
        }

        try {
            $names = array_merge(
                $names,
                array_keys(app(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class)->getToolDefinitions())
            );
        } catch (\Throwable) {
            // registry optional
        }

        sort($names);

        return array_values(array_unique($names));
    }

    protected function isStructuredDataFallback(string $message): bool
    {
        return preg_match(
            '/\b(count|how many|total|sum|average|avg|minimum|min|maximum|max|group by|by status|per status|by month|monthly)\b/i',
            $message
        ) === 1;
    }

    protected function executeRegistryTool(
        string $toolName,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): ?AgentResponse {
        $tool = $this->toolRegistry()->get($toolName);
        if (!$tool) {
            return null;
        }

        $params = is_array($options['tool_params'] ?? null) ? $options['tool_params'] : [];
        if (empty($params)) {
            $params = ToolParameterExtractor::extractWithMetadata(
                $message,
                $context,
                $tool->getParameters(),
                $toolName
            );
        }

        $selectedEntity = $this->selectedEntityContext->getFromContext($context);
        $params = $this->selectedEntityContext->bindToToolParams(
            $toolName,
            $params,
            $selectedEntity,
            $tool->getParameters()
        );

        $context->metadata['latest_user_message'] = $message;

        $errors = $tool->validate($params);
        if ($errors !== []) {
            return AgentResponse::needsUserInput(
                message: implode("\n", $errors),
                data: ['tool_name' => $toolName, 'validation_errors' => $errors],
                context: $context,
                requiredInputs: $errors
            );
        }

        $result = $tool->execute($params, $context);
        $response = AgentResponse::fromActionResult($result, $context);
        $response->strategy = $result->metadata['agent_strategy'] ?? 'tool';
        $response->metadata = array_filter(array_merge($result->metadata, [
            'agent_strategy' => $response->strategy,
            'flow_data' => $result->toArray(),
            'tool_name' => $toolName,
        ]));

        if ($result->requiresUserInput()) {
            $response->needsUserInput = true;
            $response->isComplete = false;
        }

        return $response;
    }

    protected function toolRegistry(): ToolRegistry
    {
        if ($this->toolRegistry === null) {
            $this->toolRegistry = app(ToolRegistry::class);
        }

        return $this->toolRegistry;
    }

    protected function discoverModelConfigs(): array
    {
        $manifestConfigs = $this->manifest()->modelConfigs();
        $configs = $manifestConfigs;
        if (!$this->manifest()->fallbackDiscoveryEnabled()) {
            return array_values(array_unique($configs));
        }

        $configPath = app_path('AI/Configs');

        if (!is_dir($configPath)) {
            return array_values(array_unique($configs));
        }

        $files = glob($configPath . '/*ModelConfig.php');
        foreach ($files as $file) {
            $className = 'App\\AI\\Configs\\' . basename($file, '.php');
            if (class_exists($className)) {
                $configs[] = $className;
            }
        }

        return array_values(array_unique($configs));
    }

    protected function runtimeText(string $key, string $fallback, array $replace = []): string
    {
        $translated = $this->locale()->translation($key, $replace);
        if ($translated !== '') {
            return $translated;
        }

        return strtr(
            $fallback,
            array_combine(
                array_map(static fn ($name): string => ':' . $name, array_keys($replace)),
                array_map(static fn ($value): string => (string) $value, array_values($replace))
            ) ?: []
        );
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }

    protected function manifest(): AgentManifestService
    {
        if ($this->manifestService === null) {
            $this->manifestService = app(AgentManifestService::class);
        }

        return $this->manifestService;
    }
}
