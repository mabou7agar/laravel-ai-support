<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Drivers\Ollama\OllamaEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Tests\UnitTestCase;

class OllamaEngineDriverTest extends UnitTestCase
{
    public function test_ollama_uses_explicit_model_and_system_prompt(): void
    {
        config()->set('ai-engine.engines.ollama.models.gemma3:4b', ['enabled' => true, 'credit_index' => 0.0]);

        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'model' => 'gemma3:4b',
                'response' => 'package ollama gemma3 works',
                'done' => true,
            ], JSON_THROW_ON_ERROR)),
        ]));
        $handler->push(Middleware::history($history));

        $driver = new OllamaEngineDriver(
            ['base_url' => 'http://localhost:11434', 'default_model' => 'llama2'],
            new Client(['handler' => $handler, 'base_uri' => 'http://localhost:11434'])
        );

        $response = $driver->generate(new AIRequest(
            prompt: 'Reply with a short status.',
            engine: EngineEnum::Ollama,
            model: 'gemma3:4b',
            systemPrompt: 'Only answer with the requested status.'
        ));

        $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('package ollama gemma3 works', $response->getContent());
        $this->assertSame('gemma3:4b', $payload['model']);
        $this->assertSame('Only answer with the requested status.', $payload['system']);
    }
}
