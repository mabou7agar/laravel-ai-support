<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AgentTaskStateService;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeStateStore;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolExecutor;
use LaravelAIEngine\Services\Agent\AiNative\ToolOutcomeNormalizer;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class AiNativePlannerResultProjectionTest extends UnitTestCase
{
    public function test_tool_can_project_a_compact_result_for_the_planner_without_changing_the_host_result(): void
    {
        $tools = new ToolRegistry();
        $tools->register('stage_preview', new class extends AgentTool {
            public function getName(): string
            {
                return 'stage_preview';
            }

            public function getDescription(): string
            {
                return 'Stage a large preview.';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Preview ready.');
            }

            public function resultForPlanner(ActionResult $result): array
            {
                return [
                    'success' => $result->success,
                    'message' => $result->message,
                    'data' => [
                        'preview_id' => $result->data['preview_id'],
                        'operation_count' => count($result->data['operations']),
                        'can_apply' => true,
                    ],
                ];
            }
        });

        $executor = new AiNativeToolExecutor(
            new AgentTaskStateService(new ToolOutcomeNormalizer()),
            new AiNativeStateStore(),
            tools: $tools,
        );
        $hostResult = ActionResult::success('Preview ready.', [
            'preview_id' => 'preview-123',
            'operations' => array_fill(0, 30, ['payload' => ['html' => str_repeat('x', 500)]]),
        ]);
        $state = [];

        $executor->recordResult($state, 'stage_preview', [], $hostResult);

        $plannerResult = $state['tool_results'][0]['result'];
        $this->assertSame('preview-123', $plannerResult['data']['preview_id']);
        $this->assertSame(30, $plannerResult['data']['operation_count']);
        $this->assertArrayNotHasKey('operations', $plannerResult['data']);
        $this->assertCount(30, $hostResult->data['operations']);
    }

    public function test_unregistered_tools_keep_the_legacy_complete_result(): void
    {
        $executor = new AiNativeToolExecutor(
            new AgentTaskStateService(new ToolOutcomeNormalizer()),
            new AiNativeStateStore(),
            tools: new ToolRegistry(),
        );
        $result = ActionResult::success('Loaded.', ['records' => [['id' => 1]]]);
        $state = [];

        $executor->recordResult($state, 'legacy_lookup', [], $result);

        $this->assertSame($result->toArray(), $state['tool_results'][0]['result']);
    }
}
