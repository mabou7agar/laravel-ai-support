<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Memory;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryExtractor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class ConversationMemoryExtractorTest extends UnitTestCase
{
    public function test_ai_extractor_detects_memory_without_language_specific_patterns(): void
    {
        config()->set('ai-agent.conversation_memory.extractor', 'ai');
        config()->set('ai-agent.conversation_memory.max_extraction_input_chars', 2000);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($request): bool {
                return str_contains($request->getPrompt(), 'أحب الردود المختصرة باللغة العربية')
                    && str_contains($request->getPrompt(), 'Return JSON array only')
                    && strlen($request->getPrompt()) < 2500;
            }))
            ->andReturn(AIResponse::success(json_encode([
                [
                    'namespace' => 'preferences',
                    'key' => 'reply_style',
                    'value' => 'short Arabic replies',
                    'summary' => 'User prefers short Arabic replies.',
                    'confidence' => 0.9,
                ],
            ]), (string) config('ai-engine.default'), (string) config('ai-engine.default_model')));

        $extractor = new ConversationMemoryExtractor(
            app(ConversationMemoryPolicy::class),
            $ai
        );

        $items = $extractor->extract([
            ['role' => 'user', 'content' => 'أحب الردود المختصرة باللغة العربية في هذا المشروع.'],
        ], [
            'user_id' => '7',
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
            'session_id' => 'session-a',
        ]);

        $this->assertNotEmpty($items);
        $this->assertStringContainsString('Arabic', $items[0]->summary);
        $this->assertSame('7', $items[0]->userId);
        $this->assertSame('preferences', $items[0]->namespace);
    }

    public function test_extractor_can_be_disabled_without_calling_ai(): void
    {
        config()->set('ai-agent.conversation_memory.extractor', 'none');

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldNotReceive('generate');

        $extractor = new ConversationMemoryExtractor(app(ConversationMemoryPolicy::class), $ai);

        $this->assertSame([], $extractor->extract([
            ['role' => 'user', 'content' => 'أي شيء مهم'],
        ], ['user_id' => '7']));
    }

    public function test_ai_extractor_filters_transient_application_records(): void
    {
        config()->set('ai-agent.conversation_memory.extractor', 'ai');
        config()->set('ai-agent.conversation_memory.rejected_terms', ['line item']);
        config()->set('ai-agent.conversation_memory.rejected_key_suffixes', ['_name', '_email']);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(json_encode([
                [
                    'namespace' => 'user_preferences',
                    'key' => 'customer_name',
                    'value' => 'Codex Complex Live',
                    'summary' => 'User provided customer name Codex Complex Live for the current invoice.',
                    'confidence' => 0.9,
                ],
                [
                    'namespace' => 'preferences',
                    'key' => 'reply_style',
                    'value' => 'concise summaries',
                    'summary' => 'User prefers concise summaries.',
                    'confidence' => 0.9,
                ],
            ]), (string) config('ai-engine.default'), (string) config('ai-engine.default_model')));

        $items = (new ConversationMemoryExtractor(app(ConversationMemoryPolicy::class), $ai))->extract([
            ['role' => 'user', 'content' => 'Create an invoice for Codex Complex Live. Also keep replies concise.'],
        ], ['user_id' => '7']);

        $this->assertCount(1, $items);
        $this->assertSame('reply_style', $items[0]->key);
        $this->assertSame('User prefers concise summaries.', $items[0]->summary);
    }
}
