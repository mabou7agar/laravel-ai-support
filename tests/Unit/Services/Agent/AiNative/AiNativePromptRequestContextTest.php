<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use Illuminate\Support\Carbon;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * The planner cannot resolve "due next Friday", answer in the user's locale or reason about
 * the page the user is on unless the prompt carries those facts.
 */
class AiNativePromptRequestContextTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function builder(): AiNativePromptBuilder
    {
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        return new AiNativePromptBuilder(new ToolRegistry(), $skills);
    }

    private function contextBlock(string $prompt): array
    {
        $this->assertStringContainsString('Request context JSON:', $prompt);
        $after = substr($prompt, strpos($prompt, 'Request context JSON:') + strlen('Request context JSON:'));
        $json = trim(substr($after, 0, strpos($after, 'Context snapshot JSON:')));

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_prompt_carries_date_locale_and_host_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 23:30:00', 'UTC'));

        $context = new UnifiedActionContext('prompt-context', 42, metadata: ['locale' => 'ar']);
        $prompt = $this->builder()->build('create an invoice due next friday', $context, [], [
            'timezone' => 'Africa/Cairo',
            'workspace_id' => 7,
            'user_context' => ['name' => 'Mona', 'role' => 'owner'],
            'workspace_context' => ['name' => 'Acme', 'currency' => 'EGP'],
            'page_context' => 'Viewing invoice #INV-1001',
        ]);

        $block = $this->contextBlock($prompt);

        // 23:30 UTC is already the 16th (a Wednesday) in Cairo.
        $this->assertSame('2026-09-16', $block['today']);
        $this->assertSame('Wednesday', $block['weekday']);
        $this->assertSame('Africa/Cairo', $block['timezone']);
        $this->assertSame('ar', $block['locale']);
        $this->assertSame(42, $block['user_id']);
        $this->assertSame(7, $block['workspace_id']);
        $this->assertSame(['name' => 'Mona', 'role' => 'owner'], $block['user']);
        $this->assertSame(['name' => 'Acme', 'currency' => 'EGP'], $block['workspace']);
        $this->assertSame('Viewing invoice #INV-1001', $block['page']);

        // Per-request facts stay out of the cacheable instruction prefix.
        $this->assertGreaterThan(strpos($prompt, 'Recent conversation JSON:'), strpos($prompt, 'Request context JSON:'));
    }

    public function test_date_is_present_without_any_host_context_and_large_values_are_capped(): void
    {
        config()->set('ai-agent.ai_native.prompt_context.max_bytes_per_section', 300);
        Carbon::setTestNow(Carbon::parse('2026-01-05 10:00:00', 'UTC'));

        $block = $this->contextBlock($this->builder()->build('hi', new UnifiedActionContext('prompt-context-min'), [], [
            'page_context' => str_repeat('x', 5000),
        ]));

        $this->assertSame('2026-01-05', $block['today']);
        $this->assertArrayNotHasKey('user', $block);
        $this->assertLessThan(400, strlen($block['page']));
    }

    public function test_block_can_be_disabled(): void
    {
        $prompt = $this->builder()->build('hi', new UnifiedActionContext('prompt-context-off'), [], ['prompt_context' => false]);
        $this->assertStringNotContainsString('Request context JSON:', $prompt);

        config()->set('ai-agent.ai_native.prompt_context.enabled', false);
        $prompt = $this->builder()->build('hi', new UnifiedActionContext('prompt-context-off-config'), []);
        $this->assertStringNotContainsString('Request context JSON:', $prompt);
    }
}
