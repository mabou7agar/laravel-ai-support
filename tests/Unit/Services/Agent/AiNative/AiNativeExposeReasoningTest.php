<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeConfirmationPresenter;
use LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseFactory;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseParser;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeStateStore;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class AiNativeExposeReasoningTest extends UnitTestCase
{
    private function factory(): AiNativeResponseFactory
    {
        $stateStore = new AiNativeStateStore();

        return new AiNativeResponseFactory(
            $stateStore,
            new ToolRegistry(),
            new AiNativeConfirmationPresenter()
        );
    }

    private function context(): UnifiedActionContext
    {
        return new UnifiedActionContext(sessionId: 'sess-reasoning');
    }

    public function test_parser_preserves_reasoning_key_on_normal_branch(): void
    {
        $plan = (new AiNativeResponseParser())->parse('{"action":"final","message":"x","reasoning":"y"}');

        $this->assertSame('final', $plan['action']);
        $this->assertSame('y', $plan['reasoning']);
    }

    public function test_parser_preserves_reasoning_key_on_unknown_action_branch(): void
    {
        $plan = (new AiNativeResponseParser())->parse('{"action":"do_thing","tool":"find_customer","reasoning":"looking up"}');

        $this->assertSame('tool_call', $plan['action']);
        $this->assertSame('looking up', $plan['reasoning']);
    }

    public function test_factory_copies_reasoning_trace_to_top_level_metadata_in_success(): void
    {
        $response = $this->factory()->final(
            $this->context(),
            ['reasoning_trace' => ['Looking up the customer record', 'Creating the invoice']],
            'Done.'
        );

        $this->assertArrayHasKey('reasoning_trace', $response->metadata);
        $this->assertSame(['Looking up the customer record', 'Creating the invoice'], $response->metadata['reasoning_trace']);
        // Still nested under ai_native too, not replacing it.
        $this->assertArrayHasKey('ai_native', $response->metadata);
    }

    public function test_factory_copies_reasoning_trace_in_needs_user_input(): void
    {
        $response = $this->factory()->needsUserInput(
            $this->context(),
            ['reasoning_trace' => ['Need the customer email']],
            'What is the email?'
        );

        $this->assertSame(['Need the customer email'], $response->metadata['reasoning_trace']);
    }

    public function test_factory_omits_reasoning_trace_when_absent(): void
    {
        $response = $this->factory()->final($this->context(), [], 'Done.');

        $this->assertArrayNotHasKey('reasoning_trace', $response->metadata);
    }

    public function test_factory_filters_empty_reasoning_entries(): void
    {
        $response = $this->factory()->final(
            $this->context(),
            ['reasoning_trace' => ['  ', 'Real one', 123, '']],
            'Done.'
        );

        $this->assertSame(['Real one'], $response->metadata['reasoning_trace']);
    }

    public function test_prompt_builder_includes_reasoning_instruction_when_flag_on(): void
    {
        config()->set('ai-agent.ai_native.expose_reasoning', true);

        $prompt = $this->promptBuilder()->build('hi', $this->context(), []);

        $this->assertStringContainsString('"reasoning":"<one short sentence>"', $prompt);
    }

    public function test_prompt_builder_omits_reasoning_instruction_when_flag_off(): void
    {
        config()->set('ai-agent.ai_native.expose_reasoning', false);

        $prompt = $this->promptBuilder()->build('hi', $this->context(), []);

        $this->assertStringNotContainsString('"reasoning":"<one short sentence>"', $prompt);
    }

    private function promptBuilder(): AiNativePromptBuilder
    {
        return new AiNativePromptBuilder(
            new ToolRegistry(),
            app(AgentSkillRegistry::class)
        );
    }
}
