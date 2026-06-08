<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\SubAgents;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\SubAgents\AiNativeSubAgentHandler;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * The domain-agent handler runs the real AiNative planner (NL -> tool call with params),
 * scoped to ONLY the sub-agent's declared tools. The engine is mocked, so this is deterministic.
 */
class AiNativeSubAgentHandlerTest extends TestCase
{
    private function tools(): ToolRegistry
    {
        $registry = new ToolRegistry();
        $registry->register('echo_tool', new SubAgentEchoTool());
        $registry->register('secret_tool', new SubAgentEchoTool('secret_tool'));

        return $registry;
    }

    private function registry(array $tools = ['echo_tool']): SubAgentRegistry
    {
        return new SubAgentRegistry(app(), [
            'demo' => [
                'enabled' => true,
                'name' => 'Demo agent',
                'description' => 'Handles demo tasks.',
                'handler' => 'ai_native',
                'tools' => $tools,
            ],
        ]);
    }

    private function handler(ToolRegistry $tools, SubAgentRegistry $registry, AIEngineService $ai): AiNativeSubAgentHandler
    {
        return new AiNativeSubAgentHandler($tools, $registry, $ai, app(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class), app(\LaravelAIEngine\Services\Agent\IntentSignalService::class));
    }

    private function task(): SubAgentTask
    {
        return new SubAgentTask(id: 't1', agentId: 'demo', name: 'Demo agent', objective: 'echo the word hi');
    }

    private function aiReturning(array ...$plans): AIEngineService
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->andReturn(...array_map(
            static fn (array $p): AIResponse => AIResponse::success(json_encode($p), 'openai', 'gpt-4o-mini'),
            $plans
        ));

        return $ai;
    }

    public function test_domain_agent_plans_and_executes_a_scoped_tool(): void
    {
        SubAgentEchoTool::$calls = [];
        $ai = $this->aiReturning(
            ['action' => 'tool_call', 'tool' => 'echo_tool', 'arguments' => ['text' => 'hi']],
            ['action' => 'final', 'message' => 'Echoed: hi'],
        );

        $result = $this->handler($this->tools(), $this->registry(), $ai)->handle($this->task(), new UnifiedActionContext('sa'));

        $this->assertTrue($result->success, (string) $result->message);
        $this->assertSame('Echoed: hi', $result->message);
        $this->assertContains('echo_tool', SubAgentEchoTool::$calls, 'the declared tool was actually executed.');
        $this->assertSame(['echo_tool'], $result->metadata['scoped_tools'] ?? null);
    }

    public function test_salvages_a_tool_result_when_the_planner_does_not_converge(): void
    {
        SubAgentEchoTool::$calls = [];
        // The plan calls the tool but never emits a `final`. With a 1-step budget the runtime
        // executes the tool, then exhausts and would normally dead-end on the generic
        // "I need more information to continue." (needsUserInput, no requiredInputs). The handler
        // must instead surface the successful tool result — the convergence safety net.
        $ai = $this->aiReturning(
            ['action' => 'tool_call', 'tool' => 'echo_tool', 'arguments' => ['text' => 'hi']],
        );

        $result = $this->handler($this->tools(), $this->registry(), $ai)
            ->handle($this->task(), new UnifiedActionContext('sa'), [], ['max_steps' => 1]);

        $this->assertTrue($result->success, (string) ($result->error ?? $result->message));
        $this->assertSame('Echoed: hi', $result->message, 'the computed tool result is surfaced, not a dead-end ask.');
        $this->assertContains('echo_tool', SubAgentEchoTool::$calls);
        $this->assertSame('tool_result_fallback', $result->metadata['converged_via'] ?? null);
    }

    public function test_tools_outside_the_declared_set_are_not_callable(): void
    {
        SubAgentEchoTool::$calls = [];
        // Plan tries to call secret_tool (registered globally) but the agent only declared echo_tool.
        $ai = $this->aiReturning(
            ['action' => 'tool_call', 'tool' => 'secret_tool', 'arguments' => ['text' => 'leak']],
            ['action' => 'final', 'message' => 'done'],
        );

        $this->handler($this->tools(), $this->registry(['echo_tool']), $ai)->handle($this->task(), new UnifiedActionContext('sa'));

        $this->assertNotContains('secret_tool', SubAgentEchoTool::$calls, 'a non-declared tool must never run.');
    }

    public function test_no_tools_fails_closed(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->never();

        $result = $this->handler($this->tools(), $this->registry([]), $ai)->handle($this->task(), new UnifiedActionContext('sa'));

        $this->assertFalse($result->success);
        $this->assertStringContainsStringIgnoringCase('no usable tools', (string) $result->error);
    }
}

class SubAgentEchoTool extends AgentTool
{
    /** @var array<int, string> */
    public static array $calls = [];

    public function __construct(private string $name = 'echo_tool')
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Echo the provided text.';
    }

    public function getParameters(): array
    {
        return ['text' => ['type' => 'string', 'required' => true, 'description' => 'Text to echo.']];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        self::$calls[] = $this->name;

        return ActionResult::success('Echoed: ' . ($parameters['text'] ?? ''));
    }
}
