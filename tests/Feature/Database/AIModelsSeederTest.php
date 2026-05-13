<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Database;

use LaravelAIEngine\Database\Seeders\AIModelsSeeder;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Tests\TestCase;

class AIModelsSeederTest extends TestCase
{
    public function test_seeder_registers_current_openai_image_models(): void
    {
        $this->seed(AIModelsSeeder::class);

        foreach (['gpt-image-1.5', 'gpt-image-1', 'gpt-image-1-mini'] as $modelId) {
            $model = AIModel::findByModelId($modelId);

            $this->assertNotNull($model, "{$modelId} was not seeded.");
            $this->assertSame('openai', $model->provider);
            $this->assertContains('image_generation', $model->capabilities);
            $this->assertContains('image_editing', $model->capabilities);
            $this->assertTrue($model->supports_vision);
            $this->assertFalse($model->supports_function_calling);
        }
    }

    public function test_seeder_registers_fal_media_models_used_by_routes(): void
    {
        $this->seed(AIModelsSeeder::class);

        $expected = [
            'fal-ai/nano-banana-2' => 'image_generation',
            'fal-ai/nano-banana-2/edit' => 'image_editing',
            'fal-ai/kling-video/o3/standard/image-to-video' => 'video_generation',
            'fal-ai/kling-video/o3/standard/reference-to-video' => 'video_generation',
            'bytedance/seedance-2.0/text-to-video' => 'video_generation',
            'bytedance/seedance-2.0/image-to-video' => 'video_generation',
            'bytedance/seedance-2.0/reference-to-video' => 'video_generation',
        ];

        foreach ($expected as $modelId => $capability) {
            $model = AIModel::findByModelId($modelId);

            $this->assertNotNull($model, "{$modelId} was not seeded.");
            $this->assertSame('fal_ai', $model->provider);
            $this->assertContains($capability, $model->capabilities);
        }
    }
}
