<?php

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;

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

    public function test_driver_accepts_string_timeout_from_environment_config(): void
    {
        $mock = new MockHandler([]);
        $handlerStack = HandlerStack::create($mock);

        $driver = new OpenAIEngineDriver([
            'api_key' => 'test-key',
            'timeout' => '30',
        ], new Client(['handler' => $handlerStack]));

        $this->assertInstanceOf(OpenAIEngineDriver::class, $driver);
    }

    public function test_generate_image_stores_gpt_image_base64_payload(): void
    {
        Storage::fake('public');
        config()->set('ai-engine.media_library.disk', 'public');

        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'created' => 1778666736,
                'data' => [
                    ['b64_json' => base64_encode('fake-png-bytes')],
                ],
            ])),
        ]);
        $handlerStack = HandlerStack::create($mock);

        $driver = new OpenAIEngineDriver([
            'api_key' => 'test-key',
        ], new Client(['handler' => $handlerStack]));

        $response = $driver->generateImage(new AIRequest(
            prompt: 'A minimal black square',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_IMAGE_1_MINI,
            parameters: ['image_count' => 1],
            userId: $this->createTestUser()->id
        ));

        $this->assertTrue($response->success);
        $this->assertCount(1, $response->getFiles());
        $this->assertNotEmpty($response->getFiles()[0]);
        Storage::disk('public')->assertExists(
            $response->getMetadata()['images'][0]['path']
        );
    }
    
    public function test_missing_api_key_throws_exception()
    {
        $this->expectException(\LaravelAIEngine\Exceptions\AIEngineException::class);
        
        $driver = new OpenAIEngineDriver([]);
        
        $driver->validateConfig();
    }
}
