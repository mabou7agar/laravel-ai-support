<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Tests\TestCase;

class AgentRunStepSequenceAtomicityTest extends TestCase
{
    public function test_sequential_step_creation_yields_unique_increasing_sequences(): void
    {
        $runs = app(AgentRunRepository::class);
        $steps = app(AgentRunStepRepository::class);

        $run = $runs->create([
            'session_id' => 'session-seq',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_RUNNING,
            'schema_version' => 1,
            'input' => ['message' => 'hello'],
        ]);

        $sequences = [];
        for ($i = 0; $i < 6; $i++) {
            $step = $steps->create($run, [
                'step_key' => "step-{$i}",
                'type' => 'routing',
                'status' => AIAgentRun::STATUS_RUNNING,
            ]);
            $sequences[] = (int) $step->sequence;
        }

        $this->assertSame([1, 2, 3, 4, 5, 6], $sequences);
        $this->assertSame($sequences, array_values(array_unique($sequences)));
    }

    public function test_create_retries_when_sequence_already_taken(): void
    {
        $runs = app(AgentRunRepository::class);
        $steps = app(AgentRunStepRepository::class);

        $run = $runs->create([
            'session_id' => 'session-seq-collision',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_RUNNING,
            'schema_version' => 1,
            'input' => ['message' => 'hello'],
        ]);

        // Pre-seed a step occupying sequence 1 with an explicit sequence, so a
        // naive nextSequence() implementation that returns 1 would collide.
        $steps->create($run, [
            'step_key' => 'explicit-1',
            'type' => 'routing',
            'status' => AIAgentRun::STATUS_RUNNING,
            'sequence' => 1,
        ]);

        // Auto-sequence creation must skip the taken slot and land on 2.
        $next = $steps->create($run, [
            'step_key' => 'auto',
            'type' => 'routing',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        $this->assertSame(2, (int) $next->sequence);

        $all = $steps->forRun($run)->pluck('sequence')->map(fn ($s) => (int) $s)->all();
        $this->assertSame($all, array_values(array_unique($all)), 'Sequences must be unique.');
        $this->assertSame($all, collect($all)->sort()->values()->all(), 'Sequences must be increasing.');
    }
}
