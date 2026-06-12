<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers\Fal;

use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Tests\TestCase;

class FalVideoPayloadTest extends TestCase
{
    private function driver(): FalAIEngineDriver
    {
        return new FalAIEngineDriver(['api_key' => 'test-key']);
    }

    private function operation(string $model, array $parameters, string $prompt = 'a neon city'): array
    {
        return $this->driver()->prepareVideoOperation(new AIRequest(
            prompt: $prompt,
            engine: 'fal_ai',
            model: $model,
            parameters: $parameters,
        ));
    }

    public function test_seedance_image_to_video_routes_first_and_last_frame(): void
    {
        $op = $this->operation(EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO, [
            'start_image_url' => 'https://example.com/start.png',
            'end_image_url' => 'https://example.com/end.png',
        ]);

        $this->assertSame(EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO, $op['endpoint']);
        $this->assertSame('https://example.com/start.png', $op['payload']['image_url']);
        $this->assertSame('https://example.com/end.png', $op['payload']['end_image_url']);
    }

    public function test_kling_v1_uses_tail_image_url_for_last_frame(): void
    {
        $op = $this->operation(EntityEnum::FAL_KLING_V1_STD_IMAGE_TO_VIDEO, [
            'start_image_url' => 'https://example.com/start.png',
            'end_image_url' => 'https://example.com/end.png',
            'negative_prompt' => 'blurry',
            'cfg_scale' => 0.7,
        ]);

        $this->assertSame('fal-ai/kling-video/v1/standard/image-to-video', $op['endpoint']);
        $this->assertSame('https://example.com/start.png', $op['payload']['image_url']);
        $this->assertSame('https://example.com/end.png', $op['payload']['tail_image_url']);
        $this->assertArrayNotHasKey('end_image_url', $op['payload']);
        $this->assertSame('blurry', $op['payload']['negative_prompt']);
        $this->assertSame(0.7, $op['payload']['cfg_scale']);
    }

    public function test_kling_o1_uses_start_and_end_image_url(): void
    {
        $op = $this->operation(EntityEnum::FAL_KLING_O1_IMAGE_TO_VIDEO, [
            'start_image_url' => 'https://example.com/start.png',
            'end_image_url' => 'https://example.com/end.png',
        ]);

        $this->assertSame('https://example.com/start.png', $op['payload']['start_image_url']);
        $this->assertSame('https://example.com/end.png', $op['payload']['end_image_url']);
    }

    public function test_luma_ray2_image_to_video_supports_first_last_frame_and_loop(): void
    {
        $op = $this->operation(EntityEnum::FAL_LUMA_RAY2_IMAGE_TO_VIDEO, [
            'start_image_url' => 'https://example.com/start.png',
            'end_image_url' => 'https://example.com/end.png',
            'loop' => true,
        ]);

        $this->assertSame('fal-ai/luma-dream-machine/ray-2/image-to-video', $op['endpoint']);
        $this->assertSame('https://example.com/start.png', $op['payload']['image_url']);
        $this->assertSame('https://example.com/end.png', $op['payload']['end_image_url']);
        $this->assertTrue($op['payload']['loop']);
        $this->assertSame('540p', $op['payload']['resolution']);
    }

    public function test_stable_video_endpoint_is_fixed_and_has_no_prompt(): void
    {
        $op = $this->operation(EntityEnum::FAL_STABLE_VIDEO, [
            'start_image_url' => 'https://example.com/start.png',
            'motion_bucket_id' => 180,
        ], prompt: '');

        $this->assertSame('fal-ai/stable-video', $op['endpoint']);
        $this->assertSame('https://example.com/start.png', $op['payload']['image_url']);
        $this->assertSame(180, $op['payload']['motion_bucket_id']);
        $this->assertSame(0.02, $op['payload']['cond_aug']);
        $this->assertArrayNotHasKey('prompt', $op['payload']);
    }

    public function test_animatediff_endpoint_is_fixed(): void
    {
        $op = $this->operation(EntityEnum::FAL_ANIMATEDIFF, [], prompt: 'a dancing robot');

        $this->assertSame('fal-ai/fast-animatediff/turbo/text-to-video', $op['endpoint']);
        $this->assertSame('a dancing robot', $op['payload']['prompt']);
        $this->assertSame(16, $op['payload']['num_frames']);
    }

    public function test_kling_v1_text_to_video_passes_camera_control(): void
    {
        $op = $this->operation(EntityEnum::FAL_KLING_V1_STD_TEXT_TO_VIDEO, [
            'camera_control' => 'forward_up',
        ], prompt: 'drive forward');

        $this->assertSame('forward_up', $op['payload']['camera_control']);
    }

    public function test_seedance_image_to_video_requires_start_frame(): void
    {
        $this->expectException(AIEngineException::class);
        $this->operation(EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO, []);
    }

    public function test_unsupported_options_are_not_forwarded(): void
    {
        // camera_control is a Kling-v1 field; it must NOT leak into a Seedance payload.
        $op = $this->operation(EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO, [
            'camera_control' => 'forward_up',
            'cfg_scale' => 0.9,
        ]);

        $this->assertArrayNotHasKey('camera_control', $op['payload']);
        $this->assertArrayNotHasKey('cfg_scale', $op['payload']);
    }
}
