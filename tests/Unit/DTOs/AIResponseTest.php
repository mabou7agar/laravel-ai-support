<?php

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class AIResponseTest extends TestCase
{
    public function test_ai_response_creation()
    {
        $response = new AIResponse(
            content: 'Generated content',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            metadata: ['test' => 'data'],
            tokensUsed: 100,
            creditsUsed: 0.5
        );

        $this->assertEquals('Generated content', $response->content);
        $this->assertEquals(EngineEnum::OPENAI, $response->engine);
        $this->assertEquals(EntityEnum::GPT_4O, $response->model);
        $this->assertEquals(['test' => 'data'], $response->metadata);
        $this->assertEquals(100, $response->tokensUsed);
        $this->assertEquals(0.5, $response->creditsUsed);
        $this->assertTrue($response->success);
        $this->assertNull($response->error);
    }

    public function test_ai_response_with_error()
    {
        $response = new AIResponse(
            content:  '',
            engine:   EngineEnum::OPENAI,
            model:    EntityEnum::GPT_4O,
            metadata: [],
            usage:    [],
            error:    'API request failed',
            success:  false
        );

        $this->assertEquals('', $response->content);
        $this->assertFalse($response->success);
        $this->assertEquals('API request failed', $response->error);
    }

    public function test_ai_response_default_values()
    {
        $response = new AIResponse(
            content: 'Test content',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
        );

        $this->assertEquals('Test content', $response->content);
        $this->assertEquals([], $response->metadata);
        $this->assertTrue($response->success);
        $this->assertNull($response->error);
    }

    public function test_ai_response_is_successful()
    {
        $successResponse = new AIResponse(
            content: 'Success',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
        );
        $this->assertTrue($successResponse->isSuccessful());

        $errorResponse = new AIResponse(
            content: '',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            success: false,
            error: 'Failed'
        );
        $this->assertFalse($errorResponse->isSuccessful());
    }

    public function test_ai_response_has_error()
    {
        $successResponse = new AIResponse(
            content: 'Success',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
        );
        $this->assertFalse($successResponse->hasError());

        $errorResponse = new AIResponse(
            content: '',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            success: false,
            error: 'Failed'
        );
        $this->assertTrue($errorResponse->hasError());
    }

    public function test_ai_response_get_credits_used()
    {
        $response = new AIResponse(
            content: 'Test',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            usage: ['total_cost' => 2.5]
        );

        $this->assertEquals(2.5, $response->getCreditsUsed());
    }

    public function test_ai_response_get_credits_used_default()
    {
        $response = new AIResponse(
            content: 'Test',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O
        );
        $this->assertEquals(0, $response->getCreditsUsed());
    }

    public function test_ai_response_get_tokens_used()
    {
        $response = new AIResponse(
            content: 'Test',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            usage: ['tokens' => 150]
        );

        $this->assertEquals(150, $response->getTokensUsed());
    }

    public function test_ai_response_get_processing_time()
    {
        $response = new AIResponse(
            content: 'Test',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            metadata: ['processing_time' => 1.25]
        );

        $this->assertEquals(1.25, $response->getProcessingTime());
    }

    public function test_ai_response_to_array()
    {
        $response = new AIResponse(
            content: 'Generated content',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            usage: ['tokens' => 100, 'total_cost' => 0.5],
            metadata: ['model' => 'gpt-4o'],
            success: true
        );

        $array = $response->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('Generated content', $array['content']);
        $this->assertEquals(EngineEnum::OPENAI, $array['engine']);
        $this->assertEquals(EntityEnum::GPT_4O, $array['model']);
        $this->assertEquals(['tokens' => 100, 'total_cost' => 0.5], $array['usage']);
        $this->assertEquals(['model' => 'gpt-4o'], $array['metadata']);
        $this->assertTrue($array['success']);
        $this->assertNull($array['error']);
    }

    public function test_ai_response_json_serialization()
    {
        $response = new AIResponse(
            content: 'Generated content',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            usage: ['tokens' => 100],
            metadata: ['model' => 'gpt-4o']
        );

        $json = json_encode($response);
        $decoded = json_decode($json, true);

        $this->assertEquals('Generated content', $decoded['content']);
        $this->assertEquals(['tokens' => 100], $decoded['usage']);
        $this->assertEquals(['model' => 'gpt-4o'], $decoded['metadata']);
        $this->assertTrue($decoded['success']);
    }

    public function test_ai_response_from_array()
    {
        $data = [
            'content' => 'Generated content',
            'engine' => EngineEnum::OPENAI,
            'model' => EntityEnum::GPT_4O,
            'usage' => ['tokens' => 100, 'total_cost' => 0.5],
            'metadata' => ['model' => 'gpt-4o'],
            'success' => true,
            'error' => null
        ];

        $response = AIResponse::fromArray($data);

        $this->assertEquals('Generated content', $response->content);
        $this->assertEquals(EngineEnum::OPENAI, $response->engine);
        $this->assertEquals(EntityEnum::GPT_4O, $response->model);
        $this->assertEquals(['tokens' => 100, 'total_cost' => 0.5], $response->usage);
        $this->assertEquals(['model' => 'gpt-4o'], $response->metadata);
        $this->assertTrue($response->success);
        $this->assertNull($response->error);
    }
}
