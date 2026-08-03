<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Services;

use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Services\Models\DynamicModelResolver;
use LaravelAIEngine\Tests\TestCase;

final class DynamicModelResolverTest extends TestCase
{
    public function test_vision_capability_describes_image_input_and_keeps_text_output(): void
    {
        $this->model('vendor/vision-reviewer', ['chat', 'vision', 'json_mode']);

        $this->assertSame('text', EntityEnum::from('vendor/vision-reviewer')->getContentType());
    }

    public function test_image_generation_capability_still_routes_to_image_output(): void
    {
        $this->model('vendor/image-generator', ['vision', 'image_generation']);

        $this->assertSame('image', EntityEnum::from('vendor/image-generator')->getContentType());
    }

    public function test_speech_to_text_capability_keeps_text_output(): void
    {
        $this->model('vendor/transcriber', ['speech_to_text', 'transcription']);

        $this->assertSame('text', EntityEnum::from('vendor/transcriber')->getContentType());
    }

    /** @param list<string> $capabilities */
    private function model(string $modelId, array $capabilities): void
    {
        AIModel::query()->create([
            'provider' => 'openrouter',
            'model_id' => $modelId,
            'name' => $modelId,
            'capabilities' => $capabilities,
            'supports_streaming' => true,
            'supports_vision' => in_array('vision', $capabilities, true),
            'supports_function_calling' => false,
            'supports_json_mode' => false,
            'is_active' => true,
            'is_deprecated' => false,
            'metadata' => [],
        ]);

        app(DynamicModelResolver::class)->clearCache($modelId);
    }
}
