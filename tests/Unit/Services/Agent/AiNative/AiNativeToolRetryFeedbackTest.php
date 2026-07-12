<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeStateStore;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolCallActionHandler;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guards the self-correction loops added for live-probed model fumbles:
 * a hallucinated tool name ("reviews" — a section family, not a tool) gets
 * ONE re-plan with the real tool list as feedback, and a recoverable tool
 * failure's feedback carries the result metadata (e.g. available_sections)
 * so the retry can converge instead of repeating the same mistake.
 */
class AiNativeToolRetryFeedbackTest extends UnitTestCase
{
    private function handler(): AiNativeToolCallActionHandler
    {
        $handler = (new ReflectionClass(AiNativeToolCallActionHandler::class))->newInstanceWithoutConstructor();

        $registry = new ToolRegistry();
        $registry->register('reorder_remove_sections', $this->tool('reorder_remove_sections'));
        $registry->register('set_brand_tokens', $this->tool('set_brand_tokens'));

        foreach (['tools' => $registry, 'stateStore' => new AiNativeStateStore()] as $prop => $value) {
            $p = (new ReflectionClass(AiNativeToolCallActionHandler::class))->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($handler, $value);
        }

        return $handler;
    }

    private function tool(string $name): AgentTool
    {
        return new class($name) extends AgentTool {
            public function __construct(private readonly string $toolName)
            {
            }

            public function getName(): string
            {
                return $this->toolName;
            }

            public function getDescription(): string
            {
                return 'test tool';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('ok');
            }
        };
    }

    public function test_unknown_tool_gets_one_replan_with_the_real_tool_list(): void
    {
        $handler = $this->handler();
        $m = new ReflectionMethod(AiNativeToolCallActionHandler::class, 'shouldRetryUnknownTool');
        $m->setAccessible(true);

        $state = [];
        $args = [&$state, 'reviews'];
        self::assertTrue($m->invokeArgs($handler, $args));

        $feedback = $state['runtime_feedback'][0];
        self::assertSame('unknown_tool', $feedback['reason']);
        self::assertStringContainsString('reorder_remove_sections', $feedback['message']);
        self::assertStringContainsString('set_brand_tokens', $feedback['message']);
        self::assertStringContainsString('NOT tools', $feedback['message']);

        // Second hallucination in the same turn surfaces the failure.
        $args2 = [&$state, 'testimonials'];
        self::assertFalse($m->invokeArgs($handler, $args2));
    }

    public function test_recoverable_failure_feedback_carries_the_result_metadata(): void
    {
        config()->set('ai-agent.ai_native.auto_retry.max', 2);

        $handler = $this->handler();
        $m = new ReflectionMethod(AiNativeToolCallActionHandler::class, 'shouldRetryRecoverableFailure');
        $m->setAccessible(true);

        $result = ActionResult::failure('No section operation could be applied: patch.section_not_found', metadata: [
            'available_sections' => [['id' => 'u-1', 'type' => 'pricing'], ['id' => 'u-2', 'type' => 'reviews']],
        ]);

        $state = [];
        $args = [&$state, 'reorder_remove_sections', $result];
        self::assertTrue($m->invokeArgs($handler, $args));

        $feedback = $state['runtime_feedback'][0];
        self::assertSame('tool_execution_recoverable_failure', $feedback['reason']);
        self::assertSame('pricing', $feedback['details']['available_sections'][0]['type']);
    }

    public function test_retry_budget_is_respected(): void
    {
        config()->set('ai-agent.ai_native.auto_retry.max', 1);

        $handler = $this->handler();
        $m = new ReflectionMethod(AiNativeToolCallActionHandler::class, 'shouldRetryRecoverableFailure');
        $m->setAccessible(true);

        $result = ActionResult::failure('boom');
        $state = [];
        $args = [&$state, 'tool_a', $result];
        self::assertTrue($m->invokeArgs($handler, $args));
        $args2 = [&$state, 'tool_a', $result];
        self::assertFalse($m->invokeArgs($handler, $args2));
    }

    public function test_auto_retry_disabled_by_default(): void
    {
        config()->set('ai-agent.ai_native.auto_retry.max', 0);

        $handler = $this->handler();
        $m = new ReflectionMethod(AiNativeToolCallActionHandler::class, 'shouldRetryRecoverableFailure');
        $m->setAccessible(true);

        $state = [];
        $args = [&$state, 'tool_a', ActionResult::failure('boom')];
        self::assertFalse($m->invokeArgs($handler, $args));
    }
}
