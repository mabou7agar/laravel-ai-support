<?php

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver;

class AnthropicEngineDriverTest extends TestCase
{
    public function test_generate_text_returns_success_response()
    {
        // Create a mock response
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'msg_123456',
            'content' => [
                ['type' => 'text', 'text' => 'Anthropic test response']
            ],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
            'stop_reason' => 'end_turn',
            'model' => 'claude-3-5-sonnet-20240620'
        ]));
        
        // Create a mock handler and add the response
        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        
        // Create a client with the mock handler
        $mockClient = new Client(['handler' => $handlerStack]);
        
        // Create the driver with the mock client
        $driver = new AnthropicEngineDriver([
            'api_key' => 'test-key',
            'base_url' => 'https://api.anthropic.com'
        ], $mockClient);
        
        // Create a request
        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::ANTHROPIC,
            model: EntityEnum::CLAUDE_3_5_SONNET,
            userId: $this->createTestUser()->id
        );
        
        // Generate the response
        $response = $driver->generateText($request);
        
        // Assert the response is successful
        $this->assertTrue($response->success);
        $this->assertEquals('Anthropic test response', $response->content);
        $this->assertEquals(15, $response->tokensUsed);
    }
    
    public function test_missing_api_key_throws_exception()
    {
        $this->expectException(\LaravelAIEngine\Exceptions\AIEngineException::class);
        
        $driver = new AnthropicEngineDriver([
            'base_url' => 'https://api.anthropic.com'
        ]);
        
        $driver->validateConfig();
    }

    public function test_provider_options_are_merged_into_anthropic_payload_and_headers(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'msg_options',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                'model' => 'claude-sonnet-4-5',
            ])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $driver = new AnthropicEngineDriver([
            'api_key' => 'test-key',
            'base_url' => 'https://api.anthropic.com',
        ], new Client(['handler' => $handlerStack]));

        $response = $driver->generateText(
            (new AIRequest('Hello', EngineEnum::ANTHROPIC, EntityEnum::CLAUDE_SONNET_4_5))
                ->withProviderOptions([
                    'thinking' => ['type' => 'enabled', 'budget_tokens' => 1024],
                    'headers' => ['anthropic-beta' => 'fine-grained-tool-streaming-2025-05-14'],
                ], 'anthropic')
        );

        $this->assertTrue($response->isSuccessful());

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('enabled', $payload['thinking']['type']);
        $this->assertSame(
            'fine-grained-tool-streaming-2025-05-14',
            $history[0]['request']->getHeaderLine('anthropic-beta')
        );
    }

    public function test_system_prompt_is_sent_as_a_cache_control_block_by_default(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'msg_cache',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                'model' => 'claude-sonnet-4-5',
            ])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $driver = new AnthropicEngineDriver([
            'api_key' => 'test-key',
            'base_url' => 'https://api.anthropic.com',
        ], new Client(['handler' => $handlerStack]));

        $response = $driver->generateText(
            (new AIRequest('Dynamic body', EngineEnum::ANTHROPIC, EntityEnum::CLAUDE_SONNET_4_5))
                ->withSystemPrompt('Stable runtime instructions.')
        );

        $this->assertTrue($response->isSuccessful());
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        // System is a content block with an ephemeral cache breakpoint, so Anthropic caches it.
        $this->assertSame('Stable runtime instructions.', $payload['system'][0]['text']);
        $this->assertSame('ephemeral', $payload['system'][0]['cache_control']['type']);
    }

    public function test_cache_token_usage_is_surfaced(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'msg_usage',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 10,
                    'cache_read_input_tokens' => 80,
                    'cache_creation_input_tokens' => 20,
                ],
                'model' => 'claude-sonnet-4-5',
            ])),
        ]);
        $driver = new AnthropicEngineDriver([
            'api_key' => 'test-key',
            'base_url' => 'https://api.anthropic.com',
        ], new Client(['handler' => HandlerStack::create($mock)]));

        $response = $driver->generateText(
            new AIRequest('Hello', EngineEnum::ANTHROPIC, EntityEnum::CLAUDE_SONNET_4_5)
        );

        $usage = $response->getUsage();
        $this->assertSame(80, $usage['cached_tokens']);
        $this->assertSame(20, $usage['cache_creation_tokens']);
    }

    public function test_system_prompt_caching_can_be_disabled(): void
    {
        config()->set('ai-engine.engines.anthropic.prompt_caching', false);

        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'msg_nocache',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                'model' => 'claude-sonnet-4-5',
            ])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $driver = new AnthropicEngineDriver([
            'api_key' => 'test-key',
            'base_url' => 'https://api.anthropic.com',
        ], new Client(['handler' => $handlerStack]));

        $driver->generateText(
            (new AIRequest('Dynamic body', EngineEnum::ANTHROPIC, EntityEnum::CLAUDE_SONNET_4_5))
                ->withSystemPrompt('Stable runtime instructions.')
        );

        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('Stable runtime instructions.', $payload['system']);
    }
}
