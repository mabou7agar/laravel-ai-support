<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Str;
use LaravelAIEngine\Events\AgentRunStreamed;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;

class AgentRunEventStreamService
{
    public const RUN_STARTED = 'run.started';
    public const ROUTING_STAGE_STARTED = 'routing.stage_started';
    public const ROUTING_STAGE_ABSTAINED = 'routing.stage_abstained';
    public const ROUTING_DECIDED = 'routing.decided';
    public const RAG_STARTED = 'rag.started';
    public const RAG_SOURCES_FOUND = 'rag.sources_found';
    public const RAG_COMPLETED = 'rag.completed';
    public const TOOL_STARTED = 'tool.started';
    public const TOOL_PROGRESS = 'tool.progress';
    public const TOOL_COMPLETED = 'tool.completed';
    public const TOOL_FAILED = 'tool.failed';
    public const SUB_AGENT_STARTED = 'sub_agent.started';
    public const SUB_AGENT_COMPLETED = 'sub_agent.completed';
    public const APPROVAL_REQUIRED = 'approval.required';
    public const APPROVAL_RESOLVED = 'approval.resolved';
    public const ARTIFACT_CREATED = 'artifact.created';
    public const FINAL_RESPONSE_TOKEN_STREAMED = 'final_response.token_streamed';
    public const FINAL_RESPONSE_STREAM_COMPLETED = 'final_response.stream_completed';
    public const RUN_COMPLETED = 'run.completed';
    public const RUN_FAILED = 'run.failed';
    public const RUN_CANCELLED = 'run.cancelled';
    public const RUN_EXPIRED = 'run.expired';

    public const NAMES = [
        self::RUN_STARTED,
        self::ROUTING_STAGE_STARTED,
        self::ROUTING_STAGE_ABSTAINED,
        self::ROUTING_DECIDED,
        self::RAG_STARTED,
        self::RAG_SOURCES_FOUND,
        self::RAG_COMPLETED,
        self::TOOL_STARTED,
        self::TOOL_PROGRESS,
        self::TOOL_COMPLETED,
        self::TOOL_FAILED,
        self::SUB_AGENT_STARTED,
        self::SUB_AGENT_COMPLETED,
        self::APPROVAL_REQUIRED,
        self::APPROVAL_RESOLVED,
        self::ARTIFACT_CREATED,
        self::FINAL_RESPONSE_TOKEN_STREAMED,
        self::FINAL_RESPONSE_STREAM_COMPLETED,
        self::RUN_COMPLETED,
        self::RUN_FAILED,
        self::RUN_CANCELLED,
        self::RUN_EXPIRED,
    ];

    public function __construct(
        protected AgentRunRepository $runs,
        protected AgentRunStepRepository $steps
    ) {}

    public function names(): array
    {
        return self::NAMES;
    }

    public function emit(
        string $name,
        AIAgentRun|int|string|null $run = null,
        AIAgentRunStep|int|string|null $step = null,
        array $payload = [],
        array $metadata = [],
        ?callable $sink = null
    ): array {
        $this->assertKnownEvent($name);

        $runModel = $this->resolveRun($run);
        $stepModel = $this->resolveStep($step);
        $event = $this->makeEvent($name, $runModel, $stepModel, $payload, $metadata);

        if ($runModel instanceof AIAgentRun) {
            $this->appendEvent($runModel, $event);
        }

        if ($stepModel instanceof AIAgentRunStep) {
            $this->appendEvent($stepModel, $event);
        }

        event(new AgentRunStreamed($event));

        if ($sink !== null) {
            $sink($event);
        }

        return $event;
    }

    public function fallbackEvents(AIAgentRun|int|string $run): array
    {
        $run = $this->resolveRun($run);
        if (!$run instanceof AIAgentRun) {
            return [];
        }
        $run->refresh();
        $run->unsetRelation('steps');

        $events = (array) (($run->metadata ?? [])['events'] ?? []);
        foreach ($run->steps as $step) {
            $events = array_merge($events, (array) (($step->metadata ?? [])['events'] ?? []));
        }

        $events = array_values(array_reduce($events, static function (array $carry, array $event): array {
            $carry[(string) ($event['id'] ?? spl_object_id((object) $event))] = $event;

            return $carry;
        }, []));

        usort($events, static fn (array $a, array $b): int => strcmp((string) ($a['emitted_at'] ?? ''), (string) ($b['emitted_at'] ?? '')));

        return $events;
    }

    protected function makeEvent(
        string $name,
        ?AIAgentRun $run,
        ?AIAgentRunStep $step,
        array $payload,
        array $metadata
    ): array {
        $traceId = $metadata['trace_id'] ?? $step?->metadata['trace_id'] ?? $run?->metadata['trace_id'] ?? null;

        return array_filter([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'run_id' => $run?->uuid,
            'run_db_id' => $run?->id,
            'step_id' => $step?->uuid,
            'step_db_id' => $step?->id,
            'trace_id' => $traceId,
            'payload' => $payload,
            'metadata' => $metadata,
            'emitted_at' => now()->toISOString(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function appendEvent(AIAgentRun|AIAgentRunStep $model, array $event): void
    {
        $metadata = $model->metadata ?? [];
        $events = (array) ($metadata['events'] ?? []);
        $events[] = $event;

        $limit = max(1, (int) config('ai-agent.event_stream.persisted_events_limit', 200));
        $metadata['events'] = array_slice($events, -$limit);

        $model->update(['metadata' => $metadata]);
        $model->refresh();
    }

    protected function resolveRun(AIAgentRun|int|string|null $run): ?AIAgentRun
    {
        if ($run instanceof AIAgentRun || $run === null || $run === '') {
            return $run ?: null;
        }

        return $this->runs->find($run);
    }

    protected function resolveStep(AIAgentRunStep|int|string|null $step): ?AIAgentRunStep
    {
        if ($step instanceof AIAgentRunStep || $step === null || $step === '') {
            return $step ?: null;
        }

        return $this->steps->find($step);
    }

    protected function assertKnownEvent(string $name): void
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException("Unknown agent stream event [{$name}].");
        }
    }
}
