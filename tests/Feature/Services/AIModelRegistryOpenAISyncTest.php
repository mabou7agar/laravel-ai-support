<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Services\AIModelRegistry;
use LaravelAIEngine\Tests\TestCase;

class AIModelRegistryOpenAISyncTest extends TestCase
{
    public function test_openai_sync_keeps_gpt_image_models_in_database(): void
    {
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-image-2'],
                    ['id' => 'gpt-image-1.5'],
                    ['id' => 'gpt-image-1-mini'],
                    ['id' => 'gpt-4o'],
                ],
            ]),
        ]);

        $result = app(AIModelRegistry::class)->syncOpenAIModels();

        $this->assertTrue($result['success']);
        $this->assertSame(4, $result['total']);

        $imageModel = AIModel::findByModelId('gpt-image-1.5');
        $this->assertNotNull($imageModel);
        $this->assertSame('openai', $imageModel->provider);
        $this->assertContains('image_generation', $imageModel->capabilities);
        $this->assertContains('image_editing', $imageModel->capabilities);
        $this->assertTrue($imageModel->supports_vision);
        $this->assertFalse($imageModel->supports_streaming);
        $this->assertFalse($imageModel->supports_function_calling);

        $miniModel = AIModel::findByModelId('gpt-image-1-mini');
        $this->assertNotNull($miniModel);
        $this->assertContains('image_generation', $miniModel->capabilities);

        $gptImage2 = AIModel::findByModelId('gpt-image-2');
        $this->assertNotNull($gptImage2);
        $this->assertTrue($gptImage2->supports_streaming);
    }

    public function test_fal_sync_imports_paginated_model_catalog_with_capabilities(): void
    {
        Config::set('ai-engine.engines.fal_ai.api_key', 'test-fal-key');
        Config::set('ai-engine.engines.fal_ai.catalog_sync.limit', 2);

        Http::fake([
            'api.fal.ai/v1/models?limit=2&status=active' => Http::response([
                'models' => [
                    [
                        'endpoint_id' => 'fal-ai/flux/dev',
                        'metadata' => [
                            'display_name' => 'FLUX.1 Dev',
                            'category' => 'text-to-image',
                            'description' => 'Fast text-to-image generation',
                            'status' => 'active',
                            'tags' => ['image'],
                            'date' => '2024-08-01T00:00:00Z',
                        ],
                    ],
                    [
                        'endpoint_id' => 'fal-ai/kling-video/o3/standard/image-to-video',
                        'metadata' => [
                            'display_name' => 'Kling O3 Image to Video',
                            'category' => 'image-to-video',
                            'description' => 'Image-to-video generation',
                            'status' => 'active',
                        ],
                    ],
                ],
                'next_cursor' => 'Mg==',
                'has_more' => true,
            ]),
            'api.fal.ai/v1/models?limit=2&cursor=Mg%3D%3D&status=active' => Http::response([
                'models' => [
                    [
                        'endpoint_id' => 'fal-ai/elevenlabs/tts',
                        'metadata' => [
                            'display_name' => 'ElevenLabs TTS',
                            'category' => 'text-to-speech',
                            'description' => 'Text-to-speech audio generation',
                            'status' => 'active',
                        ],
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ]),
        ]);

        $result = app(AIModelRegistry::class)->syncFalModels();

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['new']);
        $this->assertSame(2, $result['pages']);

        $imageModel = AIModel::findByModelId('fal-ai/flux/dev');
        $this->assertNotNull($imageModel);
        $this->assertSame('fal_ai', $imageModel->provider);
        $this->assertContains('image_generation', $imageModel->capabilities);
        $this->assertContains('text_to_image', $imageModel->capabilities);

        $videoModel = AIModel::findByModelId('fal-ai/kling-video/o3/standard/image-to-video');
        $this->assertNotNull($videoModel);
        $this->assertContains('video_generation', $videoModel->capabilities);
        $this->assertContains('image_to_video', $videoModel->capabilities);
        $this->assertTrue($videoModel->supports_vision);

        $audioModel = AIModel::findByModelId('fal-ai/elevenlabs/tts');
        $this->assertNotNull($audioModel);
        $this->assertContains('tts', $audioModel->capabilities);
        $this->assertContains('audio_generation', $audioModel->capabilities);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.fal.ai/v1/models?limit=2&status=active'
                && $request->header('Authorization')[0] === 'Key test-fal-key';
        });
    }

    public function test_openrouter_sync_refreshes_provider_owned_capabilities_for_existing_models(): void
    {
        Config::set('ai-engine.engines.openrouter.catalog_sync.update_existing', true);

        AIModel::query()->create([
            'provider' => 'openrouter',
            'model_id' => 'vendor/reasoning-flash',
            'name' => 'Host Custom Name',
            'capabilities' => ['chat'],
            'supports_function_calling' => false,
            'supports_json_mode' => true,
            'is_active' => false,
        ]);

        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [[
                    'id' => 'vendor/reasoning-flash',
                    'name' => 'Provider Name',
                    'description' => 'Provider description.',
                    'architecture' => ['modality' => 'text->text'],
                    'supported_parameters' => ['tools', 'tool_choice', 'reasoning', 'max_tokens'],
                    'context_length' => 262144,
                    'top_provider' => ['max_completion_tokens' => 65536],
                    'pricing' => ['prompt' => '0.0000001', 'completion' => '0.0000003'],
                ]],
            ]),
        ]);

        $result = app(AIModelRegistry::class)->syncOpenRouterModels();
        $model = AIModel::query()->where('model_id', 'vendor/reasoning-flash')->firstOrFail();

        $this->assertSame(1, $result['updated']);
        $this->assertSame('Host Custom Name', $model->name);
        $this->assertFalse($model->is_active);
        $this->assertTrue($model->supports_function_calling);
        $this->assertFalse($model->supports_json_mode);
        $this->assertContains('reasoning', $model->capabilities);
        $this->assertSame(262144, $model->context_window['input']);
        $this->assertSame(65536, $model->context_window['output']);
        $this->assertSame(
            ['tools', 'tool_choice', 'reasoning', 'max_tokens'],
            $model->metadata['supported_parameters']
        );
    }
}
