<?php

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class AIRequestTest extends TestCase
{
    public function test_ai_request_creation()
    {
        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            parameters: ['temperature' => 0.7],
            userId: 'user-123'
        );

        $this->assertEquals('Test prompt', $request->prompt);
        $this->assertEquals(EngineEnum::OPENAI, $request->engine->value);
        $this->assertEquals(EntityEnum::GPT_4O, $request->model->value);
        $this->assertEquals(['temperature' => 0.7], $request->parameters);
        $this->assertEquals('user-123', $request->userId);
    }

    public function test_ai_request_with_default_values()
    {
        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
        );

        $this->assertEquals([], $request->parameters);
        $this->assertNull($request->userId);
        $this->assertEquals([], $request->context);
        $this->assertEquals([], $request->files);
        $this->assertFalse($request->stream);
        $this->assertNull($request->systemPrompt);
        $this->assertEquals([], $request->messages);
        $this->assertNull($request->maxTokens);
        $this->assertNull($request->temperature);
        $this->assertNull($request->seed);
        $this->assertEquals([], $request->metadata);
    }

    public function test_ai_request_with_all_parameters()
    {
        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            parameters: ['temperature' => 0.7],
            userId: 'user-123',
            context: ['key' => 'value'],
            files: ['file1.txt'],
            stream: true,
            systemPrompt: 'You are a helpful assistant',
            messages: [['role' => 'user', 'content' => 'Hello']],
            maxTokens: 1000,
            temperature: 0.8,
            seed: 42,
            metadata: ['test' => 'data']
        );

        $this->assertEquals('Test prompt', $request->prompt);
        $this->assertEquals(EngineEnum::OPENAI, $request->engine->value);
        $this->assertEquals(EntityEnum::GPT_4O, $request->model->value);
        $this->assertEquals(['temperature' => 0.7], $request->parameters);
        $this->assertEquals('user-123', $request->userId);
        $this->assertEquals(['key' => 'value'], $request->context);
        $this->assertEquals(['file1.txt'], $request->files);
        $this->assertTrue($request->stream);
        $this->assertEquals('You are a helpful assistant', $request->systemPrompt);
        $this->assertEquals([['role' => 'user', 'content' => 'Hello']], $request->messages);
        $this->assertEquals(1000, $request->maxTokens);
        $this->assertEquals(0.8, $request->temperature);
        $this->assertEquals(42, $request->seed);
        $this->assertEquals(['test' => 'data'], $request->metadata);
    }

    public function test_ai_request_make_static_method()
    {
        $request = AIRequest::make(
            'Test prompt',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O,
            ['temperature' => 0.7]
        );

        $this->assertEquals('Test prompt', $request->prompt);
        $this->assertEquals(EngineEnum::OPENAI, $request->engine->value);
        $this->assertEquals(EntityEnum::GPT_4O, $request->model->value);
        $this->assertEquals(['temperature' => 0.7], $request->parameters);
    }

    public function test_ai_request_for_user_method()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $userRequest = $request->forUser('user-123');

        $this->assertEquals('user-123', $userRequest->userId);
        $this->assertNotSame($request, $userRequest); // Should return new instance
    }

    public function test_ai_request_with_streaming()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $streamingRequest = $request->withStreaming(true);

        $this->assertTrue($streamingRequest->stream);
        $this->assertNotSame($request, $streamingRequest);
    }

    public function test_ai_request_with_context()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $contextRequest = $request->withContext(['key' => 'value']);

        $this->assertEquals(['key' => 'value'], $contextRequest->context);
        $this->assertNotSame($request, $contextRequest);
    }

    public function test_ai_request_with_files()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $fileRequest = $request->withFiles(['file1.txt', 'file2.txt']);

        $this->assertEquals(['file1.txt', 'file2.txt'], $fileRequest->files);
        $this->assertNotSame($request, $fileRequest);
    }

    public function test_ai_request_with_system_prompt()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $systemRequest = $request->withSystemPrompt('You are helpful');

        $this->assertEquals('You are helpful', $systemRequest->systemPrompt);
        $this->assertNotSame($request, $systemRequest);
    }

    public function test_ai_request_with_messages()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $messageRequest = $request->withMessages($messages);

        $this->assertEquals($messages, $messageRequest->messages);
        $this->assertNotSame($request, $messageRequest);
    }

    public function test_ai_request_with_parameters()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O, ['temp' => 0.5]);
        $paramRequest = $request->withParameters(['max_tokens' => 100]);

        $this->assertEquals(['temp' => 0.5, 'max_tokens' => 100], $paramRequest->parameters);
        $this->assertNotSame($request, $paramRequest);
    }

    public function test_ai_request_with_max_tokens()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $tokenRequest = $request->withMaxTokens(500);

        $this->assertEquals(500, $tokenRequest->maxTokens);
        $this->assertNotSame($request, $tokenRequest);
    }

    public function test_ai_request_with_temperature()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $tempRequest = $request->withTemperature(0.9);

        $this->assertEquals(0.9, $tempRequest->temperature);
        $this->assertNotSame($request, $tempRequest);
    }

    public function test_ai_request_with_seed()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $seedRequest = $request->withSeed(123);

        $this->assertEquals(123, $seedRequest->seed);
        $this->assertNotSame($request, $seedRequest);
    }

    public function test_ai_request_with_metadata()
    {
        $request = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $metaRequest = $request->withMetadata(['key' => 'value']);

        $this->assertEquals(['key' => 'value'], $metaRequest->metadata);
        $this->assertNotSame($request, $metaRequest);
    }

    public function test_ai_request_content_type_methods()
    {
        $textRequest = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $this->assertTrue($textRequest->isTextGeneration());
        $this->assertFalse($textRequest->isImageGeneration());
        $this->assertFalse($textRequest->isVideoGeneration());
        $this->assertFalse($textRequest->isAudioGeneration());

        $imageRequest = AIRequest::make('Test', EngineEnum::OPENAI, EntityEnum::DALL_E_3);
        $this->assertFalse($imageRequest->isTextGeneration());
        $this->assertTrue($imageRequest->isImageGeneration());
    }

    public function test_ai_request_to_array()
    {
        $request = new AIRequest(
            prompt: 'Test prompt',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            parameters: ['temperature' => 0.7],
            userId: 'user-123',
            stream: true,
            maxTokens: 1000
        );

        $array = $request->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('Test prompt', $array['prompt']);
        $this->assertEquals('openai', $array['engine']);
        $this->assertEquals('gpt-4o', $array['model']);
        $this->assertEquals(['temperature' => 0.7], $array['parameters']);
        $this->assertEquals('user-123', $array['user_id']);
        $this->assertTrue($array['stream']);
        $this->assertEquals(1000, $array['max_tokens']);
    }
}
