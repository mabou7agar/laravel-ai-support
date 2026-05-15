<?php

namespace LaravelAIEngine\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class TestRealAgentFlowCommandTest extends UnitTestCase
{
    public function test_command_outputs_json_summary_for_multiple_messages(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $ctx1 = new UnifiedActionContext('s1', 1);
        $ctx1->metadata['tool_used'] = 'db_query';
        $ctx2 = new UnifiedActionContext('s1', 1);
        $ctx2->metadata['tool_used'] = 'vector_search';

        $runtime->shouldReceive('process')
            ->twice()
            ->andReturn(
                AgentResponse::conversational('Listed results', $ctx1),
                AgentResponse::conversational('Follow-up answer', $ctx2)
            );

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        Artisan::call('ai:test-real-agent', [
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
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $runtime->shouldReceive('process')
            ->once()
            ->andThrow(new \RuntimeException('Live provider error'));

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        $exitCode = Artisan::call('ai:test-real-agent', [
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

    public function test_command_treats_unsuccessful_agent_response_as_failed_turn(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $runtime->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::failure('No tool specified', context: new UnifiedActionContext('s-failed', 1)));

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        $exitCode = Artisan::call('ai:test-real-agent', [
            '--message' => ['show me recent updates'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $payload['summary']['total_turns']);
        $this->assertSame(0, $payload['summary']['successful_turns']);
        $this->assertSame(1, $payload['summary']['failed_turns']);
        $this->assertSame(0, $payload['summary']['error_turns']);
    }

    public function test_command_passes_local_only_option_to_orchestrator(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $runtime->shouldReceive('process')
            ->once()
            ->withArgs(function ($message, $sessionId, $userId, $options) {
                return $message === 'hello'
                    && is_array($options)
                    && ($options['local_only'] ?? false) === true;
            })
            ->andReturn(AgentResponse::conversational('Hello!', new UnifiedActionContext('s2', 1)));

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        Artisan::call('ai:test-real-agent', [
            '--message' => ['hello'],
            '--local-only' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertTrue($payload['summary']['local_only']);
    }

    public function test_command_passes_rag_models_to_runtime(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $runtime->shouldReceive('process')
            ->once()
            ->withArgs(function ($message, $sessionId, $userId, $options) {
                return $message === 'Tell me about Apollo'
                    && ($options['rag_collections'] ?? []) === ['App\\Models\\Document'];
            })
            ->andReturn(AgentResponse::conversational('Apollo context', new UnifiedActionContext('s3', 1)));

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        Artisan::call('ai:test-real-agent', [
            '--message' => ['Tell me about Apollo'],
            '--rag-model' => ['App\\Models\\Document'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(['App\\Models\\Document'], $payload['summary']['rag_collections']);
    }

    public function test_command_runs_messages_from_script_file(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $scriptPath = tempnam(sys_get_temp_dir(), 'agent-script-');
        file_put_contents($scriptPath, json_encode([
            'messages' => ['start return flow', 'use order R-100', 'confirm'],
        ], JSON_THROW_ON_ERROR));

        $responses = [
            AgentResponse::needsUserInput('Which order should I use?', context: new UnifiedActionContext('script-file', 1)),
            AgentResponse::needsUserInput('Ready to confirm return R-100.', context: new UnifiedActionContext('script-file', 1)),
            AgentResponse::success('Return created.', context: new UnifiedActionContext('script-file', 1)),
        ];

        $runtime->shouldReceive('process')
            ->times(3)
            ->withArgs(function ($message): bool {
                static $messages = ['start return flow', 'use order R-100', 'confirm'];

                return $message === array_shift($messages);
            })
            ->andReturn(...$responses);

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        $exitCode = Artisan::call('ai:test-real-agent', [
            '--script-file' => $scriptPath,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, $payload['summary']['total_turns']);

        @unlink($scriptPath);
    }

    public function test_unknown_named_script_returns_failure_instead_of_domain_specific_default(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldNotReceive('process');

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        $exitCode = Artisan::call('ai:test-real-agent', [
            '--script' => 'domain-flow',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown built-in script', Artisan::output());
    }
}
