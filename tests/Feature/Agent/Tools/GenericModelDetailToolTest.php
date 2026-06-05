<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Agent\Tools;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\Tools\AiResource;
use LaravelAIEngine\Services\Agent\Tools\GenericModelDetailTool;
use LaravelAIEngine\Tests\TestCase;

/**
 * The detail tool returns ONE record with its related rows (the gap data_query / find_
 * tools leave open). Exercised against AIAgentRun -> steps.
 */
class GenericModelDetailToolTest extends TestCase
{
    private function runWithStep(string $session = 'detail'): AIAgentRun
    {
        $run = app(AgentRunRepository::class)->create(['session_id' => $session, 'status' => 'running']);
        app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'plan',
            'type' => 'routing',
            'status' => 'completed',
            'action' => 'search_rag',
        ]);

        return $run->fresh();
    }

    private function tool(): GenericModelDetailTool
    {
        return new GenericModelDetailTool(
            'show_run',
            AIAgentRun::class,
            ['session_id'],
            ['id', 'session_id', 'status'],
            ['steps' => ['step_key', 'status']]
        );
    }

    public function test_returns_the_record_with_its_relation_rows(): void
    {
        $run = $this->runWithStep();

        $result = $this->tool()->execute(['id' => $run->id], new UnifiedActionContext('s'));

        $this->assertTrue($result->success);
        $this->assertTrue((bool) ($result->data['found'] ?? false));
        $this->assertSame($run->id, $result->data['record']['id']);
        $this->assertSame('detail', $result->data['record']['session_id']);

        $steps = $result->data['relations']['steps'] ?? [];
        $this->assertCount(1, $steps);
        $this->assertSame('plan', $steps[0]['step_key']);
        $this->assertSame('completed', $steps[0]['status']);
        // Only the requested relation columns are returned.
        $this->assertArrayNotHasKey('type', $steps[0]);
    }

    public function test_finds_by_a_search_column(): void
    {
        $run = $this->runWithStep('lookup-me');

        $result = $this->tool()->execute(['session_id' => 'lookup-me'], new UnifiedActionContext('s'));

        $this->assertTrue($result->success);
        $this->assertSame($run->id, $result->data['record']['id']);
    }

    public function test_falls_back_to_the_latest_when_no_identifier_is_given(): void
    {
        $this->runWithStep('older');
        $newest = $this->runWithStep('newer');

        $result = $this->tool()->execute([], new UnifiedActionContext('s'));

        $this->assertSame($newest->id, $result->data['record']['id']);
    }

    public function test_reports_not_found(): void
    {
        $result = $this->tool()->execute(['id' => 999999], new UnifiedActionContext('s'));

        $this->assertFalse($result->success);
        $this->assertFalse((bool) ($result->data['found'] ?? true));
    }

    public function test_ai_resource_with_registers_a_show_tool(): void
    {
        $tools = AiResource::for(AIAgentRun::class)
            ->name('run')
            ->search(['session_id'])
            ->with(['steps' => ['step_key', 'status']])
            ->tools();

        $this->assertArrayHasKey('find_run', $tools);
        $this->assertArrayHasKey('show_run', $tools);
        $this->assertInstanceOf(GenericModelDetailTool::class, $tools['show_run']);
    }
}
