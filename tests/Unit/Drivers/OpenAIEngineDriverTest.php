<?php

namespace MagicAI\LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use MagicAI\LaravelAIEngine\Tests\TestCase;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;
use MagicAI\LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;

class OpenAIEngineDriverTest extends TestCase
{
    public function test_generate_text_returns_success_response()
    {
        // Create a mock response
        $mockResponse = new Response(200, [], json_encode([
            'id' => 'chatcmpl-123456',
            'object' => 'chat.completion',
            'created' => 1677858242,
            'model' => 'gpt-4o-2024-05-13',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'OpenAI test response'
                    ],
                    'finish_reason' => 'stop',
                    'index' => 0
                ]
            ],
            'usage' => [
                'prompt_tokens' => 5,
                'completion_tokens' => 10,
                'total_tokens' => 15
            ]
        ]));
        
        // Create a mock handler and add the response
        $mock = new MockHandler([$mockResponse]);
        $handlerStack = HandlerStack::create($mock);
        
        // Create a client with the mock handler
        $mockClient = new Client(['handler' => $handlerStack]);
        
        // Create the driver with the mock client
        $driver = new OpenAIEngineDriver([
            'api_key' => 'test-key',
        ], $mockClient);
        
        // Create a request
        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: $this->createTestUser()->id
        );
        
        // Generate the response
        $response = $driver->generateText($request);
        
        // Assert the response is successful
        $this->assertTrue($response->success);
        $this->assertEquals('OpenAI test response', $response->content);
        $this->assertEquals(15, $response->tokensUsed);
    }
    
    public function test_missing_api_key_throws_exception()
    {
        $this->expectException(\MagicAI\LaravelAIEngine\Exceptions\AIEngineException::class);
        
        $driver = new OpenAIEngineDriver([]);
        
        $driver->validateConfig();
    }
}
