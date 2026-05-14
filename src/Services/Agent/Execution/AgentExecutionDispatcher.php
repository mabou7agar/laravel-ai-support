<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Execution;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionFacade;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\DeterministicAgentHandlerRegistry;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolAuditService;

class AgentExecutionDispatcher
{
    public function __construct(
        protected AgentExecutionFacade $execution,
        protected GoalAgentService $goalAgent,
        protected ?ProviderToolAuditService $audit = null,
        protected ?AgentExecutionPolicyService $policy = null,
        protected ?AgentSelectionService $selectionService = null,
        protected ?DeterministicAgentHandlerRegistry $deterministicHandlers = null
    ) {
    }

    public function dispatch(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options = [],
        ?callable $reroute = null
    ): AgentResponse {
        $options = $this->withDecisionMetadata($options, $decision);

        return match ($decision->action) {
            RoutingDecisionAction::CONVERSATIONAL => $this->execution->executeConversational($message, $context, $options),
            RoutingDecisionAction::HANDLE_SELECTION => $this->executeSelection($decision, $message, $context, $options),
            RoutingDecisionAction::RUN_DETERMINISTIC => $this->executeDeterministic($message, $context, $options, $reroute),
            RoutingDecisionAction::SEARCH_RAG => $this->executeSearchRag($message, $context, $options, $reroute),
            RoutingDecisionAction::USE_TOOL => $this->executeTool($decision, $message, $context, $options),
            RoutingDecisionAction::RUN_SUB_AGENT => $this->executeSubAgent($decision, $message, $context, $options),
            RoutingDecisionAction::START_COLLECTOR => $this->executeStartCollector($decision, $message, $context, $options),
            RoutingDecisionAction::CONTINUE_COLLECTOR => $this->execution->continueCollectorSession($message, $context, $options),
            RoutingDecisionAction::CONTINUE_NODE => $this->executeContinueNode($message, $context, $options),
            RoutingDecisionAction::ROUTE_TO_NODE => $this->executeRouteToNode($decision, $message, $context, $options, $reroute),
            RoutingDecisionAction::PAUSE_AND_HANDLE => $this->executePauseAndHandle($message, $context, $options, $reroute),
            RoutingDecisionAction::CONTINUE_RUN => $this->execution->executeResumeSession($context),
            RoutingDecisionAction::NEED_USER_INPUT => AgentResponse::needsUserInput(
                message: $decision->reason,
                data: $decision->payload,
                context: $context,
                requiredInputs: $decision->payload['required_inputs'] ?? null
            ),
            RoutingDecisionAction::FAIL => AgentResponse::failure(
                message: $decision->reason,
                data: $decision->payload,
                context: $context
            ),
            default => AgentResponse::failure(
                message: "Unsupported routing decision action [{$decision->action}].",
                data: $decision->toArray(),
                context: $context
            ),
        };
    }

    protected function executeSelection(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $type = (string) ($decision->payload['selection_type'] ?? '');
        $selection = $this->selectionService();

        if ($type === 'option_selection') {
            $response = $selection->handleOptionSelection(
                $message,
                $context,
                $options,
                fn (string $searchMessage, UnifiedActionContext $searchContext, array $searchOptions): AgentResponse => $this->executeSearchRag($searchMessage, $searchContext, $searchOptions, null),
                fn (string $resourceName, string $routeMessage, UnifiedActionContext $routeContext, array $routeOptions): AgentResponse => $this->executeRouteToNode(
                    new RoutingDecision(
                        action: RoutingDecisionAction::ROUTE_TO_NODE,
                        source: $decision->source,
                        confidence: 'high',
                        reason: 'Selection resolved to a remote node.',
                        payload: ['resource_name' => $resourceName]
                    ),
                    $routeMessage,
                    $routeContext,
                    $routeOptions,
                    null
                )
            );

            return $response ?? AgentResponse::failure(
                message: 'Selected option could not be resolved.',
                context: $context
            );
        }

        return $selection->handlePositionalReference(
            $message,
            $context,
            $options,
            fn (string $searchMessage, UnifiedActionContext $searchContext, array $searchOptions): AgentResponse => $this->executeSearchRag($searchMessage, $searchContext, $searchOptions, null),
            fn (string $resourceName, string $routeMessage, UnifiedActionContext $routeContext, array $routeOptions): AgentResponse => $this->executeRouteToNode(
                new RoutingDecision(
                    action: RoutingDecisionAction::ROUTE_TO_NODE,
                    source: $decision->source,
                    confidence: 'high',
                    reason: 'Selection resolved to a remote node.',
                    payload: ['resource_name' => $resourceName]
                ),
                $routeMessage,
                $routeContext,
                $routeOptions,
                null
            )
        );
    }

