<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIProviderToolApproval;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Services\Agent\AgentRunApprovalService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\ContextManager;

/**
 * Agent runtime backed by the official laravel/ai SDK.
 *
 * The SDK runs the model and tool loop; this runtime keeps the guarantees the
 * package is responsible for, so a run on `laravel_ai` behaves like a run on
 * the built-in runtime from the outside:
 *
 * - streaming: SDK stream events are mirrored into AgentRunEventStreamService
 *   (final_response.token_streamed, tool.started/completed/failed);
 * - approvals: SDK tool approvals become package step approvals, and an
 *   approved/rejected resume is replayed into the SDK as tool decisions;
 * - cancellation: a run cancelled mid-stream stops consuming the SDK stream;
 * - usage: SDK token usage lands in response metadata in the shape the run
 *   budget expects;
 * - recovery: provider failures are thrown so RunAgentJob fails and retries the run.
 *
 * Opt-in only: registered when laravel/ai is installed and
 * `ai-agent.runtime.laravel_ai.enabled` is true, selected via `agent_runtime`
 * or `ai-agent.runtime.default`.
 */
class LaravelAiAgentRuntime implements AgentRuntimeContract
{
    public function __construct(
        protected ?LaravelAiGateway $gateway = null
    ) {
    }

    public function name(): string
    {
        return LaravelAiSdk::RUNTIME;
    }

    public function capabilities(): AgentRuntimeCapabilities
    {
        $available = $this->gateway() !== null;

        return new AgentRuntimeCapabilities(
            streaming: $available && $this->streamingEnabled(),
            interrupts: $available,
            tools: $available,
            artifacts: false,
            humanApprovals: $available,
            subAgents: false,
            remoteCallbacks: false,
            metadata: [
                'runtime' => $this->name(),
                'sdk' => 'laravel/ai',
                'sdk_installed' => LaravelAiSdk::installed(),
                'available' => $available,
            ]
        );
    }

