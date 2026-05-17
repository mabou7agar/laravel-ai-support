<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentChatResponsePresentationApiTest extends TestCase
{
    public function test_agent_chat_api_returns_response_points_and_suggestions(): void
    {
        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                return ($args[0] ?? null) === 'Draft an invoice summary'
                    && ($args[1] ?? null) === 'api-presentation'
                    && ($args[2] ?? null) === 'openai'
                    && ($args[3] ?? null) === 'gpt-4o-mini'
                    && ($args[4] ?? null) === false
                    && ($args[5] ?? null) === true
                    && ($args[6] ?? null) === false
                    && is_array($args[10] ?? null)
                    && (($args[11]['response_points_format'] ?? null) === 'array')
                    && (($args[11]['response_suggestions'] ?? null) === true)
                    && (($args[11]['response_suggestion_limit'] ?? null) === 3);
            })
            ->andReturn(AIResponse::success(
                content: 'Summary ready.',
                engine: 'openai',
                model: 'gpt-4o-mini',
                metadata: [
                    'response_points_format' => 'array',
                    'response_points' => [[
                        'index' => 1,
                        'marker' => '-',
                        'text' => 'Create invoice from the provided data',
                        'raw' => '- Create invoice from the provided data',
                    ]],
                    'response_points_count' => 1,
                    'response_text_without_points' => 'Summary ready.',
                    'suggestions' => [[
                        'id' => 'create_invoice',
                        'type' => 'action',
                        'label' => 'Create invoice',
                        'confidence' => 90,
                    ]],
                    'suggestions_count' => 1,
                ]
            ));

        $this->app->instance(ChatService::class, $chat);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Draft an invoice summary',
            'session_id' => 'api-presentation',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'memory' => false,
            'actions' => true,
            'use_rag' => false,
            'response_points_format' => 'array',
            'response_suggestions' => true,
            'response_suggestion_limit' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.response', 'Summary ready.')
            ->assertJsonPath('data.response_points_format', 'array')
            ->assertJsonPath('data.response_points.0.text', 'Create invoice from the provided data')
            ->assertJsonPath('data.response_text_without_points', 'Summary ready.')
            ->assertJsonPath('data.suggestions.0.id', 'create_invoice')
            ->assertJsonPath('data.suggestions.0.type', 'action')
            ->assertJsonPath('data.session_id', 'api-presentation');
    }

    public function test_agent_chat_api_accepts_supported_non_default_engines(): void
    {
        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->withArgs(function (...$args): bool {
                return ($args[0] ?? null) === 'Route through OpenRouter'
                    && ($args[2] ?? null) === 'openrouter'
                    && ($args[3] ?? null) === 'openai/gpt-4o-mini';
            })
            ->andReturn(AIResponse::success('ok', 'openrouter', 'openai/gpt-4o-mini'));

        $this->app->instance(ChatService::class, $chat);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Route through OpenRouter',
            'session_id' => 'api-openrouter',
            'engine' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'memory' => false,
            'actions' => false,
            'use_rag' => false,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.response', 'ok');
    }
}
