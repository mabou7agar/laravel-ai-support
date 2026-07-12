<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseFactory;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolCallActionHandler;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guards salvageTurnSuccess(): when a trailing tool call dies on validation,
 * a tool that ALREADY SUCCEEDED this turn is the user's outcome and must be
 * returned as the final response (with skipped_followup metadata) — never
 * buried under a raw validator string. Outcomes from BEFORE this turn, ask
 * outcomes, and the failing tool's own outcomes must not be salvaged.
 */
class AiNativeSalvageTurnSuccessTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function salvage(array &$state, string $failedTool = 'apply_operations'): ?AgentResponse
    {
        $handler = (new ReflectionClass(AiNativeToolCallActionHandler::class))->newInstanceWithoutConstructor();

        $responses = Mockery::mock(AiNativeResponseFactory::class);
        $responses->shouldReceive('final')
            ->andReturnUsing(static fn (UnifiedActionContext $ctx, array $st, string $message, array $extra = []): AgentResponse
                => AgentResponse::success($message, $extra));
        $prop = new ReflectionClass(AiNativeToolCallActionHandler::class);
        $p = $prop->getProperty('responses');
        $p->setAccessible(true);
        $p->setValue($handler, $responses);

        $m = new ReflectionMethod(AiNativeToolCallActionHandler::class, 'salvageTurnSuccess');
        $m->setAccessible(true);

        $args = [new UnifiedActionContext(sessionId: 't'), &$state, $failedTool, ['Missing required parameter: preview_id']];

        return $m->invokeArgs($handler, $args);
    }

    public function test_success_from_this_turn_is_salvaged_with_skipped_followup_metadata(): void
    {
        $state = [
            'outcomes_at_turn_start' => 1,
            'recent_outcomes' => [
                ['tool' => 'old_tool', 'success' => true],   // previous turn — ignored
                ['tool' => 'reorder_remove_sections', 'success' => true, 'label' => 'stat counter'],
            ],
        ];

        $response = $this->salvage($state);

        self::assertInstanceOf(AgentResponse::class, $response);
        self::assertStringContainsString('stat counter', $response->message);
        self::assertSame('apply_operations', $response->data['skipped_followup']['tool']);
        self::assertSame(['Missing required parameter: preview_id'], $response->data['skipped_followup']['validation_errors']);
        self::assertSame('reorder_remove_sections', $response->data['last_tool_outcome']['tool']);
    }

    public function test_success_from_a_previous_turn_is_not_salvaged(): void
    {
        $state = [
            'outcomes_at_turn_start' => 1,
            'recent_outcomes' => [
                ['tool' => 'reorder_remove_sections', 'success' => true],
            ],
        ];

        self::assertNull($this->salvage($state));
    }

    public function test_ask_outcomes_and_failures_are_not_salvaged(): void
    {
        $state = [
            'outcomes_at_turn_start' => 0,
            'recent_outcomes' => [
                ['tool' => 'lookup', 'success' => true, 'needs_user_input' => true],
                ['tool' => 'compose', 'success' => false],
            ],
        ];

        self::assertNull($this->salvage($state));
    }

    public function test_the_failing_tools_own_outcome_is_not_salvaged(): void
    {
        $state = [
            'outcomes_at_turn_start' => 0,
            'recent_outcomes' => [
                ['tool' => 'apply_operations', 'success' => true],
            ],
        ];

        self::assertNull($this->salvage($state, 'apply_operations'));
    }

    public function test_most_recent_success_wins_when_several_succeeded(): void
    {
        $state = [
            'outcomes_at_turn_start' => 0,
            'recent_outcomes' => [
                ['tool' => 'first_tool', 'success' => true, 'label' => 'first'],
                ['tool' => 'second_tool', 'success' => true, 'label' => 'second'],
            ],
        ];

        $response = $this->salvage($state);

        self::assertSame('second_tool', $response->data['last_tool_outcome']['tool']);
    }
}
