<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Fal;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Services\Models\DynamicModelResolver;
use LaravelAIEngine\Tests\TestCase;

class FalCatalogExecutionServiceTest extends TestCase
{
    public function test_dynamic_fal_catalog_models_route_to_fal_and_execute_generically(): void
    {
        Storage::fake('public');
        Config::set('ai-engine.engines.fal_ai.api_key', 'test-fal-key');

        AIModel::query()->create([
            'provider' => 'fal_ai',
            'model_id' => 'fal-ai/test/image',
            'name' => 'FAL Test Image',
            'capabilities' => ['image_generation', 'text_to_image'],
            'supports_streaming' => false,
            'supports_vision' => false,
            'supports_function_calling' => false,
            'supports_json_mode' => false,
            'is_active' => true,
            'is_deprecated' => false,
            'metadata' => [
                'schema' => [
                    'required' => ['prompt'],
                    'properties' => [
                        'prompt' => ['type' => 'string'],
                    ],
                ],
            ],
        ]);
        app(DynamicModelResolver::class)->clearCache('fal-ai/test/image');

        Http::fake([
            'https://fal.run/fal-ai/test/image' => Http::response([
                'request_id' => 'fal_req_123',
                'images' => [
                    ['url' => 'https://cdn.example.test/fal.png'],
                ],
            ]),
            'https://cdn.example.test/fal.png' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $request = new AIRequest(
            prompt: 'A catalog generated image',
            engine: EngineEnum::FAL_AI,
            model: 'fal-ai/test/image',
            parameters: ['image_size' => 'square_hd']
        );

        $this->assertSame(EngineEnum::FAL_AI, $request->getEngine()->value);
        $this->assertSame('image', $request->getContentType());

        $response = (new FalAIEngineDriver(config('ai-engine.engines.fal_ai')))->generate($request);

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['https://cdn.example.test/fal.png'], $response->getFiles());
        $this->assertDatabaseHas('ai_provider_tool_runs', [
            'provider' => 'fal_ai',
            'ai_model' => 'fal-ai/test/image',
            'status' => 'completed',
            'provider_request_id' => 'fal_req_123',
        ]);
        $this->assertDatabaseHas('ai_provider_tool_artifacts', [
            'provider' => 'fal_ai',
            'artifact_type' => 'image',
            'source_url' => 'https://cdn.example.test/fal.png',
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://fal.run/fal-ai/test/image'
            && $request->header('Authorization')[0] === 'Key test-fal-key'
            && $request['prompt'] === 'A catalog generated image'
            && $request['image_size'] === 'square_hd');
    }
}
