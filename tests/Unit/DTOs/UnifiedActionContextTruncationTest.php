<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Tests\TestCase;

class UnifiedActionContextTruncationTest extends TestCase
{
    public function test_over_limit_message_is_flagged_and_recorded_in_metadata(): void
    {
        config()->set('ai-agent.context_compaction.max_message_chars', 200);

        $context = new UnifiedActionContext('sess-trunc', 'user-1');

        $long = str_repeat('a', 500);
        $context->addUserMessage($long);

        $entry = $context->conversationHistory[0];

        $this->assertTrue($entry['is_truncated']);
        $this->assertSame(500, $entry['original_length']);
        $this->assertStringEndsWith('...', $entry['content']);
        $this->assertLessThan(500, mb_strlen($entry['content']));

        $this->assertArrayHasKey('truncation_warnings', $context->metadata);
        $this->assertCount(1, $context->metadata['truncation_warnings']);
        $this->assertSame('user', $context->metadata['truncation_warnings'][0]['role']);
        $this->assertSame(500, $context->metadata['truncation_warnings'][0]['original_length']);
        $this->assertSame(200, $context->metadata['truncation_warnings'][0]['limit']);
    }

    public function test_within_limit_message_is_not_flagged_and_records_no_warning(): void
    {
        config()->set('ai-agent.context_compaction.max_message_chars', 2000);

        $context = new UnifiedActionContext('sess-ok', 'user-1');

        $context->addAssistantMessage('short reply');

        $entry = $context->conversationHistory[0];

        $this->assertArrayNotHasKey('is_truncated', $entry);
        $this->assertArrayNotHasKey('truncation_warnings', $context->metadata);
    }
}
