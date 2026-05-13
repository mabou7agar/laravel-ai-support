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
                    ['id' => 'gpt-image-1.5'],
                    ['id' => 'gpt-image-1-mini'],
                    ['id' => 'gpt-4o'],
                ],
            ]),
        ]);

        $result = app(AIModelRegistry::class)->syncOpenAIModels();

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['total']);

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
}
