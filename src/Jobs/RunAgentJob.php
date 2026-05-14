<?php

declare(strict_types=1);

namespace LaravelAIEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunRetentionService;
use LaravelAIEngine\Services\Agent\AgentRunSafetyService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentTraceMetadataService;

class RunAgentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;
    public int $timeout;
    public array $backoff;

    public function __construct(
        public int|string $runId,
        public string $message,
        public string $sessionId,
        public mixed $userId = null,
        public array $options = []
    ) {
        $this->tries = max(1, (int) config('ai-agent.run_safety.queue.tries', 3));
        $this->timeout = max(1, (int) config('ai-agent.run_safety.queue.timeout', 300));
        $this->backoff = (array) config('ai-agent.run_safety.queue.backoff', [30, 60, 120]);

        if ($connection = config('ai-agent.run_safety.queue.connection')) {
            $this->onConnection((string) $connection);
        }

        $this->onQueue((string) config('ai-agent.run_safety.queue.name', 'ai-agent'));
    }

    public function handle(
        AgentRuntimeContract $runtime,
        AgentRunRepository $runs,
        AgentRunStepRepository $steps,
        AgentRunSafetyService $safety,
        ?AgentRunEventStreamService $events = null
    ): void {
        $events ??= app(AgentRunEventStreamService::class);

        if (!$this->claimIdempotency($safety)) {
            return;
        }

        $safety->withRunLock($this->runId, function () use ($runtime, $runs, $steps, $safety, $events): void {
            $run = $runs->findOrFail($this->runId);
            $this->assertPolicyAllowsRun($run);
            $scopedOptions = $safety->applyScopeToMetadata($this->options);
            $scope = $safety->currentScope($scopedOptions);
            $safety->assertRunScope($run, $scope);

            $input = [
                'message' => $this->message,
                'session_id' => $this->sessionId,
                'user_id' => $this->userId,
                'options' => $scopedOptions,
            ];
            $retention = app(AgentRunRetentionService::class);
            $runtimeName = $runtime->name();

            $runAttributes = [
                'runtime' => $runtimeName,
                'input' => $retention->protectInput($input),
                'started_at' => $run->started_at ?? now(),
            ];
            if ($run->tenant_id === null && ($scope['tenant_id'] ?? null) !== null) {
                $runAttributes['tenant_id'] = $scope['tenant_id'];
            }
            if ($run->workspace_id === null && ($scope['workspace_id'] ?? null) !== null) {
                $runAttributes['workspace_id'] = $scope['workspace_id'];
            }

            $run = $runs->transition($run, AIAgentRun::STATUS_RUNNING, $runAttributes);

            $step = $steps->create($run, [
                'step_key' => $this->stepKey(),
                'type' => $this->stepType(),
                'status' => AIAgentRun::STATUS_RUNNING,
                'action' => $this->stepAction(),
                'source' => $runtimeName,
                'input' => $retention->protectInput(['message' => $this->message, 'options' => $scopedOptions]),
                'started_at' => now(),
            ]);
            $traceMetadata = app(AgentTraceMetadataService::class);
            $traceId = $traceMetadata->traceId($scopedOptions, $run);
            $runtimeOptions = array_merge($scopedOptions, [
                'agent_run_id' => $run->id,
                'agent_run_uuid' => $run->uuid,
                'agent_run_step_id' => $step->id,
                'agent_run_step_uuid' => $step->uuid,
                'trace_id' => $traceId,
                'runtime' => $runtimeName,
                'decision_source' => $step->source,
            ]);
            $events->emit(AgentRunEventStreamService::RUN_STARTED, $run, $step, [
                'runtime' => $runtimeName,
                'session_id' => $this->sessionId,
            ], $runtimeOptions);

            try {
                $response = $runtime->process($this->message, $this->sessionId, $this->userId, $runtimeOptions);
                $response = $traceMetadata->enrichResponse($response, $runtimeOptions, $run, $step);
                $this->complete($runs, $steps, $run, $step, $response);
                $events->emit(AgentRunEventStreamService::RUN_COMPLETED, $run, $step, [
                    'needs_user_input' => $response->needsUserInput,
                    'success' => $response->success,
                ], $runtimeOptions);
            } catch (\Throwable $e) {
                $this->failRun($runs, $steps, $run, $step, $e);
                $events->emit(AgentRunEventStreamService::RUN_FAILED, $run, $step, [
                    'error' => $e->getMessage(),
                ], $runtimeOptions);

                throw $e;
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        $run = app(AgentRunRepository::class)->find($this->runId);
        if ($run instanceof AIAgentRun && !$run->isTerminal()) {
            app(AgentRunRepository::class)->transition($run, AIAgentRun::STATUS_FAILED, [
                'failure_reason' => $exception->getMessage(),
                'failed_at' => now(),
            ]);
        }
    }

    protected function complete(
        AgentRunRepository $runs,
        AgentRunStepRepository $steps,
        AIAgentRun $run,
        AIAgentRunStep $step,
        AgentResponse $response
    ): void {
        $requestedStatus = $response->metadata['agent_run_status'] ?? null;
        $status = is_string($requestedStatus) && in_array($requestedStatus, AIAgentRun::STATUSES, true)
            ? $requestedStatus
            : ($response->needsUserInput
            ? AIAgentRun::STATUS_WAITING_INPUT
            : AIAgentRun::STATUS_COMPLETED);
        $isWaiting = in_array($status, [AIAgentRun::STATUS_WAITING_INPUT, AIAgentRun::STATUS_WAITING_APPROVAL], true);

        $steps->transition($step, $status, [
            'output' => app(AgentRunRetentionService::class)->protectResponse($response->toArray()),
            'routing_decision' => $response->metadata['routing_decision'] ?? $step->routing_decision,
            'routing_trace' => $response->metadata['routing_trace'] ?? $step->routing_trace,
            'metadata' => array_merge($step->metadata ?? [], app(AgentTraceMetadataService::class)->spanMetadata(
                "agent.{$step->type}.{$step->action}",
                ['ai.agent.status' => $status],
                ['trace_id' => $response->metadata['trace_id'] ?? null],
                $run,
                $step
            ), [
                'duration_ms' => $this->durationMs($step),
            ]),
            'completed_at' => now(),
        ]);

        $runs->transition($run, $status, [
            'final_response' => app(AgentRunRetentionService::class)->protectResponse($response->toArray()),
            'current_step' => $step->step_key,
            'routing_trace' => $response->metadata['routing_trace'] ?? $run->routing_trace,
            'metadata' => array_merge($run->metadata ?? [], array_filter([
                'trace_id' => $response->metadata['trace_id'] ?? null,
                'langgraph_run_id' => $response->metadata['langgraph_run_id'] ?? null,
                'langgraph_thread_id' => $response->metadata['langgraph_thread_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')),
            'waiting_at' => $isWaiting ? now() : null,
            'completed_at' => $isWaiting ? null : now(),
        ]);
    }

    protected function failRun(
        AgentRunRepository $runs,
        AgentRunStepRepository $steps,
        AIAgentRun $run,
        AIAgentRunStep $step,
        \Throwable $e
    ): void {
        $steps->transition($step, AIAgentRun::STATUS_FAILED, [
            'error' => $e->getMessage(),
            'metadata' => array_merge($step->metadata ?? [], app(AgentTraceMetadataService::class)->spanMetadata(
                "agent.{$step->type}.{$step->action}",
                [
                    'ai.agent.status' => AIAgentRun::STATUS_FAILED,
                    'exception.message' => $e->getMessage(),
                ],
                [],
                $run,
                $step
            ), [
                'duration_ms' => $this->durationMs($step),
            ]),
            'failed_at' => now(),
        ]);

        $runs->transition($run, AIAgentRun::STATUS_FAILED, [
            'failure_reason' => $e->getMessage(),
            'failed_at' => now(),
        ]);
    }

    protected function assertPolicyAllowsRun(AIAgentRun $run): void
    {
        $maxSteps = (int) config('ai-agent.run_safety.queue.max_steps', 50);
        if ($maxSteps > 0 && $run->steps()->count() >= $maxSteps) {
            throw new \RuntimeException("Agent run exceeded max step limit [{$maxSteps}].");
        }

        $maxTokens = config('ai-agent.run_safety.queue.max_tokens');
        $estimatedTokens = $this->options['estimated_tokens'] ?? $run->metadata['estimated_tokens'] ?? null;
        if ($maxTokens !== null && $estimatedTokens !== null && (int) $estimatedTokens > (int) $maxTokens) {
            throw new \RuntimeException("Agent run exceeded max token limit [{$maxTokens}].");
        }

        $maxCost = config('ai-agent.run_safety.queue.max_cost');
        $estimatedCost = $this->options['estimated_cost'] ?? $run->metadata['estimated_cost'] ?? null;
        if ($maxCost !== null && $estimatedCost !== null && (float) $estimatedCost > (float) $maxCost) {
            throw new \RuntimeException("Agent run exceeded max cost limit [{$maxCost}].");
        }
    }

    protected function claimIdempotency(AgentRunSafetyService $safety): bool
    {
        $key = $this->options['_idempotency_key'] ?? $this->options['idempotency_key'] ?? null;
        if ($key === null || $key === '') {
            return true;
        }

        return $safety->rememberIdempotencyKey((string) $key, [
            'run_id' => $this->runId,
            'job' => static::class,
        ]);
    }

    protected function stepKey(): string
    {
        return 'run';
    }

    protected function stepType(): string
    {
        return 'agent_run';
    }

    protected function stepAction(): string
    {
        return 'process';
    }

    protected function durationMs(AIAgentRunStep $step): int
    {
        if ($step->started_at === null) {
            return 0;
        }

        return (int) max(0, $step->started_at->diffInMilliseconds(now()));
    }
}
