<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentContextRepository;

class ContextManager
{
    public function __construct(
        protected ?ConversationContextCompactor $compactor = null,
        protected ?AgentContextRepository $contexts = null
    ) {
        if ($this->compactor === null) {
            try {
                $this->compactor = app()->bound(ConversationContextCompactor::class)
                    ? app(ConversationContextCompactor::class)
                    : new ConversationContextCompactor();
            } catch (\Throwable) {
                $this->compactor = new ConversationContextCompactor();
            }
        }

        $this->contexts ??= new AgentContextRepository();
    }

    public function getOrCreate(string $sessionId, $userId, ?string $contextScope = null): UnifiedActionContext
    {
        $context = $this->contexts->find($sessionId, $userId, $contextScope);
        
        if (!$context) {
            $context = ($contextScope === null ? $this->restoreFromAgentRuns($sessionId, $userId) : null)
                ?? new UnifiedActionContext(
                    sessionId: $sessionId,
                    userId: $userId,
                    contextScope: $contextScope
                );

            Log::channel('ai-engine')->info('New agent context created', [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'restored_from_agent_runs' => isset($context->metadata['restored_from_agent_run_id']),
            ]);
        } else {
            Log::channel('ai-engine')->info('Agent context loaded from cache', [
                'session_id' => $sessionId,
                'current_strategy' => $context->currentStrategy,
                'current_flow' => $context->currentFlow,
                'current_step' => $context->currentStep,
                'runtime_state_keys' => array_keys($context->runtimeState),
            ]);

            if ($contextScope === null) {
                $this->restoreMissingDurableState($context, $sessionId, $userId);
            }
        }

        $this->compactor->compact($context);
        
        return $context;
    }

    /**
     * @param array<string, mixed> $options Current-request scope (tenant_id/workspace_id) forwarded
     *                                       to compaction so memory writes use the live request scope.
     */
    public function save(UnifiedActionContext $context, array $options = []): void
    {
        $this->compactor->compact($context, $options);
        $this->contexts->save($context);
        
        Log::channel('ai-engine')->info('Agent context saved to cache', [
            'session_id' => $context->sessionId,
            'strategy' => $context->currentStrategy,
            'current_flow' => $context->currentFlow,
            'current_step' => $context->currentStep,
        ]);
    }

