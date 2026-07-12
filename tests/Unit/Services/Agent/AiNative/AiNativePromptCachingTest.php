<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Drivers\OpenRouter\OpenRouterEngineDriver;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AgentContextSnapshotBuilder;
use LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;
use ReflectionMethod;

/**
 * Guards the prompt-caching seam for orchestration calls: the prompt splits
 * into a byte-stable cacheable prefix + volatile body, and the OpenRouter
 * driver marks the system block cache_control for Anthropic models (OpenRouter
 * forwards block-level cache_control to Anthropic; ~0.1x input on reads).
 */
class AiNativePromptCachingTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function builder(): AiNativePromptBuilder
    {
        $tools = Mockery::mock(ToolRegistry::class);
        $tools->shouldReceive('all')->andReturn([]);
        $tools->shouldReceive('has')->andReturn(false);

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $snapshots = Mockery::mock(AgentContextSnapshotBuilder::class);
        $snapshots->shouldReceive('build')->andReturn([]);

        return new AiNativePromptBuilder($tools, $skills, $snapshots);
    }

    public function test_deterministic_tool_selection_caches_skills_and_tools_in_the_prefix(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.strategy', 'all');

        $builder = $this->builder();
        $context = new UnifiedActionContext(sessionId: 't');
        $parts = $builder->buildParts('remove the stats section', $context, []);

        self::assertStringContainsString('Available skills JSON:', $parts['system']);
        self::assertStringContainsString('Available tools JSON:', $parts['system']);
        self::assertStringStartsWith('Recent conversation JSON:', $parts['body']);
        // Byte-equality guarantee: system + separator + body === build().
        self::assertSame(
            $builder->build('remove the stats section', $context, []),
            $parts['system'] . "\n\n" . $parts['body'],
        );
    }

    public function test_message_dependent_tool_selection_caches_only_the_instruction_prefix(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.strategy', 'keyword');

        $builder = $this->builder();
        $context = new UnifiedActionContext(sessionId: 't');
        $parts = $builder->buildParts('remove the stats section', $context, []);

        self::assertStringNotContainsString('Available skills JSON:', $parts['system']);
        self::assertStringStartsWith('Available skills JSON:', $parts['body']);
        self::assertSame(
            $builder->build('remove the stats section', $context, []),
            $parts['system'] . "\n\n" . $parts['body'],
        );
    }

    private function openRouterMessages(string $model): array
    {
        $driver = (new \ReflectionClass(OpenRouterEngineDriver::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(OpenRouterEngineDriver::class, 'buildMessages');
        $m->setAccessible(true);

        $request = (new AIRequest(
            prompt: 'User request: remove the stats section',
            engine: 'openrouter',
            model: $model,
        ))->withSystemPrompt('AI_NATIVE_AGENT_RUNTIME stable instructions and tool docs');

        return $m->invoke($driver, $request);
    }

    public function test_openrouter_marks_system_block_cacheable_for_anthropic_models(): void
    {
        config()->set('ai-engine.engines.openrouter.prompt_caching', true);

        $messages = $this->openRouterMessages('anthropic/claude-sonnet-5');

        self::assertSame('system', $messages[0]['role']);
        self::assertIsArray($messages[0]['content']);
        self::assertSame(['type' => 'ephemeral'], $messages[0]['content'][0]['cache_control']);
        self::assertStringContainsString('stable instructions', $messages[0]['content'][0]['text']);
    }

    public function test_openrouter_keeps_plain_system_string_for_non_anthropic_models(): void
    {
        config()->set('ai-engine.engines.openrouter.prompt_caching', true);

        $messages = $this->openRouterMessages('openai/gpt-5.1');

        self::assertSame('system', $messages[0]['role']);
        self::assertIsString($messages[0]['content']);
    }

    public function test_openrouter_caching_can_be_disabled_via_config(): void
    {
        config()->set('ai-engine.engines.openrouter.prompt_caching', false);

        $messages = $this->openRouterMessages('anthropic/claude-sonnet-5');

        self::assertIsString($messages[0]['content']);
    }
}
