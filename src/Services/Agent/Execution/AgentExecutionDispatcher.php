<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Execution;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\Execution\RoutingActionHandlerRegistry;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolAuditService;

class AgentExecutionDispatcher
{
    public function __construct(
        protected AgentConversationService $conversationService,
        protected RoutingActionHandlerRegistry $actionHandlers,
        protected GoalAgentService $goalAgent,
        protected ?ProviderToolAuditService $audit = null,
        protected ?AgentExecutionPolicyService $policy = null
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
            RoutingDecisionAction::SEARCH_RAG => $this->executeSearchRag($message, $context, $options, $reroute),
            RoutingDecisionAction::RUN_SUB_AGENT => $this->executeSubAgent($decision, $message, $context, $options),
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
            default => $this->actionHandlers->has($decision->action)
                ? $this->actionHandlers->get($decision->action)->handle($decision, $message, $context, $options, $reroute)
                : AgentResponse::failure(
                    message: "Unsupported routing decision action [{$decision->action}].",
                    data: $decision->toArray(),
                    context: $context
                ),
        };
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

    protected function withDecisionMetadata(array $options, RoutingDecision $decision): array
    {
        return array_merge([
            'decision_action' => $decision->action,
            'decision_source' => $decision->source,
            'decision_confidence' => $decision->confidence,
            'decision_reason' => $decision->reason,
        ], $options);
    }

    /**
     * Public RAG-search delegate used by node action handlers so the
     * executeSearchRag behavior (policy enforcement + RAG search) stays in one place.
     */
    public function searchRag(
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?callable $reroute
    ): AgentResponse {
        return $this->executeSearchRag($message, $context, $options, $reroute);
    }

    /**
     * Public policy accessor for node action handlers, reusing the lazily
     * resolved AgentExecutionPolicyService instance.
     */
    public function policyService(): AgentExecutionPolicyService
    {
        return $this->policy();
    }

    /**
     * Public policy-blocked response delegate for node action handlers,
     * preserving audit + logging behavior in one place.
     */
    public function blockedByPolicy(
        string $type,
        string $resource,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->policyBlockedResponse($type, $resource, $context, $options);
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