    public function process(
        string $message,
        string $sessionId,
        mixed $userId,
        array $options = []
    ): AgentResponse {
        $gateway = $this->gateway();
        if ($gateway === null) {
            return AgentResponse::failure(
                message: 'The laravel_ai agent runtime requires the laravel/ai package (composer require laravel/ai).',
                data: [
                    'runtime' => $this->name(),
                    'sdk_installed' => false,
                ]
            );
        }

        $context = $this->context($sessionId, $userId);
        $resume = $this->resumeState($options);
        $invocation = new LaravelAiInvocation(
            message: $message,
            sessionId: $sessionId,
            userId: $userId,
            conversationId: $resume['conversation_id'] ?? $this->contextConversationId($context),
            decisions: $resume['decisions'] ?? null,
            provider: $this->stringOption($options, 'laravel_ai_provider') ?? $this->stringConfig('provider'),
            model: $this->stringOption($options, 'laravel_ai_model') ?? $this->stringConfig('model'),
            timeout: $this->timeout(),
            stream: $this->streamingEnabled() && ($options['laravel_ai_stream'] ?? true) !== false,
            scope: array_filter([
                'tenant_id' => $options['tenant_id'] ?? null,
                'workspace_id' => $options['workspace_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            run: array_filter([
                'agent_run_id' => $options['agent_run_id'] ?? null,
                'agent_run_uuid' => $options['agent_run_uuid'] ?? null,
                'agent_run_step_id' => $options['agent_run_step_id'] ?? null,
                'agent_run_step_uuid' => $options['agent_run_step_uuid'] ?? null,
                'trace_id' => $options['trace_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            options: $options
        );

        $mirror = new LaravelAiStreamMirror(
            $this->events(),
            $options,
            max(1, (int) config('ai-agent.runtime.laravel_ai.cancellation_check_every', 20))
        );

        $turn = $gateway->run($invocation, $invocation->stream ? $mirror->listener() : null);
        $mirror->finish($turn);

        $this->rememberTurn($context, $invocation, $turn);

        if ($turn->hasPendingApprovals() && !$turn->cancelled) {
            return $this->approvalResponse($turn, $invocation, $context, $options);
        }

        $response = AgentResponse::success(
            message: $turn->text,
            data: array_filter([
                'laravel_ai_conversation_id' => $turn->conversationId,
            ], static fn (mixed $value): bool => $value !== null),
            context: $context
        );
        $response->metadata = $this->metadata($turn, $invocation, $options);

        return $response;
    }

    protected function approvalResponse(
        LaravelAiTurn $turn,
        LaravelAiInvocation $invocation,
        ?UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $pending = $turn->pendingApprovals;
        $tools = array_values(array_unique(array_map(static fn (array $approval): string => (string) $approval['tool'], $pending)));
        $reason = collect($pending)->pluck('reason')->filter()->first();
        $resumePayload = array_filter([
            'laravel_ai_conversation_id' => $turn->conversationId,
            'laravel_ai_invocation_id' => $turn->invocationId,
            'laravel_ai_pending_approvals' => array_values(array_map(static fn (array $approval): string => (string) $approval['id'], $pending)),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        $message = $turn->text !== ''
            ? $turn->text
            : 'Approval required before running: ' . implode(', ', $tools) . '.';

        $response = new AgentResponse(
            success: true,
            message: $message,
            data: [
                'pending_approvals' => $pending,
                'laravel_ai_conversation_id' => $turn->conversationId,
            ],
            context: $context,
            needsUserInput: true,
            isComplete: false
        );
        $metadata = $this->metadata($turn, $invocation, $options);

        $step = isset($options['agent_run_step_id'])
            ? $this->steps()->find($options['agent_run_step_id'])
            : null;

        if ($step !== null) {
            $approval = $this->approvals()->requestStepApproval(
                $step,
                [
                    'action' => 'laravel_ai_tool_approval',
                    'tool_name' => count($tools) === 1 ? $tools[0] : 'laravel_ai_tools',
                    'tools' => $tools,
                    'tool_calls' => $pending,
                    'reason' => $reason,
                    'risk_level' => $options['laravel_ai_approval_risk_level'] ?? 'medium',
                ],
                null,
                array_filter([
                    'runtime' => $this->name(),
                    'resume_payload' => $resumePayload,
                    'trace_id' => $options['trace_id'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== [])
            );

            $response->data = array_merge($response->data ?? [], [
                'approval_key' => $approval->approval_key,
                'provider_tool_run_id' => $approval->tool_run_id,
            ]);
            $metadata = array_merge($metadata, [
                'agent_run_status' => AIAgentRun::STATUS_WAITING_APPROVAL,
                'approval_key' => $approval->approval_key,
                'approval_scope' => 'laravel_ai_tool',
            ]);
        } else {
            // Called outside a persisted run: hand the resume state back to the caller.
            $response->data = array_merge($response->data ?? [], ['resume_payload' => $resumePayload]);
        }

        $response->metadata = $metadata;

        return $response;
    }

    /**
     * Resume state for an approval continuation, read from the server-side
     * approval record (never from client-supplied options), and only when that
     * approval belongs to the run being resumed.
     *
     * @return array{conversation_id?:string,decisions?:array<string,bool>}
     */
    protected function resumeState(array $options): array
    {
        $payload = $options['resume_payload'] ?? $options['langgraph_resume_payload'] ?? null;
        $approvalKey = is_array($payload) ? trim((string) ($payload['approval_key'] ?? '')) : '';
        if ($approvalKey === '' || ($options['agent_run_id'] ?? null) === null) {
            return [];
        }

        $approval = $this->approvalRepository()->findByKey($approvalKey);
        if (!$approval instanceof AIProviderToolApproval || !$this->approvalBelongsToRun($approval, $options)) {
            return [];
        }

        $stored = (array) ($approval->metadata['resume_payload'] ?? []);
        $ids = array_values(array_filter(
            (array) ($stored['laravel_ai_pending_approvals'] ?? []),
            static fn (mixed $id): bool => is_string($id) && $id !== ''
        ));

        $approved = match ($approval->status) {
            'approved' => true,
            'rejected' => false,
            default => null,
        };

        if ($ids === [] || $approved === null) {
            return [];
        }

        return array_filter([
            'conversation_id' => is_string($stored['laravel_ai_conversation_id'] ?? null) ? $stored['laravel_ai_conversation_id'] : null,
            'decisions' => array_fill_keys($ids, $approved),
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function approvalBelongsToRun(AIProviderToolApproval $approval, array $options): bool
    {
        $runId = $options['agent_run_id'] ?? null;
        if ($runId === null) {
            return false;
        }

        $approvalRunId = $approval->run?->agent_run_id;

        return $approvalRunId !== null && (string) $approvalRunId === (string) $runId;
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadata(LaravelAiTurn $turn, LaravelAiInvocation $invocation, array $options): array
    {
        $usage = array_filter([
            'input_tokens' => $turn->inputTokens(),
            'output_tokens' => $turn->outputTokens(),
            'tokens_used' => $turn->totalTokens(),
            'cache_read_input_tokens' => $turn->usage['cache_read_input_tokens'] ?? null,
            'cache_write_input_tokens' => $turn->usage['cache_write_input_tokens'] ?? null,
            'reasoning_tokens' => $turn->usage['reasoning_tokens'] ?? null,
            'provider_model' => $turn->model,
        ], static fn (mixed $value): bool => $value !== null);

        $metadata = [
            'agent_runtime' => $this->name(),
            'agent_runtime_capabilities' => $this->capabilities()->toArray(),
            'usage' => $usage,
            'laravel_ai' => array_filter([
                'invocation_id' => $turn->invocationId,
                'conversation_id' => $turn->conversationId,
                'provider' => $turn->provider,
                'model' => $turn->model,
                'streamed' => $invocation->stream,
                'resumed' => $invocation->isResume(),
                'tool_calls' => count($turn->toolCalls),
                'tool_results' => count($turn->toolResults),
                'cancelled' => $turn->cancelled ?: null,
            ], static fn (mixed $value): bool => $value !== null),
        ];

        if ($turn->cancelled) {
            $metadata['agent_run_cancelled'] = true;
        }

        if ($turn->reasoning !== '' && $this->exposeReasoning($options)) {
            $metadata['reasoning_trace'] = [$turn->reasoning];
        }

        return $metadata;
    }

    protected function rememberTurn(?UnifiedActionContext $context, LaravelAiInvocation $invocation, LaravelAiTurn $turn): void
    {
        if ($context === null) {
            return;
        }

        try {
            if (!$invocation->isResume()) {
                $context->addUserMessage($invocation->message);
            }

            if ($turn->text !== '') {
                $context->addAssistantMessage($turn->text, ['runtime' => $this->name()]);
            }

            if ($turn->conversationId !== null) {
                $context->metadata['laravel_ai_conversation_id'] = $turn->conversationId;
            }

            $this->contexts()->save($context);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('ai-engine')->warning('laravel_ai runtime could not persist conversation context', [
                'session_id' => $invocation->sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function context(string $sessionId, mixed $userId): ?UnifiedActionContext
    {
        try {
            return $this->contexts()->getOrCreate($sessionId, $userId);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function contextConversationId(?UnifiedActionContext $context): ?string
    {
        $id = $context?->metadata['laravel_ai_conversation_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function gateway(): ?LaravelAiGateway
    {
        if ($this->gateway instanceof LaravelAiGateway) {
            return $this->gateway;
        }

        if (app()->bound(LaravelAiGateway::class)) {
            return $this->gateway = app(LaravelAiGateway::class);
        }

        return LaravelAiSdk::installed()
            ? $this->gateway = app(SdkLaravelAiGateway::class)
            : null;
    }

    protected function streamingEnabled(): bool
    {
        return (bool) config('ai-agent.runtime.laravel_ai.stream', true);
    }

    protected function exposeReasoning(array $options): bool
    {
        return (bool) ($options['expose_reasoning'] ?? config('ai-agent.ai_native.expose_reasoning', false));
    }

    protected function timeout(): ?int
    {
        $timeout = config('ai-agent.runtime.laravel_ai.timeout');

        return is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : null;
    }

    protected function stringConfig(string $key): ?string
    {
        $value = config("ai-agent.runtime.laravel_ai.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function events(): AgentRunEventStreamService
    {
        return app(AgentRunEventStreamService::class);
    }

    protected function approvals(): AgentRunApprovalService
    {
        return app(AgentRunApprovalService::class);
    }

    protected function approvalRepository(): ProviderToolApprovalRepository
    {
        return app(ProviderToolApprovalRepository::class);
    }

    protected function steps(): AgentRunStepRepository
    {
        return app(AgentRunStepRepository::class);
    }

    protected function contexts(): ContextManager
    {
        return app(ContextManager::class);
    }
}
