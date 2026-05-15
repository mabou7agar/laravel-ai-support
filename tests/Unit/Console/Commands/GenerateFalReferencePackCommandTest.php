<?php

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Console\Commands\GenerateFalReferencePackCommand;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService;
use LaravelAIEngine\Services\Fal\FalReferencePackGenerationService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class GenerateFalReferencePackCommandTest extends TestCase
{
    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(GenerateFalReferencePackCommand::class));
    }

    public function test_dry_run_prepares_generic_furniture_reference_pack_workflow(): void
    {
        $service = Mockery::mock(FalReferencePackGenerationService::class);
        $service->shouldReceive('prepareWorkflow')
            ->once()
            ->andReturn([
                [
                    'step' => 1,
                    'look_index' => 1,
                    'look_variant' => 'signature',
                    'view' => 'front',
                    'label' => 'Look 1: Primary look / Front view',
                    'model' => EntityEnum::FAL_NANO_BANANA_2,
                    'parameters' => [
                        'frame_count' => 1,
                        'image_count' => 1,
                    ],
                ],
            ]);
        $service->shouldNotReceive('generateAndStore');

        $asyncService = Mockery::mock(FalAsyncReferencePackGenerationService::class);
        $asyncService->shouldNotReceive('submit');
        $asyncService->shouldNotReceive('getStatus');

        $this->app->instance(FalReferencePackGenerationService::class, $service);
        $this->app->instance(FalAsyncReferencePackGenerationService::class, $asyncService);

        $exitCode = Artisan::call('ai:generate-reference-pack', [
            'prompt' => 'Modern curved couch',
            '--entity-type' => 'furniture',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"entity_type": "furniture"', $output);
        $this->assertStringContainsString('"look_count": 1', $output);
        $this->assertStringContainsString('"frames_per_look": 1', $output);
        $this->assertStringContainsString('"requested_views": 1', $output);
    }

    public function test_command_queues_reference_pack_by_default(): void
    {
        $service = Mockery::mock(FalReferencePackGenerationService::class);
        $service->shouldReceive('prepareWorkflow')
            ->once()
            ->andReturn([
                [
                    'step' => 1,
                    'look_index' => 1,
                    'look_variant' => 'signature',
                    'view' => 'front',
                    'label' => 'Look 1: Primary look / Front view',
                    'model' => EntityEnum::FAL_NANO_BANANA_2,
                    'parameters' => [
                        'frame_count' => 1,
                        'image_count' => 1,
                    ],
                ],
            ]);
        $service->shouldNotReceive('generateAndStore');

        $asyncService = Mockery::mock(FalAsyncReferencePackGenerationService::class);
        $asyncService->shouldReceive('submit')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Modern curved couch', $prompt);
                $this->assertSame('furniture', $options['entity_type']);
                $this->assertNull($userId);
                return true;
            })
            ->andReturn([
                'job_id' => 'job-456',
                'status' => [
                    'job_id' => 'job-456',
                    'status' => 'queued',
                ],
            ]);

        $this->app->instance(FalReferencePackGenerationService::class, $service);
        $this->app->instance(FalAsyncReferencePackGenerationService::class, $asyncService);

        $exitCode = Artisan::call('ai:generate-reference-pack', [
            'prompt' => 'Modern curved couch',
            '--entity-type' => 'furniture',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"job_id": "job-456"', Artisan::output());
    }

    public function test_command_accepts_selected_look_options(): void
    {
        $service = Mockery::mock(FalReferencePackGenerationService::class);
        $service->shouldReceive('prepareWorkflow')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Generate Mina', $prompt);
                $this->assertSame('festival_blue', $options['look_id']);
                $this->assertSame('Festival Blue', $options['look_payload']['label']);
                $this->assertSame('Keep the blue styling direction consistent across every view.', $options['look_payload']['instruction']);
                $this->assertSame('strict_stored', $options['look_mode']);
                $this->assertTrue($options['strict_stored_looks']);
                $this->assertNull($userId);

                return true;
            })
            ->andReturn([
                [
                    'step' => 1,
                    'look_mode' => 'strict_stored',
                    'look_index' => 1,
                    'look_variant' => 'festival_blue',
                    'view' => 'front',
                    'label' => 'Look 1: Festival Blue / Front portrait',
                    'model' => EntityEnum::FAL_NANO_BANANA_2,
                    'parameters' => [
                        'frame_count' => 1,
                        'image_count' => 1,
                    ],
                ],
            ]);
        $service->shouldNotReceive('generateAndStore');

        $asyncService = Mockery::mock(FalAsyncReferencePackGenerationService::class);
        $asyncService->shouldReceive('submit')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('festival_blue', $options['look_id']);
                $this->assertSame('Festival Blue', $options['look_payload']['label']);
                $this->assertSame('strict_stored', $options['look_mode']);
                $this->assertTrue($options['strict_stored_looks']);
                $this->assertNull($userId);

                return true;
            })
            ->andReturn([
                'job_id' => 'job-look-123',
                'status' => [
                    'job_id' => 'job-look-123',
                    'status' => 'queued',
                ],
            ]);

        $this->app->instance(FalReferencePackGenerationService::class, $service);
        $this->app->instance(FalAsyncReferencePackGenerationService::class, $asyncService);

        $exitCode = Artisan::call('ai:generate-reference-pack', [
            'prompt' => 'Generate Mina',
            '--look-id' => 'festival_blue',
            '--look-payload' => '{"label":"Festival Blue","instruction":"Keep the blue styling direction consistent across every view."}',
            '--look-mode' => 'strict_stored',
            '--strict-stored-looks' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"job_id": "job-look-123"', Artisan::output());
    }

    public function test_command_accepts_selected_look_set_json(): void
    {
        $service = Mockery::mock(FalReferencePackGenerationService::class);
        $service->shouldReceive('prepareWorkflow')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Generate Mina set', $prompt);
                $this->assertCount(2, $options['selected_looks']);
                $this->assertSame('business-street-look', $options['selected_looks'][0]['id']);
                $this->assertSame('airport-disguise', $options['selected_looks'][1]['id']);
                $this->assertNull($userId);

                return true;
            })
            ->andReturn([
                [
                    'step' => 1,
                    'look_mode' => 'strict_selected_set',
                    'look_index' => 1,
                    'look_variant' => 'business-street-look',
                    'view' => 'front',
                    'label' => 'Look 1: Commercial Street Business Look / Front portrait',
                    'model' => EntityEnum::FAL_NANO_BANANA_2,
                    'parameters' => [
                        'frame_count' => 1,
                        'image_count' => 1,
                    ],
                ],
            ]);
        $service->shouldNotReceive('generateAndStore');

        $asyncService = Mockery::mock(FalAsyncReferencePackGenerationService::class);
        $asyncService->shouldReceive('submit')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertCount(2, $options['selected_looks']);
                $this->assertSame('business-street-look', $options['selected_looks'][0]['id']);
                $this->assertSame('airport-disguise', $options['selected_looks'][1]['id']);
                $this->assertNull($userId);

                return true;
            })
            ->andReturn([
                'job_id' => 'job-look-set-123',
                'status' => [
                    'job_id' => 'job-look-set-123',
                    'status' => 'queued',
                ],
            ]);

        $this->app->instance(FalReferencePackGenerationService::class, $service);
        $this->app->instance(FalAsyncReferencePackGenerationService::class, $asyncService);

        $exitCode = Artisan::call('ai:generate-reference-pack', [
            'prompt' => 'Generate Mina set',
            '--look-set' => '[{"id":"business-street-look","name":"Commercial Street Business Look","instruction":"Keep the commercial business wardrobe locked."},{"id":"airport-disguise","name":"Airport Security Disguise","instruction":"Keep the airport disguise wardrobe locked."}]',
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('"look_count": 1', $output);
        $this->assertStringContainsString('"selected_look_ids": [', $output);
        $this->assertStringContainsString('"job_id": "job-look-set-123"', $output);
    }
}
