<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class GoalAgentServiceTest extends UnitTestCase
{
    public function test_goal_agent_executes_request_defined_sub_agents_in_order(): void
    {
        $registry = new SubAgentRegistry($this->app, [
            'research' => [
                'handler' => fn (SubAgentTask $task, UnifiedActionContext $context, array $previousResults, array $options) => SubAgentResult::success(
                    $task->id,
                    $task->agentId,
                    'Research complete',
                    ['facts' => ['A', 'B']]
                ),
            ],
            'writer' => [
                'handler' => function (SubAgentTask $task, UnifiedActionContext $context, array $previousResults) {
                    $this->assertArrayHasKey('research_task', $previousResults);

                    return SubAgentResult::success(
                        $task->id,
                        $task->agentId,
                        'Writer complete',
                        ['draft' => 'final answer']
                    );
                },
            ],
        ]);

        $service = new GoalAgentService(
            new SubAgentPlanner($registry),
            new SubAgentExecutionService($registry)
        );

        $response = $service->execute('Ship an answer', new UnifiedActionContext('goal-session', 1), [
            'sub_agents' => [
                [
                    'id' => 'research_task',
                    'agent_id' => 'research',
                    'objective' => 'Find facts',
                ],
                [
                    'id' => 'write_task',
                    'agent_id' => 'writer',
                    'objective' => 'Write result',
                    'depends_on' => ['research_task'],
                ],
            ],
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('goal_agent', $response->strategy);
        $this->assertArrayHasKey('research_task', $response->data['results']);
        $this->assertArrayHasKey('write_task', $response->data['results']);
        $this->assertStringContainsString('Target completed.', $response->message);
    }

    public function test_goal_agent_reports_missing_sub_agent_handler(): void
    {
        $registry = new SubAgentRegistry($this->app, [
            'research' => [
                'name' => 'Research',
            ],
        ]);

        $service = new GoalAgentService(
            new SubAgentPlanner($registry),
            new SubAgentExecutionService($registry)
        );

        $response = $service->execute('Investigate target', new UnifiedActionContext('goal-session', 1), [
            'sub_agents' => [
                [
                    'id' => 'research_task',
                    'agent_id' => 'research',
                    'objective' => 'Find facts',
                ],
            ],
        ]);

        $this->assertFalse($response->success);
        $this->assertSame('goal_agent', $response->strategy);
        $this->assertSame("No handler registered for sub-agent 'research'.", $response->data['results']['research_task']['error']);
    }

    public function test_goal_agent_fails_task_depending_on_nonexistent_task(): void
    {
        $writerRan = false;
        $registry = new SubAgentRegistry($this->app, [
            'writer' => [
                'handler' => function (SubAgentTask $task, UnifiedActionContext $context, array $previousResults) use (&$writerRan) {
                    $writerRan = true;

                    return SubAgentResult::success($task->id, $task->agentId, 'Writer complete');
                },
            ],
        ]);

        $service = new GoalAgentService(
            new SubAgentPlanner($registry),
            new SubAgentExecutionService($registry)
        );

        $response = $service->execute('Ship an answer', new UnifiedActionContext('goal-session', 1), [
            'sub_agents' => [
                [
                    'id' => 'write_task',
                    'agent_id' => 'writer',
                    'objective' => 'Write result',
                    'depends_on' => ['research_task'],
                ],
            ],
        ]);

        $this->assertFalse($writerRan);
        $this->assertFalse($response->success);
        $result = $response->data['results']['write_task'];
        $this->assertFalse($result['success']);
        $this->assertSame('research_task', $result['metadata']['missing_dependency']);
    }

    public function test_goal_agent_fails_circular_dependencies(): void
    {
        $aRan = false;
        $bRan = false;
        $registry = new SubAgentRegistry($this->app, [
            'agent_a' => [
                'handler' => function (SubAgentTask $task, UnifiedActionContext $context, array $previousResults) use (&$aRan) {
                    $aRan = true;

                    return SubAgentResult::success($task->id, $task->agentId, 'A complete');
                },
            ],
            'agent_b' => [
                'handler' => function (SubAgentTask $task, UnifiedActionContext $context, array $previousResults) use (&$bRan) {
                    $bRan = true;

                    return SubAgentResult::success($task->id, $task->agentId, 'B complete');
                },
            ],
        ]);

        $service = new GoalAgentService(
            new SubAgentPlanner($registry),
            new SubAgentExecutionService($registry)
        );

        $response = $service->execute('Ship an answer', new UnifiedActionContext('goal-session', 1), [
            'sub_agents' => [
                [
                    'id' => 'task_a',
                    'agent_id' => 'agent_a',
                    'objective' => 'A',
                    'depends_on' => ['task_b'],
                ],
                [
                    'id' => 'task_b',
                    'agent_id' => 'agent_b',
                    'objective' => 'B',
                    'depends_on' => ['task_a'],
                ],
            ],
        ]);

        // No handler should run when the plan contains a cycle.
        $this->assertFalse($aRan);
        $this->assertFalse($bRan);
        $this->assertFalse($response->success);
        $this->assertTrue($response->data['results']['task_a']['metadata']['circular_dependency']);

        // Any other emitted task in the cycle must also be flagged circular
        // (critical stop_on_failure may halt the loop before reaching it).
        if (isset($response->data['results']['task_b'])) {
            $this->assertTrue($response->data['results']['task_b']['metadata']['circular_dependency']);
        }
    }

    public function test_goal_agent_fails_forward_reference_dependency(): void
    {
        $writerRan = false;
        $registry = new SubAgentRegistry($this->app, [
            'writer' => [
                'handler' => function (SubAgentTask $task, UnifiedActionContext $context, array $previousResults) use (&$writerRan) {
                    $writerRan = true;

                    return SubAgentResult::success($task->id, $task->agentId, 'Writer complete');
                },
            ],
            'research' => [
                'handler' => fn (SubAgentTask $task, UnifiedActionContext $context, array $previousResults) => SubAgentResult::success(
                    $task->id,
                    $task->agentId,
                    'Research complete'
                ),
            ],
        ]);

        $service = new GoalAgentService(
            new SubAgentPlanner($registry),
            new SubAgentExecutionService($registry)
        );

        // write_task (order 0) depends on research_task (order 1) -> forward reference.
        $response = $service->execute('Ship an answer', new UnifiedActionContext('goal-session', 1), [
            'sub_agents' => [
                [
                    'id' => 'write_task',
                    'agent_id' => 'writer',
                    'objective' => 'Write result',
                    'order' => 0,
                    'depends_on' => ['research_task'],
                ],
                [
                    'id' => 'research_task',
                    'agent_id' => 'research',
                    'objective' => 'Find facts',
                    'order' => 1,
                ],
            ],
        ]);

        $this->assertFalse($writerRan);
        $this->assertFalse($response->success);
        $this->assertSame('research_task', $response->data['results']['write_task']['metadata']['forward_dependency']);
    }

    public function test_goal_agent_can_request_more_input_from_sub_agent(): void
    {
        $registry = new SubAgentRegistry($this->app, [
            'planner' => [
                'handler' => fn (SubAgentTask $task, UnifiedActionContext $context, array $previousResults, array $options) => SubAgentResult::needsUserInput(
                    $task->id,
                    $task->agentId,
                    'Which market should I target?',
                    metadata: ['required_inputs' => [['name' => 'market', 'type' => 'text']]]
                ),
            ],
        ]);

        $service = new GoalAgentService(
            new SubAgentPlanner($registry),
            new SubAgentExecutionService($registry)
        );

        $response = $service->execute('Build launch plan', new UnifiedActionContext('goal-session', 1), [
            'sub_agents' => ['planner'],
        ]);

        $this->assertTrue($response->success);
        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Which market should I target?', $response->message);
        $this->assertSame('market', $response->requiredInputs[0]['name']);
    }
}
