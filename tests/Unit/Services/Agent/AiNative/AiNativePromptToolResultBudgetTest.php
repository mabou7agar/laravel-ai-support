<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AgentTaskStateService;
use LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeStateStore;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolExecutor;
use LaravelAIEngine\Services\Agent\AiNative\ToolOutcomeNormalizer;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Every planner step re-sends the runtime state, so one large tool result must be bounded in
 * the prompt and must not be rendered twice (tool_results + snapshot recent_outcomes).
 */
class AiNativePromptToolResultBudgetTest extends UnitTestCase
{
    private function promptFor(array $state): string
    {
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        return (new AiNativePromptBuilder(new ToolRegistry(), $skills))
            ->build('what is on that invoice?', new UnifiedActionContext('budget'), $state);
    }

    private function stateWithLargeInvoice(): array
    {
        $lines = [];
        for ($i = 0; $i < 60; $i++) {
            $lines[] = ['product_name' => 'LINEITEM_MARKER ' . $i . ' ' . str_repeat('description ', 20), 'quantity' => $i, 'price' => 10.5];
        }
        $result = ActionResult::success('Invoice found.', [
            'invoice' => ['id' => 1001, 'number' => 'INV-1001', 'customer_name' => 'Acme', 'notes' => str_repeat('NOTE_MARKER ', 400), 'items' => $lines],
        ]);

        $state = [];
        $executor = new AiNativeToolExecutor(new AgentTaskStateService(new ToolOutcomeNormalizer()), new AiNativeStateStore());
        $executor->recordResult($state, 'find_invoice', ['query' => 'INV-1001'], $result);

        return $state;
    }

    public function test_large_tool_results_are_capped_and_rendered_once(): void
    {
        config()->set('ai-agent.ai_native.state_result_max_bytes', 0);

        $prompt = $this->promptFor($this->stateWithLargeInvoice());

        $runtimeState = substr($prompt, strpos($prompt, 'Current runtime state JSON:'));
        $this->assertLessThan(5000, strlen($runtimeState), 'The tool result is bounded by prompt_tool_result_max_bytes.');
        $this->assertStringContainsString('INV-1001', $runtimeState, 'Identifying scalars survive the cap.');
        $this->assertStringContainsString('_prompt_truncated', $runtimeState);

        $snapshot = substr($prompt, strpos($prompt, 'Context snapshot JSON:'), strpos($prompt, 'Current runtime state JSON:') - strpos($prompt, 'Context snapshot JSON:'));
        $this->assertStringNotContainsString('NOTE_MARKER', $snapshot, 'The snapshot does not repeat the payload tool_results already carries.');
        $this->assertStringContainsString('"details_in":"tool_results"', $snapshot);
        $this->assertStringContainsString('INV-1001', $snapshot, 'The outcome summary (label) stays.');
    }

    public function test_caps_can_be_disabled(): void
    {
        config()->set('ai-agent.ai_native.state_result_max_bytes', 0);
        config()->set('ai-agent.ai_native.prompt_tool_result_max_bytes', 0);
        config()->set('ai-agent.ai_native.prompt_dedupe_tool_results', false);

        $prompt = $this->promptFor($this->stateWithLargeInvoice());

        $this->assertSame(60, substr_count(substr($prompt, strpos($prompt, 'Current runtime state JSON:')), 'LINEITEM_MARKER'));
        $this->assertStringContainsString('NOTE_MARKER', substr($prompt, strpos($prompt, 'Context snapshot JSON:'), strpos($prompt, 'Current runtime state JSON:') - strpos($prompt, 'Context snapshot JSON:')));
    }

    public function test_in_loop_compaction_is_on_by_default(): void
    {
        $this->assertTrue((bool) config('ai-agent.ai_native.compaction.enabled'));
        $this->assertFalse((bool) config('ai-agent.ai_native.compaction.compact_conversation'));
    }
}
