<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Console;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Tests\TestCase;

class SyncAIModelsCommandTest extends TestCase
{
    public function test_sync_models_command_discovers_new_openai_models_into_database(): void
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

        $this->artisan('ai-engine:sync-models', ['--provider' => 'openai'])
            ->expectsOutput('🔄 Syncing AI Models...')
            ->expectsOutput('📡 Syncing OpenAI models...')
            ->expectsOutput('✅ Synced 3 OpenAI models')
            ->assertSuccessful();

        $this->assertDatabaseHas('ai_models', [
            'provider' => 'openai',
            'model_id' => 'gpt-image-1.5',
        ]);

        $imageModel = AIModel::findByModelId('gpt-image-1.5');

        $this->assertNotNull($imageModel);
        $this->assertContains('image_generation', $imageModel->capabilities);
        $this->assertContains('image_editing', $imageModel->capabilities);
        $this->assertFalse($imageModel->supports_streaming);
    }

    public function test_sync_models_command_discovers_fal_catalog_into_database(): void
    {
        Config::set('ai-engine.engines.fal_ai.api_key', 'test-fal-key');
        Config::set('ai-engine.engines.fal_ai.catalog_sync.limit', 2);

        Http::fake([
            'api.fal.ai/v1/models*' => Http::response([
                'models' => [
                    [
                        'endpoint_id' => 'fal-ai/flux/dev',
                        'metadata' => [
                            'display_name' => 'FLUX.1 Dev',
                            'category' => 'text-to-image',
                            'description' => 'Fast text-to-image generation',
                            'status' => 'active',
                        ],
                    ],
                    [
                        'endpoint_id' => 'fal-ai/minimax/video-01/image-to-video',
                        'metadata' => [
                            'display_name' => 'MiniMax Image to Video',
                            'category' => 'image-to-video',
                            'description' => 'Image-to-video generation',
                            'status' => 'active',
                        ],
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ]),
        ]);

        $this->artisan('ai-engine:sync-models', ['--provider' => 'fal_ai'])
            ->expectsOutput('🔄 Syncing AI Models...')
            ->expectsOutput('📡 Syncing FAL models...')
            ->expectsOutput('✅ Synced 2 FAL models')
            ->assertSuccessful();

        $this->assertDatabaseHas('ai_models', [
            'provider' => 'fal_ai',
            'model_id' => 'fal-ai/flux/dev',
        ]);

        $videoModel = AIModel::findByModelId('fal-ai/minimax/video-01/image-to-video');

        $this->assertNotNull($videoModel);
        $this->assertContains('video_generation', $videoModel->capabilities);
        $this->assertContains('image_to_video', $videoModel->capabilities);
        $this->assertTrue($videoModel->supports_vision);
    }
}
