<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\Actions\ActionReplyGeneratorService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class ActionReplyGeneratorServiceTest extends TestCase
{
    public function test_enhancer_can_generate_reply_before_ai_fallback(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldNotReceive('generate');

        $reply = (new ActionReplyGeneratorService($ai))->generate($this->missingCustomerAction(), [
            'enhancer' => function (string $prompt, array $context): array {
                $this->assertStringContainsString('Action facts JSON', $prompt);
                $this->assertStringNotContainsString('Fallback meaning', $prompt);
                $this->assertSame('action_reply', $context['style']);
                $this->assertContains('Smoke Customer', $context['preserve_terms']);
                $this->assertArrayNotHasKey('fallback', $context);

                return [
                    'text' => 'I have the customer name "Smoke Customer". Please send the customer email.',
                    'provider' => 'mcp',
                    'metadata' => ['request_id' => 'mcp-1'],
                ];
            },
        ]);

        $this->assertSame('I have the customer name "Smoke Customer". Please send the customer email.', $reply['text']);
        $this->assertTrue($reply['metadata']['action_reply_generated']);
        $this->assertSame('mcp', $reply['metadata']['action_reply_provider']);
        $this->assertSame('mcp-1', $reply['metadata']['request_id']);
    }

    public function test_configured_ai_generates_reply_and_preserves_quoted_relation_name(): void
    {
        config()->set('ai-agent.action_reply.ai_enabled', true);
        config()->set('ai-engine.default', 'openai');
        config()->set('ai-engine.engines.openai.api_key', 'test-key');

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(fn (AIRequest $request): bool => str_contains($request->getPrompt(), 'AGENT_ACTION_REPLY')
                && str_contains($request->getPrompt(), 'customer email')
                && str_contains($request->getPrompt(), 'Smoke Customer')
                && str_contains($request->getPrompt(), 'Use short bullet points when the reply includes several fields')
                && str_contains($request->getPrompt(), 'Before asking the user to create/confirm the final action')
                && str_contains($request->getPrompt(), 'choose wording freely')))
            ->andReturn(AIResponse::success(
                'I have the customer name "Smoke Customer." What email should I use for this customer?',
                'openai',
                'gpt-4o'
            ));

        $reply = (new ActionReplyGeneratorService($ai))->generate($this->missingCustomerAction());

        $this->assertSame('I have the customer name "Smoke Customer". What email should I use for this customer?', $reply['text']);
        $this->assertTrue($reply['metadata']['action_reply_generated']);
        $this->assertSame('ai', $reply['metadata']['action_reply_provider']);
    }

    public function test_configured_enhancer_class_can_generate_reply(): void
    {
        config()->set('ai-agent.action_reply.enhancer', ActionReplyGeneratorTestEnhancer::class);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldNotReceive('generate');

        $reply = (new ActionReplyGeneratorService($ai))->generate($this->missingCustomerAction());

        $this->assertSame('Configured enhancer generated the reply for "Smoke Customer".', $reply['text']);
        $this->assertSame('configured-test', $reply['metadata']['action_reply_provider']);
    }

    public function test_emergency_fallback_does_not_compose_action_specific_reply_when_ai_is_disabled(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldNotReceive('generate');

        $reply = (new ActionReplyGeneratorService($ai))->generate($this->missingCustomerAction(), [
            'ai_enabled' => false,
        ]);

        $this->assertSame(
            'I need a little more information to continue.',
            $reply['text']
        );
        $this->assertFalse($reply['metadata']['action_reply_generated']);
        $this->assertSame('emergency_fallback', $reply['metadata']['action_reply_provider']);
    }

    private function missingCustomerAction(): array
    {
        return [
            'success' => false,
            'message' => 'Action requires more input.',
            'needs_user_input' => true,
            'missing_fields' => ['items'],
            'next_options' => [
                [
                    'type' => 'relation_create_confirmation',
                    'relation_type' => 'customer',
                    'label' => 'Smoke Customer',
                    'required_fields' => ['customer_email'],
                ],
            ],
        ];
    }
}

class ActionReplyGeneratorTestEnhancer
{
    public function __invoke(string $prompt, array $context): array
    {
        return [
            'text' => 'Configured enhancer generated the reply for "' . $context['brief']['relation_next_steps'][0]['label'] . '".',
            'provider' => 'configured-test',
        ];
    }
}
