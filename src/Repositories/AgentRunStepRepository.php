<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
        $attributes['status'] ??= AIAgentRun::STATUS_PENDING;
        $attributes['type'] ??= 'routing';

        // If a sequence is supplied explicitly we honour it as-is. Otherwise we
        // assign one atomically so concurrent callers (job path, recovery
        // replays, runtime-control cancellation steps) never collide on the
        // unique(run_id, sequence) constraint.
        if (array_key_exists('sequence', $attributes) && $attributes['sequence'] !== null) {
            $attributes = $this->schema()->normalizeStepAttributes($attributes);

            return AIAgentRunStep::create($attributes);
        }

        return $this->createWithNextSequence($runId, $attributes);
    }

    /**
     * Assign the next sequence and persist the step atomically, retrying on a
     * unique-constraint violation in case a concurrent writer raced ahead.
     */
    protected function createWithNextSequence(int $runId, array $attributes): AIAgentRunStep
    {
        $maxAttempts = 5;

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($runId, $attributes): AIAgentRunStep {
                    // Lock the existing steps for this run so concurrent
                    // transactions serialize their MAX(sequence) read.
                    AIAgentRunStep::query()
                        ->where('run_id', $runId)
                        ->lockForUpdate()
                        ->get(['id']);

                    $attributes['sequence'] = $this->nextSequence($runId);
                    $attributes = $this->schema()->normalizeStepAttributes($attributes);

                    return AIAgentRunStep::create($attributes);
                });
            } catch (QueryException $e) {
                if ($attempt >= $maxAttempts || !$this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }

                // Drop the stale uuid/sequence so the retry recomputes them.
                unset($attributes['sequence']);
                $attributes['uuid'] = (string) Str::uuid();
            }
        }
    }

    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // 23000/23505 => integrity constraint violation; 1555/2067 => SQLite
        // unique-index codes; 1062 => MySQL duplicate entry.
        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, [1062, 1555, 2067, 19], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
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
