<?php

namespace LaravelAIEngine\Tests\Unit\Drivers\NvidiaNim;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\Drivers\NvidiaNim\NvidiaNimEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;

class NvidiaNimEngineDriverTest extends UnitTestCase
{
    public function test_driver_implements_interface(): void
    {
        $driver = $this->makeDriver([new Response(200, [], '{}')]);

        $this->assertInstanceOf(EngineDriverInterface::class, $driver);
        $this->assertSame(EngineEnum::NVIDIA_NIM, $driver->getEngine()->value);
        $this->assertTrue($driver->supports('text'));
        $this->assertTrue($driver->supports('streaming'));
    }

    public function test_generate_text_posts_openai_compatible_payload(): void
    {
        $history = [];
        $driver = $this->makeDriver([
            new Response(200, [], json_encode([
                'id' => 'chatcmpl-test',
                'choices' => [
                    [
                        'message' => ['content' => 'NIM response'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 5,
                    'completion_tokens' => 3,
                    'total_tokens' => 8,
                ],
            ])),
        ], $history);

        $response = $driver->generate(new AIRequest(
            prompt: 'Say hello',
            engine: EngineEnum::NVIDIA_NIM,
            model: EntityEnum::NVIDIA_NIM_NEMOTRON_70B,
            parameters: ['top_p' => 0.9],
            systemPrompt: 'You are concise.',
            maxTokens: 64,
            temperature: 0.2
        ));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('NIM response', $response->getContent());
        $this->assertSame('chatcmpl-test', $response->getRequestId());

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('/v1/chat/completions', $history[0]['request']->getUri()->getPath());
        $this->assertSame(EntityEnum::NVIDIA_NIM_NEMOTRON_70B, $payload['model']);
        $this->assertFalse($payload['stream']);
        $this->assertSame(64, $payload['max_tokens']);
        $this->assertSame(0.2, $payload['temperature']);
        $this->assertSame(0.9, $payload['top_p']);
        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertSame('user', $payload['messages'][1]['role']);
    }

    public function test_stream_yields_server_sent_event_content(): void
    {
        $driver = $this->makeDriver([
            new Response(200, [], implode("\n", [
                'data: {"choices":[{"delta":{"content":"Hel"}}]}',
                'data: {"choices":[{"delta":{"content":"lo"}}]}',
                'data: [DONE]',
                '',
            ])),
        ]);

        $chunks = iterator_to_array($driver->stream(new AIRequest(
            prompt: 'Say hello',
            engine: EngineEnum::NVIDIA_NIM,
            model: EntityEnum::NVIDIA_NIM_NEMOTRON_70B
        )));

        $this->assertSame(['Hel', 'lo'], $chunks);
    }

    public function test_get_available_models_falls_back_to_configured_models(): void
    {
        config()->set('ai-engine.engines.nvidia_nim.models', [
            'custom/model' => ['enabled' => true],
        ]);

        $driver = $this->makeDriver([
            new Response(500, [], 'server error'),
        ]);

        $this->assertSame([
            'custom/model' => ['enabled' => true],
        ], $driver->getAvailableModels());
    }

    private function makeDriver(array $responses, array &$history = []): NvidiaNimEngineDriver
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://integrate.api.nvidia.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer test-key',
                'Content-Type' => 'application/json',
            ],
        ]);

        return new NvidiaNimEngineDriver([
            'api_key' => 'test-key',
            'base_url' => 'https://integrate.api.nvidia.com/v1',
            'timeout' => 30,
        ], $client);
    }
}
