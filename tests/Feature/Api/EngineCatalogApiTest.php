<?php

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Tests\TestCase;

class EngineCatalogApiTest extends TestCase
{
    public function test_models_endpoint_returns_flat_model_list(): void
    {
        AIModel::create([
            'provider' => 'openai',
            'model_id' => 'gpt-4o',
            'name' => 'GPT-4o',
            'capabilities' => ['chat', 'vision'],
            'supports_streaming' => true,
            'supports_vision' => true,
            'supports_function_calling' => true,
            'supports_json_mode' => true,
            'is_active' => true,
            'is_deprecated' => false,
        ]);

        $response = $this->getJson('/api/v1/ai/models');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'models' => [
                        ['engine', 'provider', 'model_id', 'name', 'source'],
                    ],
                    'count',
                ],
            ]);

        $models = collect($response->json('data.models'));
        $this->assertTrue($models->contains(fn (array $model): bool => $model['model_id'] === 'gpt-4o'));
    }

    public function test_models_endpoint_can_filter_by_engine(): void
    {
        AIModel::create([
            'provider' => 'openai',
            'model_id' => 'gpt-4o',
            'name' => 'GPT-4o',
            'capabilities' => ['chat'],
            'supports_streaming' => true,
            'supports_vision' => false,
            'supports_function_calling' => true,
            'supports_json_mode' => true,
            'is_active' => true,
            'is_deprecated' => false,
        ]);

        AIModel::create([
            'provider' => 'anthropic',
            'model_id' => 'claude-4-sonnet',
            'name' => 'Claude 4 Sonnet',
            'capabilities' => ['chat'],
            'supports_streaming' => true,
            'supports_vision' => false,
            'supports_function_calling' => true,
            'supports_json_mode' => false,
            'is_active' => true,
            'is_deprecated' => false,
        ]);

        $response = $this->getJson('/api/v1/ai/models?engine=openai');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.engine', 'openai');

        $models = collect($response->json('data.models'));
        $this->assertTrue($models->isNotEmpty());
        $this->assertTrue($models->every(fn (array $model): bool => $model['engine'] === 'openai'));
    }

    public function test_engines_with_models_endpoint_returns_grouped_catalog(): void
    {
        AIModel::create([
            'provider' => 'openai',
            'model_id' => 'gpt-4o',
            'name' => 'GPT-4o',
            'capabilities' => ['chat', 'vision'],
            'supports_streaming' => true,
            'supports_vision' => true,
            'supports_function_calling' => true,
            'supports_json_mode' => true,
            'is_active' => true,
            'is_deprecated' => false,
        ]);

        $response = $this->getJson('/api/v1/ai/engines-with-models');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'engines' => [
                        ['engine', 'name', 'capabilities', 'configured', 'default_model', 'models'],
                    ],
                    'count',
                    'default_engine',
                    'default_model',
                ],
            ]);

        $engines = collect($response->json('data.engines'));
        $openai = $engines->firstWhere('engine', 'openai');

        $this->assertNotNull($openai);
        $this->assertIsArray($openai['models']);
        $this->assertTrue(collect($openai['models'])->contains(fn (array $model): bool => $model['model_id'] === 'gpt-4o'));
    }
}
