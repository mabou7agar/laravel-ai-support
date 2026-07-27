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
                    ['id' => 'gpt-image-2'],
                    ['id' => 'gpt-image-1.5'],
                    ['id' => 'gpt-image-1-mini'],
                    ['id' => 'gpt-4o'],
                ],
            ]),
        ]);

        $this->artisan('ai:sync-models', ['--provider' => 'openai'])
            ->expectsOutput('🔄 Syncing AI Models...')
            ->expectsOutput('📡 Syncing OpenAI models...')
            ->expectsOutput('✅ Synced 4 OpenAI models')
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

        $gptImage2 = AIModel::findByModelId('gpt-image-2');
        $this->assertNotNull($gptImage2);
        $this->assertTrue($gptImage2->supports_streaming);
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

        $this->artisan('ai:sync-models', ['--provider' => 'fal_ai'])
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

    public function test_sync_models_command_includes_dedicated_openrouter_image_models_and_pricing(): void
    {
        Config::set('ai-engine.engines.openrouter.catalog_sync.additional_models', [
            'openai/gpt-image-2',
        ]);

        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response(['data' => []]),
            'https://openrouter.ai/api/v1/models/openai/gpt-image-2/endpoints' => Http::response([
                'data' => [
                    'id' => 'openai/gpt-image-2',
                    'name' => 'OpenAI: GPT Image 2',
                    'architecture' => ['modality' => 'text+image->image'],
                    'endpoints' => [[
                        'context_length' => 400000,
                        'supported_parameters' => ['quality', 'aspect_ratio'],
                        'pricing' => [
                            'prompt' => '0.000008',
                            'completion' => '0.000008',
                            'image_output' => '0.00003',
                        ],
                    ]],
                ],
            ]),
        ]);

        $this->artisan('ai:sync-models', ['--provider' => 'openrouter'])
            ->expectsOutput('📡 Syncing OpenRouter models...')
            ->expectsOutput('✅ Synced 1 OpenRouter models')
            ->assertSuccessful();

        $model = AIModel::query()->where('model_id', 'openai/gpt-image-2')->firstOrFail();
        $this->assertSame('openrouter', $model->provider);
        $this->assertSame(0.03, $model->pricing['image_output']);
        $this->assertSame(0.00003, $model->pricing['provider']['image_output']);
    }

    public function test_sync_models_command_registers_low_cost_media_provider_models(): void
    {
        $this->artisan('ai:sync-models', ['--provider' => 'media'])
            ->expectsOutput('🔄 Syncing AI Models...')
            ->expectsOutput('📡 Syncing low-cost media provider models...')
            ->assertSuccessful();

        $this->assertDatabaseHas('ai_models', [
            'provider' => 'cloudflare_workers_ai',
            'model_id' => '@cf/black-forest-labs/flux-1-schnell',
        ]);

        $this->assertDatabaseHas('ai_models', [
            'provider' => 'huggingface',
            'model_id' => 'black-forest-labs/FLUX.1-schnell',
        ]);

        $this->assertDatabaseHas('ai_models', [
            'provider' => 'replicate',
            'model_id' => 'black-forest-labs/flux-schnell',
        ]);

        $this->assertDatabaseHas('ai_models', [
            'provider' => 'comfyui',
            'model_id' => 'comfyui/default-image',
        ]);

        $model = AIModel::findByModelId('@cf/black-forest-labs/flux-1-schnell');

        $this->assertNotNull($model);
        $this->assertContains('image_generation', $model->capabilities);
        $this->assertSame('image', $model->metadata['content_type'] ?? null);
    }
}
