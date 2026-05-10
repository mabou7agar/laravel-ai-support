<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\Agent\Handlers\ToolParameterExtractor;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\Agent\AgentManifestService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class AgentActionExecutionService
{
    public function __construct(
        protected AutonomousCollectorRegistry $collectorRegistry,
        protected AutonomousCollectorDiscoveryService $collectorDiscovery,
        protected AutonomousCollectorHandler $collectorHandler,
        protected SelectedEntityContextService $selectedEntityContext,
        protected ?LocaleResourceService $localeResources = null,
        protected ?AgentManifestService $manifestService = null
    ) {
    }

    public function executeUseTool(
        string $toolName,
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $searchRag
    ): AgentResponse {
        Log::channel('ai-engine')->debug('AgentOrchestrator: executeUseTool called', [
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
                    $response->metadata = array_filter([
                        'agent_strategy' => $response->strategy,
                        'workflow_data' => $result,
                        'tool_name' => $toolName,
                    ]);

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
                    $response->metadata = array_filter([
                        'agent_strategy' => $response->strategy,
                        'workflow_data' => $result,
                        'tool_name' => $toolName,
                    ]);

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

        Log::channel('ai-engine')->warning('Tool not found in configs, routing to RAG', [
            'tool_name' => $toolName,
        ]);

        return $searchRag($message, $context, $options);
    }

    public function executeStartCollector(
        string $collectorName,
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $routeToNode
    ): AgentResponse {
        if ($collectorName === '') {
            return AgentResponse::failure(
                message: $this->runtimeText('ai-engine::runtime.agent_action_execution.no_collector_specified', 'No collector specified'),
                context: $context
            );
        }

        Log::channel('ai-engine')->debug('AgentOrchestrator starting collector', [
            'collector_name' => $collectorName,
            'message' => substr($message, 0, 100),
        ]);

        $discoveredCollectors = $this->collectorDiscovery->discoverCollectors(useCache: true, includeRemote: true);

        if (isset($discoveredCollectors[$collectorName])) {
            $collectorInfo = $discoveredCollectors[$collectorName];

            if (($collectorInfo['source'] ?? 'local') === 'remote' && !empty($collectorInfo['node_slug'])) {
                return $routeToNode($collectorInfo['node_slug'], $message, $context, $options);
            }

            if (!empty($collectorInfo['class']) && class_exists($collectorInfo['class'])) {
                $configClass = $collectorInfo['class'];
                if (method_exists($configClass, 'getConfig')) {
                    $config = $configClass::getConfig();
                } elseif (method_exists($configClass, 'create')) {
                    $config = $configClass::create();
                } else {
                    $config = new $configClass();
                }

                return $this->collectorHandler->handle($message, $context, array_merge($options, [
                    'action' => 'start_autonomous_collector',
                    'collector_match' => [
                        'name' => $collectorName,
                        'config' => $config,
                        'description' => $collectorInfo['description'] ?? '',
                    ],
                ]));
            }
        }

        $match = AutonomousCollectorRegistry::findConfigForMessage($message);
        if (!$match) {
            Log::channel('ai-engine')->error('Collector not found', [
                'collector_name' => $collectorName,
                'discovered_collectors' => array_keys($discoveredCollectors),
            ]);

            return AgentResponse::failure(
                message: $this->runtimeText(
                    'ai-engine::runtime.agent_action_execution.collector_not_available',
                    "Collector '{$collectorName}' not available",
                    ['collector' => $collectorName]
                ),
                context: $context
            );
        }

        return $this->collectorHandler->handle($message, $context, array_merge($options, [
            'action' => 'start_autonomous_collector',
            'collector_match' => $match,
        ]));
    }

    public function executeResumeSession(UnifiedActionContext $context): AgentResponse
    {
        $sessionStack = $context->get('session_stack', []);

        if (empty($sessionStack)) {
            return AgentResponse::conversational(
                message: $this->runtimeText(
                    'ai-engine::runtime.agent_action_execution.no_paused_session',
                    "There's no paused session to resume."
                ),
                context: $context
            );
        }

        $pausedSession = array_pop($sessionStack);
        unset($pausedSession['paused_at'], $pausedSession['paused_reason']);

        $context->set('autonomous_collector', $pausedSession);

        if (empty($sessionStack)) {
            $context->forget('session_stack');
        } else {
            $context->set('session_stack', $sessionStack);
        }

        $collectorName = $pausedSession['config_name'];

        return AgentResponse::needsUserInput(
            message: $this->runtimeText(
                'ai-engine::runtime.agent_action_execution.resume_welcome',
                "Welcome back! Let's continue with your {$collectorName}.",
                ['collector' => $collectorName]
            ),
            context: $context
        );
    }

    public function executePauseAndHandle(
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $searchRag
    ): AgentResponse {
        $activeCollector = $context->get('autonomous_collector');

        if ($activeCollector) {
            $sessionStack = $context->get('session_stack', []);
            $activeCollector['paused_at'] = now()->toIso8601String();
            $sessionStack[] = $activeCollector;

            $context->set('session_stack', $sessionStack);
            $context->forget('autonomous_collector');

            Log::channel('ai-engine')->debug('Session paused', [
                'collector' => $activeCollector['config_name'],
            ]);

            return $searchRag($message, $context, $options);
        }

        Log::channel('ai-engine')->warning('pause_and_handle called with no active collector');

        return $searchRag($message, $context, $options);
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
