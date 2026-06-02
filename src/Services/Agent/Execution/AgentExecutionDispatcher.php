<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Execution;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\ProviderTools\ProviderToolAuditService;

class AgentExecutionDispatcher
{
    public function __construct(
        protected AgentActionExecutionService $actionExecutionService,
        protected AgentConversationService $conversationService,
        protected NodeSessionManager $nodeSessionManager,
        protected GoalAgentService $goalAgent,
        protected ?ProviderToolAuditService $audit = null,
        protected ?AgentExecutionPolicyService $policy = null,
        protected ?AgentSelectionService $selectionService = null
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
            RoutingDecisionAction::CONVERSATIONAL => $this->conversationService->executeConversational($message, $context, $options),
            RoutingDecisionAction::HANDLE_SELECTION => $this->executeSelection($decision, $message, $context, $options),
            RoutingDecisionAction::SEARCH_RAG => $this->executeSearchRag($message, $context, $options, $reroute),
            RoutingDecisionAction::USE_TOOL => $this->executeTool($decision, $message, $context, $options),
            RoutingDecisionAction::RUN_SUB_AGENT => $this->executeSubAgent($decision, $message, $context, $options),
            RoutingDecisionAction::CONTINUE_NODE => $this->executeContinueNode($message, $context, $options),
            RoutingDecisionAction::ROUTE_TO_NODE => $this->executeRouteToNode($decision, $message, $context, $options, $reroute),
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

    protected function executeSearchRag(
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?callable $reroute
    ): AgentResponse {
        foreach ($this->requestedCollections($options) as $collection) {
            if (!$this->policy()->canUseRagCollection($collection, $options)) {
                return $this->policyBlockedResponse('rag_collection', $collection, $context, $options);
            }
        }

        return $this->conversationService->executeSearchRAG(
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
            return $this->policyBlockedResponse('tool', $toolName, $context, $options);
        }

        $this->recordExecutionAudit('agent_tool.started', $toolName, $context, $options, $decision->payload);

        try {
            $response = $this->actionExecutionService->executeUseTool(
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

            $this->recordExecutionAudit($this->toolFinishedEvent($response), $toolName, $context, $options, [
                'success' => $response->success,
                'needs_user_input' => $response->needsUserInput,
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
                return $this->policyBlockedResponse('sub_agent', $subAgent, $context, $options);
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

    protected function executeContinueNode(string $message, UnifiedActionContext $context, array $options): AgentResponse
    {
        $response = $this->nodeSessionManager->continueSession($message, $context, $options);

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
            return $this->policyBlockedResponse('node', $requestedResource, $context, $options);
        }

        Log::channel('ai-engine')->info('Routing message to remote node', [
            'requested_resource' => $requestedResource,
            'session_id' => $context->sessionId,
        ]);

        $response = $this->nodeSessionManager->routeToNode($requestedResource, $message, $context, $options);
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
                $fallback->metadata = array_merge($fallback->metadata ?? [], [
                    'fallback_mode' => true,
                    'fallback_reason' => 'remote_node_unreachable',
                    'original_resource' => $requestedResource,
                ]);

                return $fallback;
            }
        }

        return $response;
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

    protected function toolFinishedEvent(AgentResponse $response): string
    {
        if ($response->needsUserInput) {
            return 'agent_tool.progress';
        }

        return $response->success ? 'agent_tool.completed' : 'agent_tool.failed';
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

    protected function policyBlockedResponse(
        string $type,
        string $resource,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $metadata = [
            'policy_blocked' => true,
            'blocked_type' => $type,
            'blocked_resource' => $resource,
        ];

        Log::channel('ai-engine')->warning('Agent execution blocked by policy', array_merge($metadata, [
            'session_id' => $context->sessionId,
            'user_id' => $context->userId,
        ]));

        $this->recordExecutionAudit('agent_policy.blocked', $resource, $context, $options, $metadata);

        return AgentResponse::failure(
            message: $this->policy()->blockedMessage($type, $resource),
            context: $context,
            metadata: $metadata
        );
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
            'agent_tool.progress' => AgentRunEventStreamService::TOOL_PROGRESS,
            'agent_tool.completed' => AgentRunEventStreamService::TOOL_COMPLETED,
            'agent_tool.failed' => AgentRunEventStreamService::TOOL_FAILED,
            'agent_sub_agent.started' => AgentRunEventStreamService::SUB_AGENT_STARTED,
            'agent_sub_agent.completed' => AgentRunEventStreamService::SUB_AGENT_COMPLETED,
            default => null,
        };

        if ($streamEvent !== null) {
            try {
                app(AgentRunEventStreamService::class)->emit(
                    $streamEvent,
                    $options['agent_run_id'] ?? null,
                    $options['agent_run_step_id'] ?? null,
                    array_merge($payload, ['tool_name' => $toolName]),
                    ['trace_id' => $options['trace_id'] ?? null]
                );
            } catch (\Throwable $e) {
                Log::channel('ai-engine')->warning('Agent run event stream emit failed (best-effort)', [
                    'event' => $event,
                    'stream_event' => $streamEvent,
                    'tool_name' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }
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
