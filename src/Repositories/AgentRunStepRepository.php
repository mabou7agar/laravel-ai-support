<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Services\Agent\AgentRunPayloadSchemaVersioner;

class AgentRunStepRepository
{
    public function __construct(
        protected ?AgentRunPayloadSchemaVersioner $schemaVersioner = null
    ) {
    }

    public function create(AIAgentRun|int $run, array $attributes): AIAgentRunStep
    {
        $runId = $run instanceof AIAgentRun ? (int) $run->id : $run;

        $attributes['uuid'] ??= (string) Str::uuid();
        $attributes['run_id'] = $runId;
        $attributes['sequence'] ??= $this->nextSequence($runId);
        $attributes['status'] ??= AIAgentRun::STATUS_PENDING;
        $attributes['type'] ??= 'routing';
        $attributes = $this->schema()->normalizeStepAttributes($attributes);

        return AIAgentRunStep::create($attributes);
    }

    public function find(int|string|null $id): ?AIAgentRunStep
    {
        if ($id === null || $id === '') {
            return null;
        }

        return AIAgentRunStep::query()
            ->where('id', $id)
            ->orWhere('uuid', (string) $id)
            ->first();
    }

    public function findOrFail(int|string $id): AIAgentRunStep
    {
        $step = $this->find($id);
        if ($step === null) {
            throw new \InvalidArgumentException("Agent run step [{$id}] was not found.");
        }

        return $step;
    }

    public function forRun(AIAgentRun|int $run): Collection
    {
        $runId = $run instanceof AIAgentRun ? (int) $run->id : $run;

        return AIAgentRunStep::query()
            ->where('run_id', $runId)
            ->orderBy('sequence')
            ->get();
    }

    public function update(AIAgentRunStep $step, array $attributes): AIAgentRunStep
    {
        $attributes = $this->schema()->normalizeStepAttributes(array_merge([
            'metadata' => $step->metadata ?? [],
        ], $attributes));

        $step->update($attributes);

        return $step->refresh();
    }

    public function transition(AIAgentRunStep $step, string $status, array $attributes = []): AIAgentRunStep
    {
        if (!in_array($status, AIAgentRun::STATUSES, true)) {
            throw new \InvalidArgumentException("Unsupported agent run step status [{$status}].");
        }

        $attributes['status'] = $status;

        return $this->update($step, $attributes);
    }

    public function nextSequence(AIAgentRun|int $run): int
    {
        $runId = $run instanceof AIAgentRun ? (int) $run->id : $run;
        $max = AIAgentRunStep::query()
            ->where('run_id', $runId)
            ->max('sequence');

        return ((int) $max) + 1;
    }

    protected function schema(): AgentRunPayloadSchemaVersioner
    {
        return $this->schemaVersioner ??= app()->bound(AgentRunPayloadSchemaVersioner::class)
            ? app(AgentRunPayloadSchemaVersioner::class)
            : new AgentRunPayloadSchemaVersioner();
    }
}