    protected function executeDeterministic(
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?callable $reroute
    ): AgentResponse {
        $response = $this->deterministicHandlers()->handle($message, $context, $options);
        if ($response instanceof AgentResponse) {
            return $response;
        }

        if ($reroute !== null) {
            return $reroute($message, $context->sessionId, $context->userId, array_merge($options, [
                'skip_deterministic_handlers' => true,
            ]));
        }

        return $this->execution->executeConversational($message, $context, $options);
    }

    protected function executeSearchRag(
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?callable $reroute
    ): AgentResponse {
        foreach ($this->requestedCollections($options) as $collection) {
            if (!$this->policy()->canUseRagCollection($collection, $options)) {
                return AgentResponse::failure(
                    message: $this->policy()->blockedMessage('rag_collection', $collection),
                    context: $context
                );
            }
        }

        return $this->execution->executeSearchRag(
            $message,
            $context,
            $options,
            $reroute ?? static fn (): AgentResponse => AgentResponse::failure(
                message: 'RAG requested reroute, but no reroute callback was provided.',
                context: $context
            )
        );
    }

    protected function executeTool(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $toolName = (string) ($decision->payload['resource_name'] ?? $decision->payload['tool_name'] ?? '');
        if (!$this->policy()->canUseTool($toolName, $options)) {
            return AgentResponse::failure(
                message: $this->policy()->blockedMessage('tool', $toolName),
                context: $context
            );
        }

        $this->recordExecutionAudit('agent_tool.started', $toolName, $context, $options, $decision->payload);

        try {
            $response = $this->execution->executeUseTool(
                $toolName,
                $message,
                $context,
                array_merge($options, [
                    'tool_params' => is_array($decision->payload['params'] ?? null) ? $decision->payload['params'] : [],
                ]),
                fn (string $searchMessage, UnifiedActionContext $searchContext, array $searchOptions): AgentResponse => $this->executeSearchRag(
                    $searchMessage,
                    $searchContext,
                    $searchOptions,
                    null
                )
            );

            $this->recordExecutionAudit($response->success ? 'agent_tool.completed' : 'agent_tool.failed', $toolName, $context, $options, [
                'success' => $response->success,
                'message' => $response->message,
            ]);

            return $response;
        } catch (\Throwable $e) {
            $this->recordExecutionAudit('agent_tool.failed', $toolName, $context, $options, [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function executeSubAgent(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $target = trim((string) ($decision->payload['target'] ?? $options['target'] ?? $message));

        $subAgents = $decision->payload['sub_agents'] ?? $options['sub_agents'] ?? null;
        foreach ($this->requestedSubAgents($subAgents) as $subAgent) {
            if (!$this->policy()->canUseSubAgent($subAgent, $options)) {
                return AgentResponse::failure(
                    message: $this->policy()->blockedMessage('sub_agent', $subAgent),
                    context: $context
                );
            }
        }

        $this->recordExecutionAudit('agent_sub_agent.started', 'run_sub_agent', $context, $options, [
            'target' => $target,
            'sub_agents' => $subAgents,
        ]);

        try {
            $response = $this->goalAgent->execute($target, $context, array_merge($options, [
                'agent_goal' => true,
                'target' => $target,
                'sub_agents' => $subAgents,
            ]));

            $this->recordExecutionAudit($response->success ? 'agent_sub_agent.completed' : 'agent_sub_agent.failed', 'run_sub_agent', $context, $options, [
                'success' => $response->success,
                'message' => $response->message,
            ]);

            return $response;
        } catch (\Throwable $e) {
            $this->recordExecutionAudit('agent_sub_agent.failed', 'run_sub_agent', $context, $options, [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function executeStartCollector(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->execution->executeStartCollector(
            (string) ($decision->payload['resource_name'] ?? $decision->payload['collector_name'] ?? ''),
            $message,
            $context,
            $options,
            fn (string $resourceName, string $routeMessage, UnifiedActionContext $routeContext, array $routeOptions): AgentResponse => $this->execution->routeToNode(
                $resourceName,
                $routeMessage,
                $routeContext,
                $routeOptions
            )
        );
    }

    protected function executeContinueNode(string $message, UnifiedActionContext $context, array $options): AgentResponse
    {
        $response = $this->execution->continueRoutedSession($message, $context, $options);

        return $response ?? AgentResponse::failure(
            message: 'No routed node session is available to continue.',
            context: $context
        );
    }

    protected function executeRouteToNode(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?callable $reroute
    ): AgentResponse {
        if (!empty($options['local_only'])) {
            Log::channel('ai-engine')->info('Local-only mode enabled, skipping remote routing', [
                'message' => substr($message, 0, 120),
                'session_id' => $context->sessionId,
            ]);

            return $this->executeSearchRag($message, $context, $options, $reroute);
        }

        $requestedResource = trim((string) ($decision->payload['resource_name'] ?? $decision->payload['node_slug'] ?? ''));
        if ($requestedResource === '' || $requestedResource === 'local') {
            Log::channel('ai-engine')->warning('route_to_node decision without resource_name, falling back to RAG', [
                'message' => substr($message, 0, 120),
                'session_id' => $context->sessionId,
            ]);

            return $this->executeSearchRag($message, $context, $options, $reroute);
        }

        if (!$this->policy()->canRouteToNode($requestedResource, $options)) {
            return AgentResponse::failure(
                message: $this->policy()->blockedMessage('node', $requestedResource),
                context: $context
            );
        }

        Log::channel('ai-engine')->info('Routing message to remote node', [
            'requested_resource' => $requestedResource,
            'session_id' => $context->sessionId,
        ]);

        $response = $this->execution->routeToNode($requestedResource, $message, $context, $options);
        if ($this->shouldFallbackToLocalRag($response, $options)) {
            Log::channel('ai-engine')->warning('Remote node routing failed; attempting degraded local fallback', [
                'requested_resource' => $requestedResource,
                'session_id' => $context->sessionId,
            ]);

            $fallback = $this->executeSearchRag($message, $context, array_merge($options, [
                'local_only' => true,
            ]), $reroute);

            if ($fallback->success) {
                $fallback->message = $this->fallbackNotice() . "\n\n" . $fallback->message;

                return $fallback;
            }
        }

        return $response;
    }

    protected function executePauseAndHandle(
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?callable $reroute
    ): AgentResponse {
        return $this->execution->executePauseAndHandle(
            $message,
            $context,
            $options,
            fn (string $searchMessage, UnifiedActionContext $searchContext, array $searchOptions): AgentResponse => $this->executeSearchRag(
                $searchMessage,
                $searchContext,
                $searchOptions,
                $reroute
            )
        );
    }

    protected function withDecisionMetadata(array $options, RoutingDecision $decision): array
    {
        return array_merge([
            'decision_action' => $decision->action,
            'decision_source' => $decision->source,
            'decision_confidence' => $decision->confidence,
            'decision_reason' => $decision->reason,
        ], $options);
    }

    protected function shouldFallbackToLocalRag(AgentResponse $response, array $options): bool
    {
        if ($response->success) {
            return false;
        }

        $enabled = array_key_exists('allow_local_fallback_on_node_failure', $options)
            ? (bool) $options['allow_local_fallback_on_node_failure']
            : (bool) config('ai-engine.nodes.routing.local_fallback_on_failure', false);

        if (!$enabled) {
            return false;
        }

        return str_contains(strtolower($response->message), "couldn't reach remote node");
    }

    protected function fallbackNotice(): string
    {
        $notice = config('ai-engine.nodes.routing.local_fallback_notice');
        if (is_string($notice) && trim($notice) !== '') {
            return trim($notice);
        }

        return 'Remote node is unavailable. Showing local results only (degraded mode).';
    }

    protected function recordExecutionAudit(
        string $event,
        string $toolName,
        UnifiedActionContext $context,
        array $options,
        array $payload = []
    ): void {
        if (!$this->audit instanceof ProviderToolAuditService) {
            return;
        }

        $metadata = array_filter([
            'session_id' => $context->sessionId,
            'user_id' => $context->userId,
            'agent_run_id' => $options['agent_run_id'] ?? null,
            'agent_run_step_id' => $options['agent_run_step_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $this->audit->record($event, null, null, array_merge($payload, [
            'tool_name' => $toolName,
        ]), $metadata, isset($context->userId) ? (string) $context->userId : null);

        $streamEvent = match ($event) {
            'agent_tool.started' => AgentRunEventStreamService::TOOL_STARTED,
            'agent_tool.completed' => AgentRunEventStreamService::TOOL_COMPLETED,
            'agent_tool.failed' => AgentRunEventStreamService::TOOL_FAILED,
            'agent_sub_agent.started' => AgentRunEventStreamService::SUB_AGENT_STARTED,
            'agent_sub_agent.completed' => AgentRunEventStreamService::SUB_AGENT_COMPLETED,
            default => null,
        };

        if ($streamEvent !== null) {
            app(AgentRunEventStreamService::class)->emit(
                $streamEvent,
                $options['agent_run_id'] ?? null,
                $options['agent_run_step_id'] ?? null,
                array_merge($payload, ['tool_name' => $toolName]),
                ['trace_id' => $options['trace_id'] ?? null]
            );
        }
    }

    protected function policy(): AgentExecutionPolicyService
    {
        if ($this->policy instanceof AgentExecutionPolicyService) {
            return $this->policy;
        }

        return $this->policy = app()->bound(AgentExecutionPolicyService::class)
            ? app(AgentExecutionPolicyService::class)
            : new AgentExecutionPolicyService();
    }

    protected function selectionService(): AgentSelectionService
    {
        return $this->selectionService ??= app(AgentSelectionService::class);
    }

    protected function deterministicHandlers(): DeterministicAgentHandlerRegistry
    {
        return $this->deterministicHandlers ??= app(DeterministicAgentHandlerRegistry::class);
    }

    /**
     * @return array<int, string>
     */
    protected function requestedCollections(array $options): array
    {
        $value = $options['rag_collection'] ?? $options['collection'] ?? $options['collections'] ?? [];

        return $this->stringList(is_array($value) ? $value : [$value]);
    }

    /**
     * @return array<int, string>
     */
    protected function requestedSubAgents(mixed $subAgents): array
    {
        $items = is_array($subAgents) ? $subAgents : [$subAgents];

        return array_values(array_filter(array_map(
            static function (mixed $item): string {
                if (is_array($item)) {
                    return trim((string) ($item['agent_id'] ?? $item['id'] ?? ''));
                }

                return trim((string) $item);
            },
            $items
        )));
    }

    /**
     * @param array<int, mixed> $value
     * @return array<int, string>
     */
    protected function stringList(array $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        )));
    }
}
