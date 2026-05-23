<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentConversationService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class SubAgentConversationServiceTest extends UnitTestCase
{
    public function test_two_sub_agents_exchange_messages_for_multiple_rounds_and_update_context(): void
    {
        config([
            'ai-agent.context_compaction.enabled' => true,
            'ai-agent.context_compaction.max_messages' => 4,
            'ai-agent.context_compaction.keep_recent_messages' => 2,
            'ai-agent.sub_agent_conversations.default_rounds' => 2,
            'ai-agent.sub_agent_conversations.max_rounds' => 4,
        ]);

        $seen = [];
        $agents = [
            'planner' => [
                'name' => 'Planner',
                'handler' => function (SubAgentTask $task) use (&$seen): SubAgentResult {
                    $seen[] = [
                        'agent_id' => $task->agentId,
                        'target' => $task->input['target'] ?? null,
                        'round' => $task->input['round'] ?? null,
                        'turn' => $task->input['turn'] ?? null,
                        'participant_index' => $task->input['participant_index'] ?? null,
                        'last_message' => $task->input['last_message'] ?? null,
                        'transcript_count' => count($task->input['transcript'] ?? []),
                        'conversation_id' => $task->input['conversation_id'] ?? null,
                    ];
                    $lastMessage = (string) ($task->input['last_message'] ?? 'nothing yet');

                    return SubAgentResult::success(
                        $task->id,
                        $task->agentId,
                        'Planner round ' . $task->input['round'] . ' saw: ' . $lastMessage
                    );
                },
            ],
            'reviewer' => [
                'name' => 'Reviewer',
                'handler' => function (SubAgentTask $task) use (&$seen): SubAgentResult {
                    $seen[] = [
                        'agent_id' => $task->agentId,
                        'target' => $task->input['target'] ?? null,
                        'round' => $task->input['round'] ?? null,
                        'turn' => $task->input['turn'] ?? null,
                        'participant_index' => $task->input['participant_index'] ?? null,
                        'last_message' => $task->input['last_message'] ?? null,
                        'transcript_count' => count($task->input['transcript'] ?? []),
                        'conversation_id' => $task->input['conversation_id'] ?? null,
                    ];
                    $lastMessage = (string) ($task->input['last_message'] ?? 'nothing yet');

                    return SubAgentResult::success(
                        $task->id,
                        $task->agentId,
                        'Reviewer round ' . $task->input['round'] . ' replied to: ' . $lastMessage
                    );
                },
            ],
        ];

        $service = new SubAgentConversationService(
            new SubAgentRegistry($this->app, $agents),
            new ConversationContextCompactor()
        );

        $context = new UnifiedActionContext('sub-agent-long-chat', 'user-1');

        $result = $service->run(
            'Design a safe invoice creation plan',
            $context,
            ['planner', 'reviewer'],
            ['rounds' => 2]
        );

        $this->assertTrue($result->success);
        $this->assertCount(4, $result->transcript);
        $this->assertSame(['planner', 'reviewer', 'planner', 'reviewer'], array_column($result->transcript, 'agent_id'));
        $this->assertStringContainsString('Planner round 1', $result->transcript[1]['message']);
        $this->assertStringContainsString('Reviewer round 1', $result->transcript[2]['message']);
        $this->assertSame([0, 1, 2, 3], array_column($seen, 'transcript_count'));
        $this->assertSame('Design a safe invoice creation plan', $seen[0]['last_message']);
        $this->assertSame($result->transcript[0]['message'], $seen[1]['last_message']);
        $this->assertCount(1, array_unique(array_column($seen, 'conversation_id')));
        $this->assertSame('completed', $result->stoppedReason);
        $this->assertNotEmpty($context->conversationHistory);
        $this->assertNotEmpty($context->metadata['conversation_context_metrics'] ?? null);
        $this->assertSame([
            'conversation_id' => $seen[0]['conversation_id'],
            'target' => 'Design a safe invoice creation plan',
            'participants' => ['planner', 'reviewer'],
            'turn_count' => 4,
            'last_round' => 2,
        ], array_intersect_key(
            $context->metadata['last_sub_agent_conversation'],
            array_flip(['conversation_id', 'target', 'participants', 'turn_count', 'last_round'])
        ));
    }

    public function test_rounds_are_clamped_to_configured_maximum(): void
    {
        config(['ai-agent.sub_agent_conversations.max_rounds' => 2]);

        $service = $this->service([
            'planner' => fn (SubAgentTask $task): SubAgentResult => SubAgentResult::success($task->id, $task->agentId, 'planner'),
            'reviewer' => fn (SubAgentTask $task): SubAgentResult => SubAgentResult::success($task->id, $task->agentId, 'reviewer'),
        ]);

        $result = $service->run('Clamp this long chat', new UnifiedActionContext('round-clamp'), ['planner', 'reviewer'], [
            'rounds' => 99,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->roundsCompleted);
        $this->assertCount(4, $result->transcript);
    }

    public function test_missing_handler_stops_with_partial_transcript_preserved(): void
    {
        $service = $this->service([
            'planner' => fn (SubAgentTask $task): SubAgentResult => SubAgentResult::success($task->id, $task->agentId, 'planner complete'),
        ]);

        $result = $service->run('Handle missing participant', new UnifiedActionContext('missing-handler'), ['planner', 'missing'], [
            'rounds' => 2,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('missing_handler', $result->stoppedReason);
        $this->assertStringContainsString("No handler registered for sub-agent 'missing'", (string) $result->error);
        $this->assertCount(1, $result->transcript);
        $this->assertSame('planner', $result->transcript[0]['agent_id']);
        $this->assertArrayHasKey('conversation_1_1_planner', $result->results);
    }

    public function test_needs_user_input_stops_before_later_agents(): void
    {
        $service = $this->service([
            'planner' => fn (SubAgentTask $task): SubAgentResult => SubAgentResult::needsUserInput(
                $task->id,
                $task->agentId,
                'Need the invoice due date.',
                metadata: ['required_inputs' => ['due_date']]
            ),
            'reviewer' => fn (SubAgentTask $task): SubAgentResult => SubAgentResult::success($task->id, $task->agentId, 'should not run'),
        ]);

        $result = $service->run('Collect invoice details', new UnifiedActionContext('needs-input'), ['planner', 'reviewer'], [
            'rounds' => 2,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('needs_user_input', $result->stoppedReason);
        $this->assertSame(0, $result->roundsCompleted);
        $this->assertCount(1, $result->transcript);
        $this->assertTrue($result->transcript[0]['needs_user_input']);
    }

    public function test_stop_on_failure_false_continues_but_reports_failed_outcome(): void
    {
        $service = $this->service([
            'planner' => fn (SubAgentTask $task): SubAgentResult => SubAgentResult::failure(
                $task->id,
                $task->agentId,
                'Planner failed safely.'
            ),
            'reviewer' => fn (SubAgentTask $task): SubAgentResult => SubAgentResult::success(
                $task->id,
                $task->agentId,
                'Reviewer saw: ' . $task->input['last_message']
            ),
        ]);

        $result = $service->run('Continue after failure', new UnifiedActionContext('continue-after-failure'), ['planner', 'reviewer'], [
            'rounds' => 1,
            'stop_on_failure' => false,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('completed_with_failures', $result->stoppedReason);
        $this->assertCount(2, $result->transcript);
        $this->assertFalse($result->transcript[0]['success']);
        $this->assertTrue($result->transcript[1]['success']);
        $this->assertStringContainsString('Planner failed safely.', $result->transcript[1]['message']);
    }

    public function test_participants_can_use_keyed_arrays_aliases_input_and_metadata(): void
    {
        $seen = [];
        $service = $this->service([
            'planner' => [
                'name' => 'Planner Definition',
                'handler' => function (SubAgentTask $task) use (&$seen): SubAgentResult {
                    $seen[$task->agentId] = [
                        'name' => $task->name,
                        'objective' => $task->objective,
                        'input' => $task->input,
                        'metadata' => $task->metadata,
                    ];

                    return SubAgentResult::success($task->id, $task->agentId, 'ok');
                },
            ],
            'reviewer' => function (SubAgentTask $task) use (&$seen): SubAgentResult {
                $seen[$task->agentId] = [
                    'name' => $task->name,
                    'objective' => $task->objective,
                    'input' => $task->input,
                    'metadata' => $task->metadata,
                ];

                return SubAgentResult::success($task->id, $task->agentId, 'ok');
            },
        ]);

        $result = $service->run('Normalize participants', new UnifiedActionContext('normalize-participants'), [
            'planner' => [
                'name' => 'Planner Override',
                'objective' => 'Plan first',
                'input' => ['level' => 'advanced'],
                'metadata' => ['tone' => 'brief'],
            ],
            ['agent' => 'reviewer', 'target' => 'Review second'],
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Planner Override', $seen['planner']['name']);
        $this->assertStringContainsString('Plan first', $seen['planner']['objective']);
        $this->assertSame('advanced', $seen['planner']['input']['level']);
        $this->assertSame('brief', $seen['planner']['metadata']['tone']);
        $this->assertStringContainsString('Review second', $seen['reviewer']['objective']);
    }

    private function service(array $agents): SubAgentConversationService
    {
        $normalized = [];
        foreach ($agents as $id => $handler) {
            $normalized[$id] = is_array($handler)
                ? $handler
                : ['name' => ucfirst((string) $id), 'handler' => $handler];
        }

        return new SubAgentConversationService(
            new SubAgentRegistry($this->app, $normalized),
            new ConversationContextCompactor()
        );
    }
}
