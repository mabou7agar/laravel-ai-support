<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentRunRuntimeControlService;
use LaravelAIEngine\Services\Agent\AgentRunSafetyService;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeDriverMap;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\EngineAgent;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiInvocation;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiSdk;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\SdkLaravelAiGateway;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\PackageAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\Support\Stubs\LaravelAi\NotAnSdkAgent;
use LaravelAIEngine\Tests\Support\Stubs\RecordingAgentTool;
use LaravelAIEngine\Tests\Support\Stubs\LaravelAi\SupportDeskAgent;
use LaravelAIEngine\Tests\TestCase;

/**
 * The laravel_ai runtime against the real laravel/ai SDK (driven by the SDK's
 * own fakes, so no provider is called).
 *
 * Skipped unless laravel/ai is installed. It needs PHP 8.3+ and Laravel 12+,
 * so CI runs it in the dedicated `laravel-ai` job.
 */
class LaravelAiSdkGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!LaravelAiSdk::installed()) {
            $this->markTestSkipped('Requires laravel/ai (PHP 8.3+, Laravel 12+). Runs in the laravel-ai CI job.');
        }

        $this->artisan('migrate', [
            '--path' => dirname((new \ReflectionClass(\Laravel\Ai\AiServiceProvider::class))->getFileName(), 2) . '/database/migrations',
            '--realpath' => true,
        ])->run();

        config()->set('ai-agent.runtime.laravel_ai.enabled', true);
        config()->set('ai-agent.runtime.laravel_ai.instructions', 'You are a test agent.');
        config()->set('ai-engine.credits.enabled', false);
        RecordingAgentTool::reset();
        // The SDK's own conversation titling is left ON here on purpose: the
        // runtime must avoid the extra, unaccounted title call by itself.
    }

    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);

        if (class_exists(\Laravel\Ai\AiServiceProvider::class)) {
            $providers[] = \Laravel\Ai\AiServiceProvider::class;
        }

        return $providers;
    }

    public function test_enabled_runtime_registers_and_validates_with_the_sdk_installed(): void
    {
        $this->assertContains('laravel_ai', AgentRuntimeDriverMap::names());

        config()->set('ai-agent.runtime.default', 'laravel_ai');
        $report = (new AgentRuntimeConfigValidator())->validate();
        $this->assertTrue($report['passed'], json_encode($report['issues']));

        config()->set('ai-agent.runtime.laravel_ai.agent', NotAnSdkAgent::class);
        config()->set('ai-agent.runtime.laravel_ai.tools', [NotAnSdkAgent::class]);
        $codes = array_column((new AgentRuntimeConfigValidator())->validate()['issues'], 'code');
        $this->assertContains('invalid_laravel_ai_agent', $codes);
        $this->assertContains('invalid_laravel_ai_tool', $codes);
    }

    public function test_prompt_maps_text_usage_and_provider_details(): void
    {
        EngineAgent::fake([
            new \Laravel\Ai\Responses\TextResponse(
                'Hello from the SDK.',
                new \Laravel\Ai\Responses\Data\TextUsage(10, 5, reasoningTokens: 2),
                new \Laravel\Ai\Responses\Data\Meta('openai', 'gpt-fake')
            ),
        ]);

        $turn = app(SdkLaravelAiGateway::class)->run($this->invocation('Say hello', stream: false));

        $this->assertSame('Hello from the SDK.', $turn->text);
        $this->assertSame(10, $turn->inputTokens());
        $this->assertSame(5, $turn->outputTokens());
        $this->assertSame(2, $turn->usage['reasoning_tokens']);
        $this->assertSame('gpt-fake', $turn->model);
        $this->assertNotEmpty($turn->invocationId);
        $this->assertFalse($turn->cancelled);
        EngineAgent::assertPrompted('Say hello');
    }

    public function test_stream_hands_sdk_events_to_the_listener_and_combines_text(): void
    {
        EngineAgent::fake(['Hello there friend']);
        $types = [];

        $turn = app(SdkLaravelAiGateway::class)->run(
            $this->invocation('Greet me'),
            function (array $event) use (&$types): bool {
                $types[] = $event['type'];

                return true;
            }
        );

        $this->assertSame('Hello there friend', $turn->text);
        $this->assertSame(3, count(array_keys($types, 'text_delta', true)));
        $this->assertContains('stream_start', $types);
        $this->assertContains('stream_end', $types);
        $this->assertFalse($turn->cancelled);
    }

    public function test_listener_returning_false_abandons_the_stream(): void
    {
        EngineAgent::fake(['one two three four']);

        $turn = app(SdkLaravelAiGateway::class)->run(
            $this->invocation('Count', conversationId: null),
            static fn (array $event): bool => $event['type'] !== 'text_delta'
        );

        $this->assertTrue($turn->cancelled);
        $this->assertSame('one', $turn->text);
        // The conversation the turn ran in is kept, but the abandoned turn was not stored in it.
        $this->assertNotNull($turn->conversationId);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('agent_conversation_messages')->where('conversation_id', $turn->conversationId)->count());
    }

    public function test_pending_approval_is_mapped_and_resume_sends_decisions(): void
    {
        EngineAgent::fake([
            \Laravel\Ai\Responses\AgentResponse::fakeWithPendingApprovals([
                new \Laravel\Ai\Approvals\PendingApproval('call-1', 'refund_order', ['order' => 42], 'Refunds need approval.'),
            ]),
            'Refund issued.',
        ]);

        $gateway = app(SdkLaravelAiGateway::class);
        $paused = $gateway->run($this->invocation('Refund order 42', stream: false));

        $this->assertSame([[
            'id' => 'call-1',
            'tool' => 'refund_order',
            'arguments' => ['order' => 42],
            'reason' => 'Refunds need approval.',
        ]], $paused->pendingApprovals);
        $this->assertNotNull($paused->conversationId, 'a paused turn is stored so it can be resumed');

        $resumed = $gateway->run(new LaravelAiInvocation(
            message: 'continue',
            sessionId: 'sdk-session',
            userId: 9,
            conversationId: $paused->conversationId,
            decisions: ['call-1' => true],
            stream: false
        ));

        $this->assertSame('Refund issued.', $resumed->text);
        $this->assertSame([], $resumed->pendingApprovals);
    }

    public function test_configured_host_agent_is_used_instead_of_the_default(): void
    {
        config()->set('ai-agent.runtime.laravel_ai.agent', SupportDeskAgent::class);
        SupportDeskAgent::fake(['Support desk here.']);
        EngineAgent::fake()->preventStrayPrompts();

        $turn = app(SdkLaravelAiGateway::class)->run($this->invocation('Help', stream: false));

        $this->assertSame('Support desk here.', $turn->text);
        SupportDeskAgent::assertPrompted('Help');
    }

    public function test_run_agent_job_executes_on_the_sdk_backed_runtime_end_to_end(): void
    {
        config()->set('ai-agent.runtime.default', 'laravel_ai');
        EngineAgent::fake([
            new \Laravel\Ai\Responses\TextResponse(
                'Order 42 has shipped.',
                new \Laravel\Ai\Responses\Data\TextUsage(12, 8),
                new \Laravel\Ai\Responses\Data\Meta('openai', 'gpt-fake')
            ),
        ]);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'sdk-e2e',
            'user_id' => '9',
            'tenant_id' => 'tenant-a',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);

        $this->handle(new RunAgentJob($run->id, 'Where is order 42?', 'sdk-e2e', '9', ['tenant_id' => 'tenant-a']));

        $run->refresh();
        $events = collect($run->metadata['events'] ?? []);

        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('laravel_ai', $run->runtime);
        $this->assertSame('Order 42 has shipped.', $run->final_response['message']);
        $this->assertSame(20, $run->metadata['budget']['usage']['tokens']);
        $this->assertSame(
            'Order 42 has shipped.',
            $events->where('name', AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED)->pluck('payload.token')->implode('')
        );
        $this->assertSame(1, $events->where('name', AgentRunEventStreamService::RUN_COMPLETED)->count());
    }

    public function test_sdk_approval_pause_and_resume_through_the_package_control_surface(): void
    {
        config()->set('ai-agent.runtime.default', 'laravel_ai');
        EngineAgent::fake([
            \Laravel\Ai\Responses\AgentResponse::fakeWithPendingApprovals([
                new \Laravel\Ai\Approvals\PendingApproval('call-7', 'refund_order', ['order' => 7], 'Refunds need approval.'),
            ]),
            'Refund issued.',
        ]);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'sdk-approval',
            'user_id' => '9',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);

        $this->handle(new RunAgentJob($run->id, 'Refund order 7', 'sdk-approval', '9'));

        $run->refresh();
        $this->assertSame(AIAgentRun::STATUS_WAITING_APPROVAL, $run->status);
        $approvalKey = $run->final_response['data']['approval_key'];

        app(AgentRunRuntimeControlService::class)->resume($run, ['approval_key' => $approvalKey, 'actor_id' => 'admin-1']);

        $run->refresh();
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('Refund issued.', $run->final_response['message']);
        $this->assertTrue($run->final_response['metadata']['laravel_ai']['resumed']);
    }

    public function test_new_conversations_are_opened_without_the_sdk_title_call(): void
    {
        config()->set('ai.conversations.generate_title', true);
        EngineAgent::fake(['Only answer.'])->preventStrayPrompts();

        $turn = app(SdkLaravelAiGateway::class)->run($this->invocation('Plan my week around three client meetings', stream: false));

        $this->assertSame('Only answer.', $turn->text, 'the single scripted response was not spent on a title');
        $this->assertNotNull($turn->conversationId);
        $this->assertSame(
            'Plan my week around three client meetings',
            \Illuminate\Support\Facades\DB::table('agent_conversations')->where('id', $turn->conversationId)->value('title')
        );
    }

    public function test_sdk_titling_can_be_opted_back_into(): void
    {
        config()->set('ai.conversations.generate_title', true);
        config()->set('ai-agent.runtime.laravel_ai.generate_titles', true);
        // The SDK answers first and titles the conversation afterwards.
        EngineAgent::fake(['The answer.', 'Weekly plan']);

        $turn = app(SdkLaravelAiGateway::class)->run($this->invocation('Plan my week', stream: false));

        $this->assertSame('The answer.', $turn->text);
        $this->assertNotNull($turn->conversationId);
        $this->assertSame('Weekly plan', \Illuminate\Support\Facades\DB::table('agent_conversations')->where('id', $turn->conversationId)->value('title'));
    }

    public function test_package_tool_schema_is_exposed_to_the_sdk(): void
    {
        $tool = new PackageAgentTool(new RecordingAgentTool(), static fn () => new \LaravelAIEngine\DTOs\UnifiedActionContext('s', 1));
        $schema = (new \Laravel\Ai\ObjectSchema($tool->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory())))->toSchema();

        $this->assertSame('lookup_order', $tool->name());
        $this->assertSame('integer', $schema['properties']['order']['type']);
        $this->assertSame(['order'], $schema['required']);
    }

    public function test_package_tool_runs_through_the_policy_checked_executor_with_run_scope(): void
    {
        $this->registerPackageTool(new RecordingAgentTool());
        EngineAgent::fake([
            new \Laravel\Ai\Responses\Data\ToolCall('call-1', 'lookup_order', ['order' => 42]),
            'Order 42 has shipped.',
        ]);

        $turn = app(SdkLaravelAiGateway::class)->run(new LaravelAiInvocation(
            message: 'Where is order 42?',
            sessionId: 'tool-session',
            userId: 9,
            stream: false,
            options: ['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a']
        ));

        $this->assertSame('Order 42 has shipped.', $turn->text);
        $this->assertCount(1, RecordingAgentTool::$calls);
        $this->assertSame(42, RecordingAgentTool::$calls[0]['parameters']['order']);
        $this->assertSame('tenant-a', RecordingAgentTool::$calls[0]['request_options']['tenant_id']);
        $this->assertSame('tool-session', RecordingAgentTool::$calls[0]['session_id']);
        $this->assertSame('lookup_order', $turn->toolResults[0]['name']);
        $this->assertTrue($turn->toolResults[0]['successful']);
        $this->assertStringContainsString('has shipped', json_encode($turn->toolResults[0]['result']));
    }

    public function test_execution_policy_still_blocks_a_bridged_package_tool(): void
    {
        config()->set('ai-agent.execution_policy.tool_deny', ['lookup_order']);
        $this->registerPackageTool(new RecordingAgentTool());
        EngineAgent::fake([
            new \Laravel\Ai\Responses\Data\ToolCall('call-2', 'lookup_order', ['order' => 7]),
            'I could not look that up.',
        ]);

        $turn = app(SdkLaravelAiGateway::class)->run($this->invocation('Where is order 7?', stream: false));

        $this->assertSame([], RecordingAgentTool::$calls);
        $this->assertStringContainsString('blocked', json_encode($turn->toolResults[0]['result']));
    }

    public function test_package_tool_that_needs_confirmation_pauses_the_run_and_runs_after_approval(): void
    {
        config()->set('ai-agent.runtime.default', 'laravel_ai');
        $this->registerPackageTool(new RecordingAgentTool('refund_order', confirm: true));
        EngineAgent::fake([
            new \Laravel\Ai\Responses\Data\ToolCall('call-3', 'refund_order', ['order' => 7]),
            'Refund issued.',
        ]);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'confirm-session',
            'user_id' => '9',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);
        $this->handle(new RunAgentJob($run->id, 'Refund order 7', 'confirm-session', '9'));

        $run->refresh();
        $approvalKey = $run->final_response['data']['approval_key'];
        $approval = app(\LaravelAIEngine\Repositories\ProviderToolApprovalRepository::class)->findByKeyOrFail($approvalKey);

        $this->assertSame(AIAgentRun::STATUS_WAITING_APPROVAL, $run->status);
        $this->assertSame('refund_order', $approval->tool_name);
        $this->assertSame('Refunds need approval.', $approval->tool_payload['decision']['reason'] ?? $run->final_response['data']['pending_approvals'][0]['reason']);
        $this->assertSame([], RecordingAgentTool::$calls, 'nothing runs before approval');

        app(AgentRunRuntimeControlService::class)->resume($run, ['approval_key' => $approvalKey, 'actor_id' => 'admin-1']);

        // The SDK's fakes do not execute approved tools on resume (only a real
        // provider does), so tool execution after approval is covered by the
        // live-provider smoke test; here the resume must complete the run.
        $run->refresh();
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('Refund issued.', $run->final_response['message']);
    }

    private function registerPackageTool(RecordingAgentTool $tool): void
    {
        app(ToolRegistry::class)->register($tool->getName(), $tool);
        config()->set('ai-agent.runtime.laravel_ai.package_tools', [$tool->getName()]);
    }

    private function invocation(string $message, bool $stream = true, ?string $conversationId = null): LaravelAiInvocation
    {
        return new LaravelAiInvocation(
            message: $message,
            sessionId: 'sdk-session',
            userId: 9,
            conversationId: $conversationId,
            stream: $stream
        );
    }

    private function handle(RunAgentJob $job): void
    {
        $job->handle(
            $this->app->make(AgentRuntimeContract::class),
            app(AgentRunRepository::class),
            app(AgentRunStepRepository::class),
            app(AgentRunSafetyService::class)
        );
    }
}
