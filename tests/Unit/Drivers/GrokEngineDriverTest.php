<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Drivers\Grok\GrokEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;

class GrokEngineDriverTest extends UnitTestCase
{
    public function test_grok_generates_text_and_maps_usage_into_ai_response(): void
    {
        Http::fake([
            'https://api.x.ai/v1/chat/completions' => Http::response([
                'id' => 'xai-123',
                'model' => 'grok-4.1',
                'choices' => [[
                    'message' => ['content' => 'Hello from Grok.'],
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7, 'total_tokens' => 12],
            ]),
        ]);

        $driver = new GrokEngineDriver(['api_key' => 'xai-key']);
        $response = $driver->generateText(new AIRequest(
            prompt: 'Say hello.',
            engine: EngineEnum::Xai,
            model: EntityEnum::GROK_4_1,
            systemPrompt: 'You are concise.'
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Hello from Grok.', $response->getContent());
        $this->assertSame('xai', $response->getMetadata()['provider']);
        $this->assertSame('grok-4.1', $response->getMetadata()['model']);
        $this->assertSame(12, $response->getMetadata()['usage']['total_tokens']);
        $this->assertSame(12, $response->getTokensUsed());

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.x.ai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer xai-key')
                && $payload['model'] === 'grok-4.1'
                && $payload['messages'][0]['role'] === 'system'
                && $payload['messages'][0]['content'] === 'You are concise.'
                && $payload['messages'][1]['role'] === 'user'
                && $payload['messages'][1]['content'] === 'Say hello.';
        });
    }

    public function test_grok_returns_error_response_on_api_failure(): void
    {
        Http::fake([
            'https://api.x.ai/v1/chat/completions' => Http::response([
                'error' => ['message' => 'invalid model'],
            ], 400),
        ]);

        $driver = new GrokEngineDriver(['api_key' => 'xai-key']);
        $response = $driver->generateText(new AIRequest(
            prompt: 'Say hello.',
            engine: EngineEnum::Xai,
            model: EntityEnum::GROK_4
        ));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame('invalid model', $response->getError());
    }

    public function test_grok_engine_enum_maps_to_driver(): void
    {
        $this->assertSame(GrokEngineDriver::class, EngineEnum::Xai->driverClass());
        $this->assertSame(EngineEnum::Xai, EntityEnum::from(EntityEnum::GROK_4_1)->engine());
        $this->assertSame('text', EntityEnum::from(EntityEnum::GROK_4)->getContentType());
    }
}
