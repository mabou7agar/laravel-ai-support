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

    /** Build a prompt with one param-heavy tool present, under the given options. */
    private function buildWithTool(array $options): string
    {
        $description = 'Paint a wall a color (e.g. blue) while preserving the existing trim. '
            . str_repeat('Detailed operational guidance that is not needed to call this tool. ', 8)
            . 'LONG_DESCRIPTION_TAIL';
        $tool = Mockery::mock(\LaravelAIEngine\Services\Agent\Tools\AgentTool::class);
        $tool->shouldReceive('getName')->andReturn('paint_wall');
        $tool->shouldReceive('getDescription')->andReturn($description);
        $tool->shouldReceive('getParameters')->andReturn(['color' => ['type' => 'string', 'description' => 'UNIQUE_PARAM_MARKER']]);
        $tool->shouldReceive('toArray')->andReturn(['name' => 'paint_wall', 'description' => $description, 'parameters' => ['color' => ['type' => 'string', 'description' => 'UNIQUE_PARAM_MARKER']]]);

        $tools = Mockery::mock(ToolRegistry::class);
        $tools->shouldReceive('all')->andReturn(['paint_wall' => $tool]);
        $tools->shouldReceive('has')->with('find_tools')->andReturn(false);
        $tools->shouldReceive('has')->andReturn(false);

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);
        $snapshots = Mockery::mock(AgentContextSnapshotBuilder::class);
        $snapshots->shouldReceive('build')->andReturn([]);

        return (new AiNativePromptBuilder($tools, $skills, $snapshots))
            ->build('paint the wall', new UnifiedActionContext(sessionId: 't'), [], $options);
    }

    public function test_full_disclosure_includes_tool_parameter_schemas(): void
    {
        $prompt = $this->buildWithTool([]);

        $this->assertStringContainsString('paint_wall', $prompt);
        $this->assertStringContainsString('UNIQUE_PARAM_MARKER', $prompt); // params present
    }

    public function test_per_request_progressive_disclosure_drops_parameter_schemas(): void
    {
        $prompt = $this->buildWithTool(['tool_selection' => ['disclosure' => 'progressive']]);

        // Name + description stay; the param schema is deferred to find_tools.
        $this->assertStringContainsString('paint_wall', $prompt);
        $this->assertStringContainsString('Paint a wall a color', $prompt);
        $this->assertStringNotContainsString('UNIQUE_PARAM_MARKER', $prompt);
    }

    public function test_native_transport_can_omit_duplicate_tool_documents_from_text_prompt(): void
    {
        $prompt = $this->buildWithTool([
            '_planner_transport' => 'native_tools',
            'tool_selection' => ['embed_native_documents' => false],
        ]);

        $this->assertStringContainsString(
            'Available tools are provided through native function schemas.',
            $prompt
        );
        $this->assertStringNotContainsString('paint_wall', $prompt);
        $this->assertStringNotContainsString('UNIQUE_PARAM_MARKER', $prompt);
    }

    public function test_native_transport_keeps_duplicate_tool_documents_by_default_for_compatibility(): void
    {
        $prompt = $this->buildWithTool(['_planner_transport' => 'native_tools']);

        $this->assertStringContainsString('paint_wall', $prompt);
        $this->assertStringContainsString('UNIQUE_PARAM_MARKER', $prompt);
    }

    public function test_full_schema_tool_description_can_be_capped_without_trimming_parameters(): void
    {
        $prompt = $this->buildWithTool([
            'tool_selection' => ['full_schema_description_max_chars' => 80],
        ]);

        $this->assertStringContainsString('e.g. blue) while preserving', $prompt);
        $this->assertStringNotContainsString('LONG_DESCRIPTION_TAIL', $prompt);
        $this->assertStringContainsString('UNIQUE_PARAM_MARKER', $prompt);
    }

    public function test_per_request_exposed_tools_bounds_the_prompt_roster(): void
    {
        $paint = Mockery::mock(\LaravelAIEngine\Services\Agent\Tools\AgentTool::class);
        $paint->shouldReceive('getName')->andReturn('paint_wall');
        $paint->shouldReceive('getDescription')->andReturn('Paint a wall.');
        $paint->shouldReceive('toArray')->andReturn([
            'name' => 'paint_wall',
            'description' => 'Paint a wall.',
            'parameters' => [],
        ]);

        $invoice = Mockery::mock(\LaravelAIEngine\Services\Agent\Tools\AgentTool::class);
        $invoice->shouldReceive('getName')->andReturn('create_invoice');
        $invoice->shouldReceive('getDescription')->andReturn('Create an invoice.');
        $invoice->shouldReceive('toArray')->andReturn([
            'name' => 'create_invoice',
            'description' => 'Create an invoice.',
            'parameters' => [],
        ]);

        $tools = Mockery::mock(ToolRegistry::class);
        $tools->shouldReceive('all')->andReturn([
            'paint_wall' => $paint,
            'create_invoice' => $invoice,
        ]);
        $tools->shouldReceive('has')->andReturn(false);

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);
        $snapshots = Mockery::mock(AgentContextSnapshotBuilder::class);
        $snapshots->shouldReceive('build')->andReturn([]);

        $prompt = (new AiNativePromptBuilder($tools, $skills, $snapshots))->build(
            'paint the wall',
            new UnifiedActionContext(sessionId: 't'),
            [],
            ['tool_selection' => ['exposed_tools' => ['paint_wall']]],
        );

        $this->assertStringContainsString('paint_wall', $prompt);
        $this->assertStringNotContainsString('create_invoice', $prompt);
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
