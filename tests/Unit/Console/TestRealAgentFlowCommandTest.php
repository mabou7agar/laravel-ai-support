<?php

namespace LaravelAIEngine\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentOrchestrator;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class TestRealAgentFlowCommandTest extends UnitTestCase
{
    public function test_command_outputs_json_summary_for_multiple_messages(): void
    {
        $orchestrator = Mockery::mock(AgentOrchestrator::class);

        $ctx1 = new UnifiedActionContext('s1', 1);
        $ctx1->metadata['tool_used'] = 'db_query';
        $ctx2 = new UnifiedActionContext('s1', 1);
        $ctx2->metadata['tool_used'] = 'vector_search';

        $orchestrator->shouldReceive('process')
            ->twice()
            ->andReturn(
                AgentResponse::conversational('Listed results', $ctx1),
                AgentResponse::conversational('Follow-up answer', $ctx2)
            );

        $this->app->instance(AgentOrchestrator::class, $orchestrator);

        Artisan::call('ai-engine:test-real-agent', [
            '--message' => ['list invoices', 'what is status of first one'],
            '--session' => 'real-test-session',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame(2, $payload['summary']['total_turns']);
        $this->assertSame(2, $payload['summary']['successful_turns']);
        $this->assertSame(0, $payload['summary']['failed_turns']);
        $this->assertSame(1, $payload['summary']['tool_counts']['db_query']);
        $this->assertSame(1, $payload['summary']['tool_counts']['vector_search']);
    }

    public function test_command_handles_exceptions_and_returns_failure(): void
    {
        $orchestrator = Mockery::mock(AgentOrchestrator::class);

        $orchestrator->shouldReceive('process')
            ->once()
            ->andThrow(new \RuntimeException('Live provider error'));

        $this->app->instance(AgentOrchestrator::class, $orchestrator);

        $exitCode = Artisan::call('ai-engine:test-real-agent', [
            '--message' => ['list invoices'],
            '--stop-on-error' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['summary']['failed_turns']);
        $this->assertSame(false, $payload['turns'][0]['success']);
    }

    public function test_command_passes_local_only_option_to_orchestrator(): void
    {
        $orchestrator = Mockery::mock(AgentOrchestrator::class);

        $orchestrator->shouldReceive('process')
            ->once()
            ->withArgs(function ($message, $sessionId, $userId, $options) {
                return $message === 'hello'
                    && is_array($options)
                    && ($options['local_only'] ?? false) === true;
            })
            ->andReturn(AgentResponse::conversational('Hello!', new UnifiedActionContext('s2', 1)));

        $this->app->instance(AgentOrchestrator::class, $orchestrator);

        Artisan::call('ai-engine:test-real-agent', [
            '--message' => ['hello'],
            '--local-only' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertTrue($payload['summary']['local_only']);
    }
}
