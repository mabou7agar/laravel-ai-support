<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AgentContextSnapshotBuilder;
use LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Guards the human-approval prompt gate: hosts that stage work for an
 * explicit user approval step (previews with an Apply button) pass
 * require_human_approvals — the planner prompt must then tell the model a
 * staged preview IS the outcome, instead of instructing it to auto-call the
 * skill final tool (which made models fire apply/execute tools the user
 * never asked for).
 */
class AiNativePromptBuilderApprovalTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function build(array $options): string
    {
        $tools = Mockery::mock(ToolRegistry::class);
        $tools->shouldReceive('all')->andReturn([]);
        $tools->shouldReceive('has')->andReturn(false);

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $snapshots = Mockery::mock(AgentContextSnapshotBuilder::class);
        $snapshots->shouldReceive('build')->andReturn([]);

        $builder = new AiNativePromptBuilder($tools, $skills, $snapshots);

        return $builder->build('remove the stats section', new UnifiedActionContext(sessionId: 't'), [], $options);
    }

    public function test_default_prompt_keeps_the_final_tool_auto_call_instruction(): void
    {
        $prompt = $this->build([]);

        $this->assertStringContainsString('call that final tool with the complete current_payload', $prompt);
        $this->assertStringNotContainsString('Human approval mode', $prompt);
    }

    public function test_require_human_approvals_replaces_the_auto_call_instruction(): void
    {
        $prompt = $this->build(['require_human_approvals' => true]);

        $this->assertStringContainsString('Human approval mode', $prompt);
        $this->assertStringContainsString('Never call an apply/execute/publish/final tool in the same turn', $prompt);
        $this->assertStringNotContainsString('call that final tool with the complete current_payload instead of returning a final answer', $prompt);
    }
}
