<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\AgentOrchestrationInspector;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeDriverMap;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager;
use LaravelAIEngine\Services\Agent\Runtime\LangGraphAgentRuntime;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiAgentRuntime;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiGateway;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiSdk;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiTurn;
use LaravelAIEngine\Tests\Support\Stubs\FakeLaravelAiGateway;
use LaravelAIEngine\Tests\Support\Stubs\RecordingAgentRuntime;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class LaravelAiAgentRuntimeTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No users table in unit tests; billing is covered by LaravelAiRuntimeParityTest.
        config()->set('ai-engine.credits.enabled', false);
    }

    public function test_runtime_is_not_registered_unless_enabled(): void
    {
        config()->set('ai-agent.runtime.laravel_ai.enabled', false);

        $this->assertFalse(LaravelAiSdk::shouldRegister());
        $this->assertNotContains('laravel_ai', AgentRuntimeDriverMap::names());
        $this->assertFalse((bool) config('ai-agent.runtime.laravel_ai.enabled'), 'the runtime must ship disabled');
    }

    public function test_enabling_without_the_sdk_does_not_register_and_is_reported(): void
    {
        $this->skipWhenSdkInstalled();
        config()->set('ai-agent.runtime.laravel_ai.enabled', true);

        $this->assertNotContains('laravel_ai', AgentRuntimeDriverMap::names());

        $report = (new AgentRuntimeConfigValidator())->validate();
        $issue = collect($report['issues'])->firstWhere('code', 'laravel_ai_sdk_missing');

        $this->assertTrue($report['passed'], 'a warning must not fail validation while laravel_ai is not the default');
        $this->assertSame('warning', $issue['severity']);
        $this->assertStringContainsString('composer require laravel/ai', $issue['message']);
    }

    public function test_defaulting_to_laravel_ai_without_the_sdk_fails_validation(): void
    {
        $this->skipWhenSdkInstalled();
        config()->set('ai-agent.runtime.laravel_ai.enabled', true);
        config()->set('ai-agent.runtime.default', 'laravel_ai');

        $report = (new AgentRuntimeConfigValidator())->validate();
        $codes = array_column($report['issues'], 'code');

        $this->assertFalse($report['passed']);
        $this->assertContains('laravel_ai_sdk_missing', $codes);
        $this->assertContains('invalid_runtime', $codes);
        $this->assertSame('error', collect($report['issues'])->firstWhere('code', 'laravel_ai_sdk_missing')['severity']);
    }

    public function test_runtime_without_gateway_or_sdk_fails_closed(): void
    {
        $this->skipWhenSdkInstalled();

        $runtime = new LaravelAiAgentRuntime();
        $response = $runtime->process('hello', 'no-sdk', 1);

        $this->assertFalse($response->success);
        $this->assertStringContainsString('requires the laravel/ai package', $response->message);
        $this->assertFalse($runtime->capabilities()->tools);
        $this->assertFalse($runtime->capabilities()->metadata['available']);
    }

    public function test_capabilities_reflect_gateway_and_streaming_config(): void
    {
        $runtime = new LaravelAiAgentRuntime(new FakeLaravelAiGateway());
        $capabilities = $runtime->capabilities();

        $this->assertSame('laravel_ai', $runtime->name());
        $this->assertTrue($capabilities->streaming);
        $this->assertTrue($capabilities->tools);
        $this->assertTrue($capabilities->humanApprovals);
        $this->assertTrue($capabilities->interrupts);
        $this->assertFalse($capabilities->subAgents);
        $this->assertFalse($capabilities->artifacts);

        config()->set('ai-agent.runtime.laravel_ai.stream', false);

        $this->assertFalse($runtime->capabilities()->streaming);
    }

    public function test_usage_and_sdk_details_are_reported_in_metadata(): void
    {
        $gateway = new FakeLaravelAiGateway([
            new LaravelAiTurn(
                text: 'Hi.',
                usage: ['input_tokens' => 30, 'output_tokens' => 12, 'cache_read_input_tokens' => 10, 'reasoning_tokens' => 4],
                invocationId: 'inv-1',
                conversationId: 'conv-1',
                provider: 'anthropic',
                model: 'claude-test',
                reasoning: 'thinking'
            ),
        ]);

        $response = (new LaravelAiAgentRuntime($gateway))->process('hello', 'usage-session', 5);

        $this->assertTrue($response->success);
        $this->assertSame('Hi.', $response->message);
        $this->assertSame('laravel_ai', $response->metadata['agent_runtime']);
        $this->assertSame([
            'input_tokens' => 30,
            'output_tokens' => 12,
            'tokens_used' => 42,
            'cache_read_input_tokens' => 10,
            'reasoning_tokens' => 4,
            'provider_model' => 'claude-test',
        ], $response->metadata['usage']);
        $this->assertSame('conv-1', $response->metadata['laravel_ai']['conversation_id']);
        $this->assertSame('anthropic', $response->metadata['laravel_ai']['provider']);
        $this->assertArrayNotHasKey('reasoning_trace', $response->metadata, 'reasoning stays private unless exposed');
    }

    public function test_reasoning_is_only_exposed_when_requested(): void
    {
        $gateway = new FakeLaravelAiGateway([
            new LaravelAiTurn(text: 'Done.', reasoning: 'step by step'),
        ]);

        $response = (new LaravelAiAgentRuntime($gateway))->process('hello', 'reasoning-session', 5, ['expose_reasoning' => true]);

        $this->assertSame(['step by step'], $response->metadata['reasoning_trace']);
    }

    public function test_provider_model_and_streaming_options_reach_the_gateway(): void
    {
        config()->set('ai-agent.runtime.laravel_ai.provider', 'openai');
        config()->set('ai-agent.runtime.laravel_ai.model', 'config-model');
        config()->set('ai-agent.runtime.laravel_ai.timeout', 45);

        $gateway = new FakeLaravelAiGateway([new LaravelAiTurn(text: 'ok'), new LaravelAiTurn(text: 'ok')]);
        $runtime = new LaravelAiAgentRuntime($gateway);

        $runtime->process('a', 'opts-session', 5);
        $runtime->process('b', 'opts-session', 5, ['laravel_ai_model' => 'request-model', 'laravel_ai_stream' => false]);

        [$first, $second] = $gateway->invocations;
        $this->assertSame('openai', $first->provider);
        $this->assertSame('config-model', $first->model);
        $this->assertSame(45, $first->timeout);
        $this->assertTrue($first->stream);
        $this->assertSame('request-model', $second->model);
        $this->assertFalse($second->stream);
    }

    public function test_pending_approval_outside_a_persisted_run_returns_resume_state(): void
    {
        $gateway = new FakeLaravelAiGateway([
            new LaravelAiTurn(
                text: '',
                invocationId: 'inv-2',
                conversationId: 'conv-2',
                pendingApprovals: [['id' => 'call-1', 'tool' => 'charge_card', 'arguments' => ['amount' => 10], 'reason' => 'Payments need approval.']]
            ),
        ]);

        $response = (new LaravelAiAgentRuntime($gateway))->process('charge me', 'direct-session', 5);

        $this->assertTrue($response->success);
        $this->assertTrue($response->needsUserInput);
        $this->assertFalse($response->isComplete);
        $this->assertSame('Approval required before running: charge_card.', $response->message);
        $this->assertArrayNotHasKey('agent_run_status', $response->metadata);
        $this->assertSame([
            'laravel_ai_conversation_id' => 'conv-2',
            'laravel_ai_invocation_id' => 'inv-2',
            'laravel_ai_pending_approvals' => ['call-1'],
        ], $response->data['resume_payload']);
    }

    public function test_client_supplied_resume_payload_without_a_run_is_ignored(): void
    {
        $gateway = new FakeLaravelAiGateway([new LaravelAiTurn(text: 'plain')]);

        (new LaravelAiAgentRuntime($gateway))->process('continue', 'forged-session', 5, [
            'resume_payload' => [
                'approval_key' => 'forged',
                'laravel_ai_conversation_id' => 'someone-elses',
                'laravel_ai_pending_approvals' => ['call-x'],
            ],
        ]);

        $this->assertNull($gateway->invocations[0]->decisions);
        $this->assertNotSame('someone-elses', $gateway->invocations[0]->conversationId);
    }

    public function test_manager_attribution_keeps_legacy_names_for_built_ins_and_names_registered_runtimes(): void
    {
        config()->set('ai-agent.runtime.drivers', ['recording' => RecordingAgentRuntime::class]);

        $laravel = Mockery::mock(LaravelAgentRuntime::class);
        $langGraph = Mockery::mock(LangGraphAgentRuntime::class);
        $laravel->shouldReceive('name')->andReturn('laravel');
        $langGraph->shouldReceive('name')->andReturn('langgraph');
        $manager = new AgentRuntimeManager($laravel, $langGraph);

        // Built-in selection under a built-in default: unchanged historical attribution.
        $this->assertSame('laravel', $manager->runtimeNameFor([]));
        $this->assertSame('laravel', $manager->runtimeNameFor(['agent_runtime' => 'langgraph']));
        $this->assertSame('laravel', $manager->runtimeNameFor(['agent_runtime' => 'unknown']));

        // A registered runtime is attributed to itself, whether requested or default.
        $this->assertSame('recording', $manager->runtimeNameFor(['agent_runtime' => 'recording']));
        config()->set('ai-agent.runtime.default', 'recording');
        $this->assertSame('recording', $manager->runtimeNameFor([]));
        $this->assertSame('laravel', $manager->runtimeNameFor(['agent_runtime' => 'laravel']));
    }

    public function test_orchestration_inspector_lists_registered_runtimes(): void
    {
        $before = $this->app->make(AgentOrchestrationInspector::class)->inspect()->nodes['runtimes'];
        $this->assertSame(['laravel'], $before, 'existing installs report the same runtimes as before');

        config()->set('ai-agent.runtime.drivers', ['laravel_ai' => LaravelAiAgentRuntime::class]);

        $after = $this->app->make(AgentOrchestrationInspector::class)->inspect()->nodes['runtimes'];
        $this->assertSame(['laravel', 'laravel_ai'], $after);
    }

    public function test_sub_agent_capability_follows_the_bridged_package_tools(): void
    {
        $runtime = new LaravelAiAgentRuntime(new FakeLaravelAiGateway());
        $this->assertFalse($runtime->capabilities()->subAgents);

        config()->set('ai-agent.runtime.laravel_ai.package_tools', 'lookup_order, run_sub_agent');
        $this->assertTrue($runtime->capabilities()->subAgents);

        config()->set('ai-agent.runtime.laravel_ai.package_tools', true);
        $this->assertTrue($runtime->capabilities()->subAgents);
    }

    public function test_validator_warns_about_package_tools_that_are_not_registered(): void
    {
        config()->set('ai-agent.runtime.laravel_ai.enabled', true);
        config()->set('ai-agent.runtime.laravel_ai.package_tools', ['run_skill', 'no_such_tool']);

        if (!LaravelAiSdk::installed()) {
            // Without the SDK the missing-SDK warning short-circuits the laravel_ai checks.
            $codes = array_column((new AgentRuntimeConfigValidator())->validate()['issues'], 'code');
            $this->assertSame(['laravel_ai_sdk_missing'], $codes);

            return;
        }

        $issues = collect((new AgentRuntimeConfigValidator())->validate()['issues'])->where('code', 'unknown_laravel_ai_package_tool');
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('[no_such_tool]', $issues->first()['message']);
    }

    private function skipWhenSdkInstalled(): void
    {
        if (LaravelAiSdk::installed()) {
            $this->markTestSkipped('Covers installs without laravel/ai; the SDK is installed here.');
        }
    }
}
