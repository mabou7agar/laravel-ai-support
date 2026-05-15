<?php

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Console\Commands\GenerateFalCharacterCommand;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Fal\FalAsyncCharacterGenerationService;
use LaravelAIEngine\Services\Fal\FalCharacterGenerationService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class GenerateFalCharacterCommandTest extends TestCase
{
    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(GenerateFalCharacterCommand::class));
    }

    public function test_command_signature(): void
    {
        $command = new GenerateFalCharacterCommand();

        $this->assertSame('ai:generate-character', $command->getName());
    }

    public function test_dry_run_prepares_grouped_character_generation_workflow(): void
    {
        $service = Mockery::mock(FalCharacterGenerationService::class);
        $service->shouldReceive('prepareWorkflow')
            ->once()
            ->andReturn([
                [
                    'step' => 1,
                    'look_index' => 1,
                    'look_label' => 'Signature look',
                    'look_variant' => 'signature',
                    'view' => 'front',
                    'label' => 'Look 1: Signature look / Front portrait',
                    'model' => EntityEnum::FAL_NANO_BANANA_2,
                    'parameters' => [
                        'frame_count' => 1,
                        'image_count' => 1,
                    ],
                ],
                [
                    'step' => 5,
                    'look_index' => 2,
                    'look_label' => 'Hair and makeup variant',
                    'look_variant' => 'beauty_variant',
                    'view' => 'front',
                    'label' => 'Look 2: Hair and makeup variant / Front portrait',
                    'model' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                    'parameters' => [
                        'frame_count' => 1,
                        'image_count' => 1,
                        'mode' => 'edit',
                    ],
                ],
            ]);
        $service->shouldNotReceive('generateAndStore');

        $asyncService = Mockery::mock(FalAsyncCharacterGenerationService::class);
        $asyncService->shouldNotReceive('submit');
        $asyncService->shouldNotReceive('getStatus');

        $this->app->instance(FalCharacterGenerationService::class, $service);
        $this->app->instance(FalAsyncCharacterGenerationService::class, $asyncService);

        $exitCode = Artisan::call('ai:generate-character', [
            'prompt' => 'Front-facing portrait of Mina',
            '--frame-count' => 8,
            '--look-size' => 4,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"requested_views": 2', $output);
        $this->assertStringContainsString('"look_index": 1', $output);
        $this->assertStringContainsString('"look_index": 2', $output);
    }

    public function test_command_saves_generated_character_for_reuse(): void
    {
        $service = Mockery::mock(FalCharacterGenerationService::class);
        $service->shouldReceive('prepareWorkflow')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Front-facing portrait of Mina', $prompt);
                $this->assertSame(5, $options['frame_count']);
                $this->assertSame(4, $options['look_size']);
                $this->assertSame('voice-mina', $options['voice_id']);
                $this->assertSame(0.33, $options['voice_settings']['stability']);
                $this->assertSame(0.77, $options['voice_settings']['similarity_boost']);
                $this->assertSame(0.15, $options['voice_settings']['style']);
                $this->assertTrue($options['voice_settings']['use_speaker_boost']);
                $this->assertSame('42', $userId);
                return true;
            })
            ->andReturn([
                [
                    'step' => 1,
                    'look_index' => 1,
                    'look_label' => 'Signature look',
                    'look_variant' => 'signature',
                    'view' => 'front',
                    'label' => 'Look 1: Signature look / Front portrait',
                    'model' => EntityEnum::FAL_NANO_BANANA_2,
                    'parameters' => [
                        'frame_count' => 1,
                        'image_count' => 1,
                    ],
                ],
            ]);
        $service->shouldReceive('generateAndStore')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId, ?callable $progress): bool {
                $this->assertSame('Front-facing portrait of Mina', $prompt);
                $this->assertSame('Mina', $options['name']);
                $this->assertSame('hero-mina', $options['save_as']);
                $this->assertSame(4, $options['look_size']);
                $this->assertSame('voice-mina', $options['voice_id']);
                $this->assertSame(0.33, $options['voice_settings']['stability']);
                $this->assertSame('42', $userId);
                $this->assertIsCallable($progress);
                return true;
            })
            ->andReturn([
                'alias' => 'hero-mina',
                'character' => [
                    'name' => 'Mina',
                    'frontal_image_url' => 'https://example.com/mina-front.png',
                    'reference_image_urls' => [
                        'https://example.com/mina-side.png',
                        'https://example.com/mina-closeup.png',
                    ],
                    'voice_id' => 'voice-mina',
                ],
            ]);

        $asyncService = Mockery::mock(FalAsyncCharacterGenerationService::class);
        $asyncService->shouldNotReceive('submit');
        $asyncService->shouldNotReceive('getStatus');

        $this->app->instance(FalCharacterGenerationService::class, $service);
        $this->app->instance(FalAsyncCharacterGenerationService::class, $asyncService);

        $exitCode = Artisan::call('ai:generate-character', [
            'prompt' => 'Front-facing portrait of Mina',
            '--name' => 'Mina',
            '--save-as' => 'hero-mina',
            '--frame-count' => 5,
            '--look-size' => 4,
            '--user-id' => '42',
            '--voice-id' => 'voice-mina',
            '--voice-stability' => '0.33',
            '--voice-similarity-boost' => '0.77',
            '--voice-style' => '0.15',
            '--voice-speaker-boost' => 'true',
            '--sync' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString("Character saved as 'hero-mina'", $output);
        $this->assertStringContainsString('https://example.com/mina-front.png', $output);
        $this->assertStringContainsString('voice-mina', $output);
    }

    public function test_command_queues_character_workflow_by_default(): void
    {
        $service = Mockery::mock(FalCharacterGenerationService::class);
        $service->shouldReceive('prepareWorkflow')
            ->once()
            ->andReturn([
                [
                    'step' => 1,
                    'look_index' => 1,
                    'look_label' => 'Signature look',
                    'look_variant' => 'signature',
                    'view' => 'front',
                    'label' => 'Look 1: Signature look / Front portrait',
                    'model' => EntityEnum::FAL_NANO_BANANA_2,
                    'parameters' => [
                        'frame_count' => 1,
                        'image_count' => 1,
                    ],
                ],
            ]);
        $service->shouldNotReceive('generateAndStore');

        $asyncService = Mockery::mock(FalAsyncCharacterGenerationService::class);
        $asyncService->shouldReceive('submit')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Front-facing portrait of Mina', $prompt);
                $this->assertSame(8, $options['frame_count']);
                $this->assertSame(4, $options['look_size']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn([
                'job_id' => 'job-123',
                'status' => [
                    'job_id' => 'job-123',
                    'status' => 'queued',
                ],
            ]);

        $this->app->instance(FalCharacterGenerationService::class, $service);
        $this->app->instance(FalAsyncCharacterGenerationService::class, $asyncService);

        $exitCode = Artisan::call('ai:generate-character', [
            'prompt' => 'Front-facing portrait of Mina',
            '--frame-count' => 8,
            '--look-size' => 4,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"job_id": "job-123"', Artisan::output());
    }

    public function test_command_can_generate_preview_only(): void
    {
        $service = Mockery::mock(FalCharacterGenerationService::class);
        $service->shouldReceive('prepareWorkflow')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Front-facing portrait of Mina', $prompt);
                $this->assertTrue($options['preview_only']);
                return true;
            })
            ->andReturn([
                [
                    'step' => 1,
                    'look_index' => 1,
                    'look_label' => 'Signature look',
                    'look_variant' => 'signature',
                    'view' => 'front',
                    'label' => 'Look 1: Signature look / Front portrait',
                    'model' => EntityEnum::FAL_NANO_BANANA_2,
                    'parameters' => [
                        'frame_count' => 1,
                        'image_count' => 1,
                    ],
                ],
            ]);
        $service->shouldReceive('generateAndStore')
            ->once()
            ->withArgs(function (string $prompt, array $options): bool {
                $this->assertTrue($options['preview_only']);
                return true;
            })
            ->andReturn([
                'alias' => 'mina-preview',
                'character' => [
                    'name' => 'Mina',
                    'frontal_image_url' => 'https://example.com/mina-preview.png',
                    'reference_image_urls' => [],
                ],
            ]);

        $asyncService = Mockery::mock(FalAsyncCharacterGenerationService::class);
        $asyncService->shouldNotReceive('submit');
        $asyncService->shouldNotReceive('getStatus');

        $this->app->instance(FalCharacterGenerationService::class, $service);
        $this->app->instance(FalAsyncCharacterGenerationService::class, $asyncService);

        $exitCode = Artisan::call('ai:generate-character', [
            'prompt' => 'Front-facing portrait of Mina',
            '--name' => 'Mina',
            '--save-as' => 'mina-preview',
            '--preview-only' => true,
            '--sync' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('--from-character=mina-preview', Artisan::output());
    }
}
