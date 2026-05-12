<?php

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Fal\FalAsyncVideoService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class GenerateVideoApiTest extends TestCase
{
    public function test_video_endpoint_defaults_to_seedance_text_to_video_for_text_only_requests(): void
    {
        $service = Mockery::mock(AIEngineService::class);
        $service->shouldReceive('generateDirect')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame('fal_ai', $request->getEngine()->value);
                $this->assertSame(EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO, $request->getModel()->value);
                $this->assertSame('Create a neon city chase at night', $request->getPrompt());

                return true;
            })
            ->andReturn(AIResponse::success(
                '{"video":{"url":"https://example.com/out.mp4"}}',
                'fal_ai',
                EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO
            ));

        $this->app->instance(AIEngineService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/video', [
            'prompt' => 'Create a neon city chase at night',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.model', EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO);
    }

    public function test_video_endpoint_defaults_to_kling_reference_when_character_sources_are_present(): void
    {
        $service = Mockery::mock(AIEngineService::class);
        $service->shouldReceive('generateDirect')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame(EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO, $request->getModel()->value);
                $this->assertArrayHasKey('character_sources', $request->getParameters());

                return true;
            })
            ->andReturn(AIResponse::success(
                '{"video":{"url":"https://example.com/out.mp4"}}',
                'fal_ai',
                EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO
            ));

        $this->app->instance(AIEngineService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/video', [
            'prompt' => 'Make the pilot walk toward camera',
            'character_sources' => [
                [
                    'name' => 'Mina',
                    'frontal_image_url' => 'https://example.com/front.png',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.model', EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO);
    }

    public function test_video_endpoint_defaults_to_seedance_reference_when_animation_reference_is_present(): void
    {
        $service = Mockery::mock(AIEngineService::class);
        $service->shouldReceive('generateDirect')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame(EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO, $request->getModel()->value);
                $this->assertSame(['https://example.com/dance-reference.mp4'], $request->getParameters()['reference_video_urls']);
                $this->assertSame(['https://example.com/dance-reference.mp4'], $request->getParameters()['video_urls']);

                return true;
            })
            ->andReturn(AIResponse::success(
                '{"video":{"url":"https://example.com/out.mp4"}}',
                'fal_ai',
                EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO
            ));

        $this->app->instance(AIEngineService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/video', [
            'prompt' => 'Make the character perform this dance',
            'animation_reference_url' => 'https://example.com/dance-reference.mp4',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.model', EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO);
    }

    public function test_video_endpoint_passes_reference_audio_urls_with_seedance_reference_model(): void
    {
        $service = Mockery::mock(AIEngineService::class);
        $service->shouldReceive('generateDirect')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame(EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO, $request->getModel()->value);
                $this->assertSame(['https://example.com/motion.mp4'], $request->getParameters()['video_urls']);
                $this->assertSame(['https://example.com/beat.mp3'], $request->getParameters()['audio_urls']);

                return true;
            })
            ->andReturn(AIResponse::success(
                '{"video":{"url":"https://example.com/out.mp4"}}',
                'fal_ai',
                EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO
            ));

        $this->app->instance(AIEngineService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/video', [
            'prompt' => 'Use the dance and beat as timing references',
            'reference_video_urls' => ['https://example.com/motion.mp4'],
            'reference_audio_urls' => ['https://example.com/beat.mp3'],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.model', EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO);
    }

    public function test_video_endpoint_rejects_audio_reference_without_visual_or_video_reference(): void
    {
        $response = $this->postJson('/api/v1/ai/generate/video', [
            'prompt' => 'Use this beat',
            'reference_audio_urls' => ['https://example.com/beat.mp3'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.message', 'This video model requires reference_image_urls, reference_video_urls, or character_sources.');
    }

    public function test_image_endpoint_defaults_to_nano_banana_edit_when_source_images_are_present(): void
    {
        $service = Mockery::mock(AIEngineService::class);
        $service->shouldReceive('generateDirect')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame('fal_ai', $request->getEngine()->value);
                $this->assertSame(EntityEnum::FAL_NANO_BANANA_2_EDIT, $request->getModel()->value);
                $this->assertSame(['https://example.com/base.png'], $request->getParameters()['source_images']);

                return true;
            })
            ->andReturn(AIResponse::success(
                '{"images":[{"url":"https://example.com/base.png"}]}',
                'fal_ai',
                EntityEnum::FAL_NANO_BANANA_2_EDIT
            ));

        $this->app->instance(AIEngineService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/image', [
            'prompt' => 'Turn this into a cinematic portrait',
            'source_images' => ['https://example.com/base.png'],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.model', EntityEnum::FAL_NANO_BANANA_2_EDIT);
    }

    public function test_video_endpoint_returns_422_for_image_model_without_start_frame(): void
    {
        $response = $this->postJson('/api/v1/ai/generate/video', [
            'model' => EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            'prompt' => 'Animate this still image',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.message', 'This video model requires start_image_url.');
    }

    public function test_video_endpoint_returns_422_for_reference_model_without_references(): void
    {
        $response = $this->postJson('/api/v1/ai/generate/video', [
            'model' => EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO,
            'prompt' => 'Use these references to create a scene',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.message', 'This video model requires reference_image_urls, reference_video_urls, or character_sources.');
    }

    public function test_video_endpoint_can_submit_async_job(): void
    {
        $service = Mockery::mock(FalAsyncVideoService::class);
        $service->shouldReceive('submit')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Create a neon city chase at night', $prompt);
                $this->assertSame(EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO, $options['model']);
                $this->assertNull($userId);

                return true;
            })
            ->andReturn([
                'job_id' => 'local-job-1',
                'status' => [
                    'job_id' => 'local-job-1',
                    'status' => 'queued',
                    'metadata' => [
                        'provider' => ['request_id' => 'fal-job-1'],
                    ],
                ],
                'webhook_url' => 'https://app.test/api/v1/ai/generate/video/fal/webhook?job_id=local-job-1&token=secret',
            ]);

        $this->app->instance(FalAsyncVideoService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/video', [
            'prompt' => 'Create a neon city chase at night',
            'async' => true,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', 'local-job-1')
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_video_job_status_endpoint_returns_tracked_job(): void
    {
        $service = Mockery::mock(FalAsyncVideoService::class);
        $service->shouldReceive('getStatus')
            ->once()
            ->with('local-job-1', true)
            ->andReturn([
                'job_id' => 'local-job-1',
                'status' => 'processing',
                'metadata' => [
                    'provider' => ['status' => 'IN_PROGRESS'],
                ],
            ]);

        $this->app->instance(FalAsyncVideoService::class, $service);

        $response = $this->getJson('/api/v1/ai/generate/video/jobs/local-job-1?refresh=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', 'local-job-1')
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_fal_video_webhook_endpoint_accepts_completion_callback(): void
    {
        $service = Mockery::mock(FalAsyncVideoService::class);
        $service->shouldReceive('handleWebhook')
            ->once()
            ->withArgs(function (string $jobId, string $token, array $payload): bool {
                $this->assertSame('local-job-1', $jobId);
                $this->assertSame('secret-token', $token);
                $this->assertSame('OK', $payload['status']);

                return true;
            })
            ->andReturn([
                'job_id' => 'local-job-1',
                'status' => 'completed',
            ]);

        $this->app->instance(FalAsyncVideoService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/video/fal/webhook?job_id=local-job-1&token=secret-token', [
            'request_id' => 'fal-job-1',
            'gateway_request_id' => 'fal-job-1',
            'status' => 'OK',
            'payload' => [
                'video' => ['url' => 'https://example.com/out.mp4'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', 'local-job-1')
            ->assertJsonPath('data.status', 'completed');
    }
}
