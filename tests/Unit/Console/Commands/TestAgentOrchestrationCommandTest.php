<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use LaravelAIEngine\DTOs\AgentOrchestrationReport;
use LaravelAIEngine\Services\Agent\AgentOrchestrationInspector;
use LaravelAIEngine\Services\Diagnostics\TestEverythingRunner;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class TestAgentOrchestrationCommandTest extends UnitTestCase
{
    public function test_command_inspects_links_and_runs_focused_tests(): void
    {
        $inspector = Mockery::mock(AgentOrchestrationInspector::class);
        $inspector->shouldReceive('inspect')
            ->once()
            ->andReturn(new AgentOrchestrationReport(
                nodes: ['tools' => ['run_sub_agent'], 'sub_agents' => ['general'], 'skills' => []],
                links: [],
                issues: [],
                metrics: [
                    'tool_count' => 1,
                    'sub_agent_count' => 1,
                    'skill_count' => 0,
                    'link_count' => 0,
                    'complexity_score' => 2,
                    'max_complexity' => 80,
                ]
            ));

        $runner = Mockery::mock(TestEverythingRunner::class);
        $runner->shouldReceive('runStages')
            ->once()
            ->withArgs(function (array $stages, bool $stopOnFailure): bool {
                $this->assertFalse($stopOnFailure);
                $this->assertSame('agent_orchestration_core', $stages[0]['name']);
                $this->assertStringContainsString('tests/Unit/Services/Agent', $stages[0]['command']);

                return true;
            })
            ->andReturn([
                [
                    'name' => 'agent_orchestration_core',
                    'command' => 'phpunit',
                    'workdir' => dirname(__DIR__, 4),
                    'status' => 'passed',
                    'exit_code' => 0,
                    'duration_ms' => 10.0,
                    'output' => '',
                ],
            ]);

        $this->app->instance(AgentOrchestrationInspector::class, $inspector);
        $this->app->instance(TestEverythingRunner::class, $runner);

        $this->artisan('ai:test-orchestration')
            ->expectsOutput('Agent orchestration graph')
            ->expectsOutput('No missing links or complexity violations found.')
            ->expectsOutput('Agent orchestration links and focused tests passed.')
            ->assertSuccessful();
    }

    public function test_command_fails_when_inspector_finds_missing_links(): void
    {
        $inspector = Mockery::mock(AgentOrchestrationInspector::class);
        $inspector->shouldReceive('inspect')
            ->once()
            ->andReturn(new AgentOrchestrationReport(
                nodes: ['tools' => [], 'sub_agents' => ['research'], 'skills' => []],
                links: [],
                issues: [
                    [
                        'severity' => 'error',
                        'code' => 'missing_tool',
                        'message' => 'Sub-agent [research] references missing tool [search].',
                        'subject' => 'sub_agent:research',
                    ],
                ],
                metrics: [
                    'tool_count' => 0,
                    'sub_agent_count' => 1,
                    'skill_count' => 0,
                    'link_count' => 0,
                    'complexity_score' => 1,
                    'max_complexity' => 80,
                ]
            ));

        $runner = Mockery::mock(TestEverythingRunner::class);
        $runner->shouldNotReceive('runStages');

        $this->app->instance(AgentOrchestrationInspector::class, $inspector);
        $this->app->instance(TestEverythingRunner::class, $runner);

        $this->artisan('ai:test-orchestration', ['--no-phpunit' => true])
            ->expectsOutput('Agent orchestration graph')
            ->assertFailed();
    }
}
