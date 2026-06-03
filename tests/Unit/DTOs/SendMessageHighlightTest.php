<?php

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\SendMessageDTO;
use LaravelAIEngine\Tests\TestCase;

class SendMessageHighlightTest extends TestCase
{
    public function test_composed_message_returns_raw_message_without_highlight(): void
    {
        $dto = new SendMessageDTO(message: 'What does this mean?', sessionId: 's-1');

        $this->assertSame('What does this mean?', $dto->composedMessage());
    }

    public function test_composed_message_prepends_highlight_quote(): void
    {
        $dto = new SendMessageDTO(
            message: 'Explain this part',
            sessionId: 's-1',
            highlightContext: 'the mitochondria is the powerhouse',
        );

        $composed = $dto->composedMessage();

        $this->assertStringContainsString('the mitochondria is the powerhouse', $composed);
        $this->assertStringContainsString('Explain this part', $composed);
        $this->assertStringStartsWith('Regarding this selected text:', $composed);
    }

    public function test_blank_highlight_is_ignored(): void
    {
        $dto = new SendMessageDTO(
            message: 'Hello',
            sessionId: 's-1',
            highlightContext: '   ',
        );

        $this->assertSame('Hello', $dto->composedMessage());
    }
}
