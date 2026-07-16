<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Services\Agent\AiNative\AgentTaskStateService;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeStateStore;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolExecutor;
use LaravelAIEngine\Services\Agent\AiNative\ToolOutcomeNormalizer;
use LaravelAIEngine\Tests\UnitTestCase;

class AiNativeStateResultCapTest extends UnitTestCase
{
    public function test_default_off_records_the_result_untouched(): void
    {
        $state = [];
        $this->executor()->recordResult($state, 'stage_preview', [], $this->bigResult());

        $recorded = $state['tool_results'][0]['result'];
        $this->assertArrayNotHasKey('_state_truncated', $recorded);
        $this->assertCount(60, $recorded['data']['operations']);
    }

    public function test_oversized_result_is_capped_but_ids_survive(): void
    {
        config()->set('ai-agent.ai_native.state_result_max_bytes', 2048);

        $state = [];
        $this->executor()->recordResult($state, 'stage_preview', [], $this->bigResult());

        $recorded = $state['tool_results'][0]['result'];
        $encoded = json_encode($recorded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertTrue($recorded['_state_truncated']);
        $this->assertGreaterThan(strlen((string) $encoded), $recorded['_original_bytes']);
        // The planner must still see the outcome and its reference ids.
        $this->assertStringContainsString('preview-abc123', (string) $encoded);
        // Long list elided to 10 entries + a marker.
        $this->assertLessThanOrEqual(11, count($recorded['data']['operations']));
    }

    public function test_small_results_pass_through_even_when_cap_is_on(): void
    {
        config()->set('ai-agent.ai_native.state_result_max_bytes', 2048);

        $state = [];
        $small = ActionResult::success('done', ['id' => 42]);
        $this->executor()->recordResult($state, 'lookup', [], $small);

        $recorded = $state['tool_results'][0]['result'];
        $this->assertArrayNotHasKey('_state_truncated', $recorded);
        $this->assertSame(42, $recorded['data']['id']);
    }

    private function executor(): AiNativeToolExecutor
    {
        return new AiNativeToolExecutor(
            new AgentTaskStateService(new ToolOutcomeNormalizer()),
            new AiNativeStateStore(),
        );
    }

    private function bigResult(): ActionResult
    {
        return ActionResult::success('staged', [
            'preview_id' => 'preview-abc123',
            'operations' => array_fill(0, 60, [
                'type' => 'add_section',
                'payload' => ['props' => str_repeat('x', 400)],
            ]),
        ]);
    }
}
