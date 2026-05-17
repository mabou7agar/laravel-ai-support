<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Memory;

use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\DTOs\ConversationMemoryResult;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPromptBuilder;
use LaravelAIEngine\Tests\UnitTestCase;

class ConversationMemoryPromptBuilderTest extends UnitTestCase
{
    public function test_prompt_builder_keeps_only_relevant_memories_under_budget(): void
    {
        config()->set('ai-agent.conversation_memory.max_prompt_chars', 120);
        config()->set('ai-agent.conversation_memory.min_score', 0.45);

        $builder = app(ConversationMemoryPromptBuilder::class);

        $text = $builder->build([
            new ConversationMemoryResult(
                item: ConversationMemoryItem::fromArray([
                    'summary' => 'User prefers Arabic replies.',
                    'key' => 'preferred_language',
                    'namespace' => 'preferences',
                ]),
                score: 0.95,
                reason: 'lexical',
            ),
            new ConversationMemoryResult(
                item: ConversationMemoryItem::fromArray([
                    'summary' => str_repeat('Long irrelevant memory. ', 30),
                    'key' => 'long',
                    'namespace' => 'conversation',
                ]),
                score: 0.3,
                reason: 'low_score',
            ),
        ]);

        $this->assertStringContainsString('Arabic replies', $text);
        $this->assertLessThanOrEqual(120, strlen($text));
        $this->assertStringNotContainsString('Long irrelevant memory', $text);
    }
}
