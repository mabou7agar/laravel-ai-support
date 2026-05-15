<?php

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Console\Commands\TestFalMediaCommand;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Fal\FalAsyncVideoService;
use LaravelAIEngine\Services\Fal\FalMediaWorkflowService;
use LaravelAIEngine\Support\Fal\FalCharacterStore;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class TestFalMediaCommandTest extends TestCase
{
    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(TestFalMediaCommand::class));
    }

    public function test_command_signature(): void
    {
        $command = new TestFalMediaCommand();

        $this->assertSame('ai:test-fal-media', $command->getName());
    }

    public function test_dry_run_shows_prepared_request_without_calling_service(): void
    {
        $service = Mockery::mock(FalMediaWorkflowService::class);
        $service->shouldReceive('prepareRequest')
            ->once()
            ->andReturn(new AIRequest('Create hero keyframes', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2, [
                'frame_count' => 2,
                'aspect_ratio' => '16:9',
            ], 'demo-user'));
        $service->shouldNotReceive('generate');
        $this->app->instance(FalMediaWorkflowService::class, $service);

        $exitCode = Artisan::call('ai:test-fal-media', [
            'prompt' => 'Create hero keyframes',
            '--frame-count' => 2,
            '--aspect-ratio' => '16:9',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Prepared FAL media request', $output);
        $this->assertStringContainsString(EntityEnum::FAL_NANO_BANANA_2, $output);
        $this->assertStringContainsString('"frame_count": 2', $output);
    }

    public function test_command_sends_nano_banana_edit_request_with_character_sources(): void
    {
        $service = Mockery::mock(FalMediaWorkflowService::class);
        $service->shouldReceive('prepareRequest')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Turn this into a cinematic portrait', $prompt);
                $this->assertSame(['https://example.com/base.png'], $options['source_images']);
                $this->assertCount(1, $options['character_sources']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn(new AIRequest('Turn this into a cinematic portrait', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT, [
                'source_images' => ['https://example.com/base.png'],
                'character_sources' => [
                    ['name' => 'Mina', 'frontal_image_url' => 'https://example.com/front.png'],
                ],
            ], 'demo-user'));
        $service->shouldReceive('generate')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Turn this into a cinematic portrait', $prompt);
                $this->assertSame(['https://example.com/base.png'], $options['source_images']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn([
                'response' => AIResponse::success(
                    '{"images":[{"url":"https://example.com/out.png"}]}',
                    'fal_ai',
                    EntityEnum::FAL_NANO_BANANA_2_EDIT
                )->withFiles(['/storage/ai-generated/fal-ai/images/out.png']),
            ]);

        $this->app->instance(FalMediaWorkflowService::class, $service);

        $exitCode = Artisan::call('ai:test-fal-media', [
            'prompt' => 'Turn this into a cinematic portrait',
            '--source-image' => ['https://example.com/base.png'],
            '--character' => ['{"name":"Mina","frontal_image_url":"https://example.com/front.png"}'],
        ]);

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('FAL media request succeeded', $output);
        $this->assertStringContainsString('/storage/ai-generated/fal-ai/images/out.png', $output);
    }

    public function test_command_auto_resolves_kling_reference_model_from_reference_images(): void
    {
        $service = Mockery::mock(FalMediaWorkflowService::class);
        $service->shouldReceive('prepareRequest')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Make the scene move like a trailer shot', $prompt);
                $this->assertSame(['https://example.com/ref.png'], $options['reference_image_urls']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn(new AIRequest('Make the scene move like a trailer shot', 'fal_ai', EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO, [
                'reference_image_urls' => ['https://example.com/ref.png'],
            ], 'demo-user'));
        $service->shouldReceive('generate')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Make the scene move like a trailer shot', $prompt);
                $this->assertSame(['https://example.com/ref.png'], $options['reference_image_urls']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn([
                'response' => AIResponse::success(
                    '{"video":{"url":"https://example.com/out.mp4"}}',
                    'fal_ai',
                    EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO
                ),
            ]);

        $this->app->instance(FalMediaWorkflowService::class, $service);

        $exitCode = Artisan::call('ai:test-fal-media', [
            'prompt' => 'Make the scene move like a trailer shot',
            '--reference-image-url' => ['https://example.com/ref.png'],
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_command_passes_multi_prompt_shots(): void
    {
        $service = Mockery::mock(FalMediaWorkflowService::class);
        $service->shouldReceive('prepareRequest')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('', $prompt);
                $this->assertCount(2, $options['character_sources']);
                $this->assertSame([
                    ['prompt' => '@Element1 enters the cafe', 'duration' => '3'],
                    ['prompt' => '@Element1 sits beside @Element2', 'duration' => '5'],
                ], $options['multi_prompt']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn(new AIRequest('', 'fal_ai', EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO, [
                'multi_prompt' => [
                    ['prompt' => '@Element1 enters the cafe', 'duration' => '3'],
                    ['prompt' => '@Element1 sits beside @Element2', 'duration' => '5'],
                ],
            ], 'demo-user'));
        $service->shouldReceive('generate')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('', $prompt);
                $this->assertCount(2, $options['character_sources']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn([
                'response' => AIResponse::success(
                    '{"video":{"url":"https://example.com/out.mp4"}}',
                    'fal_ai',
                    EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO
                ),
            ]);

        $this->app->instance(FalMediaWorkflowService::class, $service);

        $exitCode = Artisan::call('ai:test-fal-media', [
            '--character' => [
                '{"name":"Mina","frontal_image_url":"https://example.com/mina.png"}',
                '{"name":"Ray","frontal_image_url":"https://example.com/ray.png"}',
            ],
            '--shot' => [
                '{"prompt":"@Element1 enters the cafe","duration":3}',
                '{"prompt":"@Element1 sits beside @Element2","duration":5}',
            ],
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_command_can_reuse_last_generated_character(): void
    {
        app(FalCharacterStore::class)->save([
            'name' => 'Mina',
            'frontal_image_url' => 'https://example.com/mina.png',
            'reference_image_urls' => ['https://example.com/mina-side.png'],
            'metadata' => ['source' => 'test'],
        ], 'mina');

        $service = Mockery::mock(FalMediaWorkflowService::class);
        $service->shouldReceive('prepareRequest')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Make Mina walk toward camera', $prompt);
                $this->assertTrue($options['use_last_character']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn(new AIRequest('Make Mina walk toward camera', 'fal_ai', EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO, [
                'character_sources' => [
                    ['frontal_image_url' => 'https://example.com/mina.png'],
                ],
            ], 'demo-user'));
        $service->shouldReceive('generate')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Make Mina walk toward camera', $prompt);
                $this->assertTrue($options['use_last_character']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn([
                'response' => AIResponse::success(
                    '{"video":{"url":"https://example.com/out.mp4"}}',
                    'fal_ai',
                    EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO
                ),
            ]);

        $this->app->instance(FalMediaWorkflowService::class, $service);

        $exitCode = Artisan::call('ai:test-fal-media', [
            'prompt' => 'Make Mina walk toward camera',
            '--use-last-character' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_command_can_submit_async_video_job(): void
    {
        $mediaService = Mockery::mock(FalMediaWorkflowService::class);
        $mediaService->shouldReceive('prepareRequest')
            ->once()
            ->andReturn(new AIRequest(
                'Create a neon city chase',
                'fal_ai',
                EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO,
                ['duration' => '5']
            ));
        $mediaService->shouldNotReceive('generate');
        $this->app->instance(FalMediaWorkflowService::class, $mediaService);

        $asyncService = Mockery::mock(FalAsyncVideoService::class);
        $asyncService->shouldReceive('submit')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Create a neon city chase', $prompt);
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
                'webhook_url' => 'https://app.test/api/v1/ai/generate/video/fal/webhook?job_id=local-job-1',
            ]);
        $this->app->instance(FalAsyncVideoService::class, $asyncService);

        $exitCode = Artisan::call('ai:test-fal-media', [
            'prompt' => 'Create a neon city chase',
            '--model' => EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO,
            '--async' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_command_fails_for_invalid_character_json(): void
    {
        $exitCode = Artisan::call('ai:test-fal-media', [
            'prompt' => 'Create a character turnaround',
            '--character' => ['{invalid-json}'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must be valid JSON', Artisan::output());
    }
}
