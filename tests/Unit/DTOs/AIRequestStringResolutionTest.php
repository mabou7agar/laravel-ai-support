<?php

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Tests\UnitTestCase;

class AIRequestStringResolutionTest extends UnitTestCase
{
    public function test_constructor_accepts_string_engine_and_model(): void
    {
        $request = new AIRequest(
            prompt: 'Hello',
            engine: 'openai',
            model: 'gpt-4o-mini'
        );

        $this->assertSame('openai', $request->getEngine()->value);
        $this->assertSame('gpt-4o-mini', $request->getModel()->value);
    }

    public function test_make_accepts_string_engine_and_model(): void
    {
        $request = AIRequest::make('Hello', 'openai', 'gpt-4o-mini');

        $this->assertSame('openai', $request->getEngine()->value);
        $this->assertSame('gpt-4o-mini', $request->getModel()->value);
    }

    public function test_for_user_and_with_context_keep_conversation_id(): void
    {
        $request = new AIRequest(
            prompt: 'Hello',
            engine: 'openai',
            model: 'gpt-4o-mini',
            conversationId: 'conv_123',
            context: ['a' => 'b']
        );

        $forUser = $request->forUser('user_1');
        $withContext = $request->withContext(['c' => 'd']);

        $this->assertSame('conv_123', $forUser->getConversationId());
        $this->assertSame('conv_123', $withContext->getConversationId());
        $this->assertSame(['a' => 'b', 'c' => 'd'], $withContext->getContext());
    }
}
