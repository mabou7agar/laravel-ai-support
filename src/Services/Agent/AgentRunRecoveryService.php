<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;

class AgentRunRecoveryService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly AgentRunStepRepository $steps
    ) {}

    public function replayFailedStep(AIAgentRunStep|int|string $step, array $overrides = []): AIAgentRunStep
    {
        $failedStep = $step instanceof AIAgentRunStep ? $step : $this->steps->findOrFail($step);
        if ($failedStep->status !== AIAgentRun::STATUS_FAILED) {
            throw new \InvalidArgumentException('Only failed agent run steps can be replayed.');
        }

        $attributes = [
            'step_key' => $failedStep->step_key,
            'type' => $failedStep->type,
            'status' => AIAgentRun::STATUS_PENDING,
            'action' => $failedStep->action,
            'source' => $failedStep->source,
            'input' => $failedStep->input,
            'routing_decision' => $failedStep->routing_decision,
            'routing_trace' => $failedStep->routing_trace,
            'metadata' => array_merge($failedStep->metadata ?? [], [
                'replay_of_step_id' => $failedStep->id,
            ]),
        ];
        if (isset($overrides['metadata']) && is_array($overrides['metadata'])) {
            $overrides['metadata'] = array_merge($attributes['metadata'], $overrides['metadata']);
        }

        $replay = $this->steps->create((int) $failedStep->run_id, array_merge($attributes, $overrides));

        $run = $failedStep->run;
        if ($run instanceof AIAgentRun) {
            $this->runs->transition($run, AIAgentRun::STATUS_PENDING, [
                'current_step' => $replay->step_key,
                'failure_reason' => null,
                'metadata' => $this->appendRecoveryEvent($run, 'failed_step_replayed', [
                    'failed_step_id' => $failedStep->id,
                    'replay_step_id' => $replay->id,
                ]),
            ]);
        }

        return $replay;
    }

    public function resumeFromStep(AIAgentRunStep|int|string $step, array $metadata = []): AIAgentRun
    {
        $resumeStep = $step instanceof AIAgentRunStep ? $step : $this->steps->findOrFail($step);
        $run = $resumeStep->run;
        if (!$run instanceof AIAgentRun) {
            throw new \InvalidArgumentException('Agent run step is not linked to a run.');
        }

        return $this->runs->transition($run, AIAgentRun::STATUS_RUNNING, [
            'current_step' => $resumeStep->step_key,
            'failure_reason' => null,
            'metadata' => $this->appendRecoveryEvent($run, 'resumed_from_step', array_merge([
                'step_id' => $resumeStep->id,
            ], $metadata)),
        ]);
    }

    public function markManuallyResolved(AIAgentRun|int|string $run, ?string $actorId = null, ?string $reason = null, array $finalResponse = []): AIAgentRun
    {
        $record = $run instanceof AIAgentRun ? $run : $this->runs->findOrFail($run);

        return $this->runs->transition($record, AIAgentRun::STATUS_COMPLETED, [
            'final_response' => $finalResponse ?: ['message' => 'Agent run manually resolved.'],
            'failure_reason' => null,
            'completed_at' => now(),
            'metadata' => $this->appendRecoveryEvent($record, 'manually_resolved', [
                'actor_id' => $actorId,
                'reason' => $reason,
            ]),
        ]);
    }

    private function appendRecoveryEvent(AIAgentRun $run, string $event, array $payload = []): array
    {
        $metadata = $run->metadata ?? [];
        $events = is_array($metadata['recovery_events'] ?? null) ? $metadata['recovery_events'] : [];
        $events[] = [
            'event' => $event,
            'payload' => $payload,
            'recorded_at' => now()->toIso8601String(),
        ];
        $metadata['recovery_events'] = $events;

        return $metadata;
    }
}
