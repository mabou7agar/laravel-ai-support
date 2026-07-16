<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeStateStore;
use LaravelAIEngine\Tests\UnitTestCase;

class AiNativeStateHistoryCapTest extends UnitTestCase
{
    public function test_default_off_loads_persisted_state_untouched(): void
    {
        $context = $this->contextWithState([
            'tool_results' => array_fill(0, 20, $this->bigResult()),
        ]);

        $state = (new AiNativeStateStore())->state($context);

        $this->assertCount(20, $state['tool_results']);
        $this->assertArrayNotHasKey('_state_truncated', $state['tool_results'][0]['result']);
    }

    public function test_loaded_oversized_entries_are_pruned_and_history_trimmed(): void
    {
        config()->set('ai-agent.ai_native.state_result_max_bytes', 2048);
        config()->set('ai-agent.ai_native.state_history_max_results', 6);

        $context = $this->contextWithState([
            'tool_results' => array_fill(0, 20, $this->bigResult()),
            'recent_outcomes' => [
                ['tool' => 'stage_preview', 'summary' => str_repeat('y', 9000)],
                ['tool' => 'lookup', 'summary' => 'small'],
            ],
        ]);

        $state = (new AiNativeStateStore())->state($context);

        // History trimmed to the NEWEST N, with the drop recorded.
        $this->assertCount(6, $state['tool_results']);
        $this->assertSame(14, $state['compacted_tool_results']);

        // Every surviving oversized entry pruned; ids survive the prune.
        $encoded = json_encode($state['tool_results'][0], JSON_UNESCAPED_UNICODE);
        $this->assertLessThan(6000, strlen((string) $encoded));
        $this->assertStringContainsString('preview-abc123', (string) $encoded);

        // recent_outcomes entries capped too; small ones untouched.
        $this->assertTrue($state['recent_outcomes'][0]['_state_truncated']);
        $this->assertSame('small', $state['recent_outcomes'][1]['summary']);
    }

    public function test_task_frame_recent_outcomes_are_trimmed_and_pruned_but_payload_survives(): void
    {
        config()->set('ai-agent.ai_native.state_result_max_bytes', 2048);
        config()->set('ai-agent.ai_native.state_history_max_results', 4);

        $context = $this->contextWithState([
            'task_frame' => [
                'active_objective' => 'tools',
                'current_payload' => ['html' => str_repeat('K', 9000)],
                'recent_outcomes' => array_fill(0, 12, [
                    'tool' => 'find_tools',
                    'outcome' => 'found',
                    'display' => ['tools' => array_fill(0, 8, ['schema' => str_repeat('s', 1200)])],
                ]),
            ],
        ]);

        $state = (new AiNativeStateStore())->state($context);

        $outcomes = $state['task_frame']['recent_outcomes'];
        $this->assertCount(4, $outcomes, 'trimmed to the newest N');
        $this->assertTrue($outcomes[0]['_state_truncated'], 'oversized outcomes pruned');
        // FUNCTIONAL working-draft data must never be touched.
        $this->assertSame(9000, strlen($state['task_frame']['current_payload']['html']));
    }

    /**
     * @param array<string, mixed> $aiNative
     */
    private function contextWithState(array $aiNative): UnifiedActionContext
    {
        $context = new UnifiedActionContext('state-cap');
        $context->metadata['ai_native'] = $aiNative;

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function bigResult(): array
    {
        return [
            'tool' => 'stage_preview',
            'params' => [],
            'result' => [
                'success' => true,
                'data' => [
                    'preview_id' => 'preview-abc123',
                    'operations' => array_fill(0, 40, ['type' => 'add_section', 'payload' => ['props' => str_repeat('x', 400)]]),
                ],
            ],
        ];
    }
}
