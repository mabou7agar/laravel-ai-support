<?php

namespace LaravelAIEngine\Tests\Feature\Api;

use Illuminate\Support\Facades\Config;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Tests\TestCase;

class GoalAgentApiTest extends TestCase
{
    public function test_rag_chat_endpoint_executes_goal_agent_sub_agents_end_to_end(): void
    {
        Config::set('ai-agent.sub_agents', [
            'research' => [
                'name' => 'Research',
                'handler' => fn (
                    SubAgentTask $task,
                    UnifiedActionContext $context,
                    array $previousResults,
                    array $options
                ) => SubAgentResult::success(
                    taskId: $task->id,
                    agentId: $task->agentId,
                    message: 'Research complete',
                    data: ['facts' => ['market is ready']]
                ),
            ],
            'writer' => [
                'name' => 'Writer',
                'handler' => fn (
                    SubAgentTask $task,
                    UnifiedActionContext $context,
                    array $previousResults,
                    array $options
                ) => SubAgentResult::success(
                    taskId: $task->id,
                    agentId: $task->agentId,
                    message: 'Writer complete',
                    data: [
                        'previous_task_ids' => array_keys($previousResults),
                        'draft' => 'Launch plan drafted',
                    ]
                ),
            ],
        ]);

        $response = $this->postJson('/api/v1/agent/chat', [
            'message' => 'Create a launch plan',
            'session_id' => 'goal-api-e2e',
            'memory' => false,
            'actions' => false,
            'agent_goal' => true,
            'target' => 'Create a launch plan',
            'rag_collections' => ['App\\Models\\Product'],
            'sub_agents' => [
                [
                    'id' => 'research_task',
                    'agent_id' => 'research',
                    'objective' => 'Collect facts',
                ],
                [
                    'id' => 'write_task',
                    'agent_id' => 'writer',
                    'objective' => 'Write final plan',
                    'depends_on' => ['research_task'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', 'goal-api-e2e')
            ->assertJsonPath('data.focused_entity.target', 'Create a launch plan')
            ->assertJsonPath('data.focused_entity.tasks.0.agent_id', 'research')
            ->assertJsonPath('data.focused_entity.tasks.1.agent_id', 'writer');

        $this->assertStringContainsString('Target completed.', $response->json('data.response'));
        $this->assertStringContainsString('research: done - Research complete', $response->json('data.response'));
        $this->assertStringContainsString('writer: done - Writer complete', $response->json('data.response'));
    }

    public function test_rag_chat_alias_is_removed(): void
    {
        $this->postJson('/api/v1/rag/chat', [
            'message' => 'Create a plan',
            'session_id' => 'removed-rag-chat-alias',
        ])->assertNotFound();
    }
}