    public function clear(string $sessionId, mixed $userId = null, ?string $contextScope = null): void
    {
        $this->contexts->forget($sessionId, $userId, $contextScope);
        
        Log::channel('ai-engine')->info('Agent context cleared', [
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);
    }

    public function exists(string $sessionId, mixed $userId = null, ?string $contextScope = null): bool
    {
        return $this->contexts->exists($sessionId, $userId, $contextScope);
    }
    
    public function load(string $sessionId, mixed $userId = null, ?string $contextScope = null): ?UnifiedActionContext
    {
        return $this->contexts->find($sessionId, $userId, $contextScope);
    }

    protected function restoreFromAgentRuns(string $sessionId, mixed $userId): ?UnifiedActionContext
    {
        try {
            $runs = AIAgentRun::query()
                ->where('session_id', $sessionId)
                ->when(
                    $userId !== null && $userId !== '',
                    fn ($query) => $query->where('user_id', (string) $userId),
                    fn ($query) => $query->whereNull('user_id')
                )
                ->latest('id')
                ->limit(12)
                ->get();
        } catch (\Throwable) {
            return null;
        }

        if ($runs->isEmpty()) {
            return null;
        }

        $history = [];
        foreach ($runs->reverse() as $run) {
            $inputMessage = $run->input['message'] ?? null;
            if (is_string($inputMessage) && trim($inputMessage) !== '') {
                $history[] = [
                    'role' => 'user',
                    'content' => $inputMessage,
                    'timestamp' => optional($run->created_at)->toIso8601String(),
                ];
            }

            $finalResponse = is_array($run->final_response) ? $run->final_response : [];
            $responseMessage = $finalResponse['message'] ?? null;
            if (is_string($responseMessage) && trim($responseMessage) !== '') {
                $history[] = [
                    'role' => 'assistant',
                    'content' => $responseMessage,
                    'metadata' => (array) ($finalResponse['metadata'] ?? []),
                    'timestamp' => optional($run->completed_at ?? $run->waiting_at ?? $run->updated_at)->toIso8601String(),
                ];
            }
        }

        $context = new UnifiedActionContext(
            sessionId: $sessionId,
            userId: $userId,
            conversationHistory: array_values(array_filter($history))
        );

        foreach ($runs as $run) {
            $response = (array) ($run->final_response ?? []);
            $aiNative = $this->durableAiNativeFromResponse($response);
            if ($aiNative !== null) {
                $context->metadata['ai_native'] = $aiNative;
                $context->metadata['restored_from_agent_run_id'] = $run->uuid ?: $run->id;
                break;
            }
        }

        return $context;
    }

    protected function restoreMissingDurableState(UnifiedActionContext $context, string $sessionId, mixed $userId): void
    {
        if ($this->durableAiNativeFromContext($context) !== null) {
            return;
        }

        $restored = $this->restoreFromAgentRuns($sessionId, $userId);
        if (!$restored instanceof UnifiedActionContext) {
            return;
        }

        $aiNative = $this->durableAiNativeFromContext($restored);
        if ($aiNative === null) {
            return;
        }

        $context->metadata['ai_native'] = $aiNative;

        $context->metadata['restored_from_agent_run_id'] = $restored->metadata['restored_from_agent_run_id'] ?? null;

        if ($context->conversationHistory === [] && $restored->conversationHistory !== []) {
            $context->conversationHistory = $restored->conversationHistory;
        }
    }

    protected function activeAiNativeFromContext(UnifiedActionContext $context): ?array
    {
        $state = $context->metadata['ai_native'] ?? null;

        return $this->isActiveAiNativeState($state) ? $state : null;
    }

    protected function durableAiNativeFromContext(UnifiedActionContext $context): ?array
    {
        $state = $context->metadata['ai_native'] ?? null;

        return $this->isDurableAiNativeState($state) ? $state : null;
    }

    protected function activeAiNativeFromResponse(array $response): ?array
    {
        $metadata = (array) ($response['metadata'] ?? []);

        foreach ([
            $metadata['ai_native'] ?? null,
            $metadata['metadata']['ai_native'] ?? null,
            $response['data']['ai_native'] ?? null,
            $response['data']['metadata']['ai_native'] ?? null,
        ] as $state) {
            if ($this->isActiveAiNativeState($state)) {
                return $state;
            }
        }

        return null;
    }

    protected function durableAiNativeFromResponse(array $response): ?array
    {
        $active = $this->activeAiNativeFromResponse($response);
        if ($active !== null) {
            return $active;
        }

        $metadata = (array) ($response['metadata'] ?? []);

        foreach ([
            $metadata['ai_native'] ?? null,
            $metadata['metadata']['ai_native'] ?? null,
            $response['data']['ai_native'] ?? null,
            $response['data']['metadata']['ai_native'] ?? null,
        ] as $state) {
            if ($this->isDurableAiNativeState($state)) {
                return $state;
            }
        }

        return null;
    }

    protected function isActiveAiNativeState(mixed $state): bool
    {
        if (!is_array($state)) {
            return false;
        }

        if (is_array($state['pending_tool'] ?? null) || is_array($state['suggested_tool_continuation'] ?? null)) {
            return true;
        }

        $taskFrame = $state['task_frame'] ?? null;

        return is_array($taskFrame)
            && !empty($taskFrame['active_objective'])
            && ($taskFrame['status'] ?? 'working') !== 'completed';
    }

    protected function isDurableAiNativeState(mixed $state): bool
    {
        if ($this->isActiveAiNativeState($state)) {
            return true;
        }

        if (!is_array($state)) {
            return false;
        }

        $taskFrame = is_array($state['task_frame'] ?? null) ? $state['task_frame'] : [];

        return is_array($state['recent_outcomes'] ?? null)
            || is_array($state['tool_results'] ?? null)
            || is_array($taskFrame['completed_writes'] ?? null)
            || is_array($taskFrame['current_payload'] ?? null);
    }
}
