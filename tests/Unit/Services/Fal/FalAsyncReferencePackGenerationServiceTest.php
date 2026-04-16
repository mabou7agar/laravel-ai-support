<?php

namespace LaravelAIEngine\Tests\Unit\Services\Fal;

use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService;
use LaravelAIEngine\Services\Fal\FalReferencePackGenerationService;
use LaravelAIEngine\Services\JobStatusTracker;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class FalAsyncReferencePackGenerationServiceTest extends TestCase
{
    public function test_submit_tracks_first_async_image_step(): void
    {
        config()->set('app.url', 'https://app.test');

        $selectedLooks = [
            [
                'id' => 'business-street-look',
                'name' => 'Commercial Street Business Look',
                'instruction' => 'Tailored city business styling.',
                'is_primary' => true,
            ],
            [
                'id' => 'airport-disguise',
                'name' => 'Airport Security Disguise',
                'instruction' => 'Travel disguise styling.',
            ],
        ];

        $workflow = [
            [
                'step' => 1,
                'label' => 'Look 1: Signature / Front portrait',
                'model' => EntityEnum::FAL_NANO_BANANA_2,
                'look_mode' => 'strict_selected_set',
                'look_index' => 1,
                'look_label' => 'Signature look',
                'look_variant' => 'signature',
                'selected_look' => $selectedLooks[0],
                'selected_looks' => $selectedLooks,
                'view_index' => 1,
                'view' => 'front',
                'view_label' => 'Front portrait',
                'entity_type' => 'character',
            ],
            [
                'step' => 2,
                'label' => 'Look 1: Signature / Side profile',
                'model' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                'look_mode' => 'strict_selected_set',
                'look_index' => 1,
                'look_label' => 'Signature look',
                'look_variant' => 'signature',
                'selected_look' => $selectedLooks[0],
                'selected_looks' => $selectedLooks,
                'view_index' => 2,
                'view' => 'side',
                'view_label' => 'Side profile',
                'entity_type' => 'character',
            ],
            [
                'step' => 3,
                'label' => 'Look 2: Airport disguise / Front portrait',
                'model' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                'look_mode' => 'strict_selected_set',
                'look_index' => 2,
                'look_label' => 'Airport disguise',
                'look_variant' => 'airport-disguise',
                'selected_look' => $selectedLooks[1],
                'selected_looks' => $selectedLooks,
                'view_index' => 1,
                'view' => 'front',
                'view_label' => 'Front portrait',
                'entity_type' => 'character',
            ],
        ];

        $firstRequest = new AIRequest(
            prompt: 'Generate Mina preview',
            engine: 'fal_ai',
            model: EntityEnum::FAL_NANO_BANANA_2,
            parameters: ['frame_count' => 1]
        );

        $referenceService = Mockery::mock(FalReferencePackGenerationService::class);
        $referenceService->shouldReceive('prepareWorkflow')->once()->andReturn($workflow);
        $referenceService->shouldReceive('initializeGeneratedImages')->once()->andReturn([]);
        $referenceService->shouldReceive('prepareStepRequest')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId, array $step, array $generatedImages) use ($workflow): bool {
                $this->assertSame('Generate Mina preview', $prompt);
                $this->assertTrue($options['preview_only']);
                $this->assertSame('character', $options['entity_type']);
                $this->assertNull($userId);
                $this->assertSame($workflow[0], $step);
                $this->assertSame([], $generatedImages);

                return true;
            })
            ->andReturn($firstRequest);

        $driver = Mockery::mock(FalAIEngineDriver::class);
        $driver->shouldReceive('validateRequest')->once()->with($firstRequest);
        $driver->shouldReceive('submitImageAsync')
            ->once()
            ->withArgs(function (AIRequest $request, ?string $webhookUrl) use ($firstRequest): bool {
                $this->assertSame($firstRequest, $request);
                $this->assertSame('https://app.test/api/v1/ai/generate/preview/fal/webhook', strtok((string) $webhookUrl, '?'));

                return true;
            })
            ->andReturn([
                'request_id' => 'fal-image-1',
                'status_url' => 'https://queue.fal.run/status/fal-image-1',
                'response_url' => 'https://queue.fal.run/response/fal-image-1',
                'operation' => [
                    'endpoint' => EntityEnum::FAL_NANO_BANANA_2,
                    'resolved_model' => EntityEnum::FAL_NANO_BANANA_2,
                    'payload' => ['prompt' => 'Generate Mina preview'],
                ],
            ]);

        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')->once()->andReturn($driver);

        $service = new FalAsyncReferencePackGenerationService(
            $referenceService,
            $registry,
            app(JobStatusTracker::class),
            app(CreditManager::class)
        );

        $submitted = $service->submit('Generate Mina preview', [
            'entity_type' => 'character',
            'preview_only' => true,
        ]);

        $this->assertSame('queued', $submitted['status']['status']);
        $this->assertSame('strict_selected_set', $submitted['status']['metadata']['look_mode']);
        $this->assertSame(2, $submitted['status']['metadata']['look_count']);
        $this->assertSame(2, $submitted['status']['metadata']['frames_per_look']);
        $this->assertSame(
            ['business-street-look', 'airport-disguise'],
            $submitted['status']['metadata']['selected_look_ids']
        );
        $this->assertSame('fal-image-1', $submitted['status']['metadata']['provider']['request_id']);
        $this->assertArrayNotHasKey('token', $submitted['status']['metadata']['webhook']);
    }

    public function test_handle_webhook_advances_multi_step_workflow_and_completes(): void
    {
        $tracker = app(JobStatusTracker::class);

        $workflow = [
            [
                'step' => 1,
                'label' => 'Look 1: Signature / Front portrait',
                'model' => EntityEnum::FAL_NANO_BANANA_2,
                'look_mode' => 'strict_stored',
                'look_index' => 1,
                'look_label' => 'Signature look',
                'look_variant' => 'signature',
                'selected_look' => [
                    'id' => 'mina-signature',
                    'name' => 'Mina Signature Look',
                    'instruction' => 'Keep Mina in her signature styling.',
                    'is_primary' => true,
                ],
                'selected_looks' => [[
                    'id' => 'mina-signature',
                    'name' => 'Mina Signature Look',
                    'instruction' => 'Keep Mina in her signature styling.',
                    'is_primary' => true,
                ]],
                'view_index' => 1,
                'view' => 'front',
                'view_label' => 'Front portrait',
                'entity_type' => 'character',
            ],
            [
                'step' => 2,
                'label' => 'Look 1: Signature / Side profile',
                'model' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                'look_mode' => 'strict_stored',
                'look_index' => 1,
                'look_label' => 'Signature look',
                'look_variant' => 'signature',
                'selected_look' => [
                    'id' => 'mina-signature',
                    'name' => 'Mina Signature Look',
                    'instruction' => 'Keep Mina in her signature styling.',
                    'is_primary' => true,
                ],
                'selected_looks' => [[
                    'id' => 'mina-signature',
                    'name' => 'Mina Signature Look',
                    'instruction' => 'Keep Mina in her signature styling.',
                    'is_primary' => true,
                ]],
                'view_index' => 2,
                'view' => 'side',
                'view_label' => 'Side profile',
                'entity_type' => 'character',
            ],
        ];

        $secondRequest = new AIRequest(
            prompt: 'Generate Mina side profile',
            engine: 'fal_ai',
            model: EntityEnum::FAL_NANO_BANANA_2_EDIT,
            parameters: [
                'mode' => 'edit',
                'source_images' => ['https://example.com/mina-front.png'],
            ]
        );

        $firstResponse = AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2)
            ->withMetadata([
                'images' => [['url' => 'https://example.com/mina-front.png']],
            ]);

        $secondResponse = AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)
            ->withMetadata([
                'images' => [['url' => 'https://example.com/mina-side.png']],
            ]);

        $finalWorkflowResponse = AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2)
            ->withFiles([
                'https://example.com/mina-front.png',
                'https://example.com/mina-side.png',
            ])
            ->withMetadata([
                'workflow' => 'reference_pack',
                'images' => [
                    ['url' => 'https://example.com/mina-front.png'],
                    ['url' => 'https://example.com/mina-side.png'],
                ],
            ]);

        $referenceService = Mockery::mock(FalReferencePackGenerationService::class);
        $referenceService->shouldReceive('extractGeneratedImageRecord')
            ->once()
            ->with($firstResponse, $workflow[0], 1)
            ->andReturn([
                'url' => 'https://example.com/mina-front.png',
                'source_url' => 'https://example.com/mina-front.png',
                'entity_type' => 'character',
                'look_index' => 1,
                'look_variant' => 'signature',
                'look_label' => 'Signature look',
                'view_index' => 1,
                'view' => 'front',
                'view_label' => 'Front portrait',
                'label' => 'Look 1: Signature / Front portrait',
                'step' => 1,
            ]);
        $referenceService->shouldReceive('prepareStepRequest')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId, array $step, array $generatedImages): bool {
                $this->assertSame('Generate Mina', $prompt);
                $this->assertSame('character', $options['entity_type']);
                $this->assertNull($userId);
                $this->assertSame(EntityEnum::FAL_NANO_BANANA_2_EDIT, $step['model']);
                $this->assertSame('https://example.com/mina-front.png', $generatedImages[0]['url']);

                return true;
            })
            ->andReturn($secondRequest);
        $referenceService->shouldReceive('extractGeneratedImageRecord')
            ->once()
            ->with($secondResponse, $workflow[1], 2)
            ->andReturn([
                'url' => 'https://example.com/mina-side.png',
                'source_url' => 'https://example.com/mina-side.png',
                'entity_type' => 'character',
                'look_index' => 1,
                'look_variant' => 'signature',
                'look_label' => 'Signature look',
                'view_index' => 2,
                'view' => 'side',
                'view_label' => 'Side profile',
                'label' => 'Look 1: Signature / Side profile',
                'step' => 2,
            ]);
        $referenceService->shouldReceive('finalizeStoredResult')
            ->once()
            ->withArgs(function (array $generatedImages, array $options, float $credits): bool {
                $this->assertCount(2, $generatedImages);
                $this->assertSame('character', $options['entity_type']);
                $this->assertSame(0.0, $credits);

                return true;
            })
            ->andReturn([
                'alias' => 'mina-preview',
                'reference_pack' => [
                    'alias' => 'mina-preview',
                    'frontal_image_url' => 'https://example.com/mina-front.png',
                    'reference_image_urls' => ['https://example.com/mina-side.png'],
                ],
                'character' => [
                    'alias' => 'mina-preview',
                    'frontal_image_url' => 'https://example.com/mina-front.png',
                    'reference_image_urls' => ['https://example.com/mina-side.png'],
                ],
                'response' => $finalWorkflowResponse,
            ]);

        $driver = Mockery::mock(FalAIEngineDriver::class);
        $driver->shouldReceive('buildImageResponseFromOperation')
            ->once()
            ->andReturn($firstResponse);
        $driver->shouldReceive('validateRequest')->once()->with($secondRequest);
        $driver->shouldReceive('submitImageAsync')
            ->once()
            ->withArgs(function (AIRequest $request, ?string $webhookUrl) use ($secondRequest): bool {
                $this->assertSame($secondRequest, $request);
                $this->assertSame('https://app.test/api/v1/ai/generate/reference-pack/fal/webhook?job_id=local-job-1&token=secret-token', $webhookUrl);

                return true;
            })
            ->andReturn([
                'request_id' => 'fal-image-2',
                'status_url' => 'https://queue.fal.run/status/fal-image-2',
                'response_url' => 'https://queue.fal.run/response/fal-image-2',
                'operation' => [
                    'endpoint' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                    'resolved_model' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                    'payload' => ['prompt' => 'Generate Mina side profile'],
                ],
            ]);
        $driver->shouldReceive('buildImageResponseFromOperation')
            ->once()
            ->andReturn($secondResponse);

        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')->times(3)->andReturn($driver);

        $service = new FalAsyncReferencePackGenerationService(
            $referenceService,
            $registry,
            $tracker,
            app(CreditManager::class)
        );

        $tracker->updateStatus('local-job-1', 'queued', [
            'prompt' => 'Generate Mina',
            'user_id' => null,
            'options' => [
                'entity_type' => 'character',
            ],
            'workflow' => $workflow,
            'total_steps' => 2,
            'generated_images' => [],
            'credits' => [
                'charged_steps' => [],
                'total_charged' => 0.0,
            ],
            'webhook' => [
                'url' => 'https://app.test/api/v1/ai/generate/reference-pack/fal/webhook?job_id=local-job-1&token=secret-token',
                'token' => 'secret-token',
                'enabled' => true,
            ],
            'current_step_index' => 0,
            'current_request' => [
                'prompt' => 'Generate Mina',
                'engine' => 'fal_ai',
                'model' => EntityEnum::FAL_NANO_BANANA_2,
                'parameters' => ['frame_count' => 1],
            ],
            'operation' => [
                'endpoint' => EntityEnum::FAL_NANO_BANANA_2,
                'resolved_model' => EntityEnum::FAL_NANO_BANANA_2,
                'payload' => ['prompt' => 'Generate Mina'],
            ],
            'provider' => [
                'status_url' => 'https://queue.fal.run/status/fal-image-1',
                'response_url' => 'https://queue.fal.run/response/fal-image-1',
            ],
            'steps' => [],
        ]);

        $intermediate = $service->handleWebhook('local-job-1', 'secret-token', [
            'status' => 'OK',
            'payload' => [
                'images' => [['url' => 'https://example.com/mina-front.png']],
            ],
        ]);

        $this->assertSame('processing', $intermediate['status']);
        $this->assertSame(1, $intermediate['metadata']['current_step_index']);
        $this->assertSame('fal-image-2', $intermediate['metadata']['provider']['request_id']);

        $completed = $service->handleWebhook('local-job-1', 'secret-token', [
            'status' => 'OK',
            'payload' => [
                'images' => [['url' => 'https://example.com/mina-side.png']],
            ],
        ]);

        $this->assertSame('completed', $completed['status']);
        $this->assertSame('strict_stored', $completed['metadata']['look_mode']);
        $this->assertSame(1, $completed['metadata']['look_count']);
        $this->assertSame(2, $completed['metadata']['frames_per_look']);
        $this->assertSame(['mina-signature'], $completed['metadata']['selected_look_ids']);
        $this->assertSame('mina-preview', $completed['metadata']['alias']);
        $this->assertSame('https://example.com/mina-front.png', $completed['metadata']['reference_pack']['frontal_image_url']);
        $this->assertSame('https://example.com/mina-side.png', $completed['metadata']['response']['metadata']['images'][1]['url']);
    }

    public function test_handle_webhook_retries_current_step_with_provider_urls_after_stored_url_failure(): void
    {
        $tracker = app(JobStatusTracker::class);

        $workflow = [
            [
                'step' => 1,
                'label' => 'Look 1: Signature / Front portrait',
                'model' => EntityEnum::FAL_NANO_BANANA_2,
                'look_index' => 1,
                'look_label' => 'Signature look',
                'look_variant' => 'signature',
                'view_index' => 1,
                'view' => 'front',
                'view_label' => 'Front portrait',
                'entity_type' => 'character',
            ],
            [
                'step' => 2,
                'label' => 'Look 1: Signature / Side profile',
                'model' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                'look_index' => 1,
                'look_label' => 'Signature look',
                'look_variant' => 'signature',
                'view_index' => 2,
                'view' => 'side',
                'view_label' => 'Side profile',
                'entity_type' => 'character',
            ],
        ];

        $providerRetryRequest = new AIRequest(
            prompt: 'Generate Mina side profile',
            engine: 'fal_ai',
            model: EntityEnum::FAL_NANO_BANANA_2_EDIT,
            parameters: [
                'mode' => 'edit',
                'source_images' => ['https://v3.fal.media/files/mina-front.png'],
            ]
        );

        $referenceService = Mockery::mock(FalReferencePackGenerationService::class);
        $referenceService->shouldReceive('hasProviderFallbackForStep')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId, array $step, array $generatedImages) use ($workflow): bool {
                $this->assertSame('Generate Mina', $prompt);
                $this->assertSame('character', $options['entity_type']);
                $this->assertNull($userId);
                $this->assertSame($workflow[1], $step);
                $this->assertSame('https://app.test/storage/generated/mina-front.png', $generatedImages[0]['stored_url']);
                $this->assertSame('https://v3.fal.media/files/mina-front.png', $generatedImages[0]['provider_url']);

                return true;
            })
            ->andReturn(true);
        $referenceService->shouldReceive('prepareStepRequest')
            ->once()
            ->withArgs(function (
                string $prompt,
                array $options,
                ?string $userId,
                array $step,
                array $generatedImages,
                string $urlStrategy
            ) use ($workflow): bool {
                $this->assertSame('Generate Mina', $prompt);
                $this->assertSame('character', $options['entity_type']);
                $this->assertNull($userId);
                $this->assertSame($workflow[1], $step);
                $this->assertSame('provider', $urlStrategy);
                $this->assertSame('https://v3.fal.media/files/mina-front.png', $generatedImages[0]['provider_url']);

                return true;
            })
            ->andReturn($providerRetryRequest);

        $driver = Mockery::mock(FalAIEngineDriver::class);
        $driver->shouldReceive('validateRequest')->once()->with($providerRetryRequest);
        $driver->shouldReceive('submitImageAsync')
            ->once()
            ->withArgs(function (AIRequest $request, ?string $webhookUrl) use ($providerRetryRequest): bool {
                $this->assertSame($providerRetryRequest, $request);
                $this->assertSame('https://app.test/api/v1/ai/generate/reference-pack/fal/webhook?job_id=local-job-2&token=secret-token', $webhookUrl);

                return true;
            })
            ->andReturn([
                'request_id' => 'fal-image-2-retry',
                'status_url' => 'https://queue.fal.run/status/fal-image-2-retry',
                'response_url' => 'https://queue.fal.run/response/fal-image-2-retry',
                'operation' => [
                    'endpoint' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                    'resolved_model' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                    'payload' => ['prompt' => 'Generate Mina side profile'],
                ],
            ]);

        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')->once()->andReturn($driver);

        $service = new FalAsyncReferencePackGenerationService(
            $referenceService,
            $registry,
            $tracker,
            app(CreditManager::class)
        );

        $tracker->updateStatus('local-job-2', 'processing', [
            'prompt' => 'Generate Mina',
            'user_id' => null,
            'options' => [
                'entity_type' => 'character',
            ],
            'workflow' => $workflow,
            'total_steps' => 2,
            'generated_images' => [[
                'url' => 'https://app.test/storage/generated/mina-front.png',
                'stored_url' => 'https://app.test/storage/generated/mina-front.png',
                'source_url' => 'https://v3.fal.media/files/mina-front.png',
                'provider_url' => 'https://v3.fal.media/files/mina-front.png',
                'entity_type' => 'character',
                'look_index' => 1,
                'look_variant' => 'signature',
                'look_label' => 'Signature look',
                'view_index' => 1,
                'view' => 'front',
                'view_label' => 'Front portrait',
                'label' => 'Look 1: Signature / Front portrait',
                'step' => 1,
            ]],
            'credits' => [
                'charged_steps' => [],
                'total_charged' => 0.0,
            ],
            'webhook' => [
                'url' => 'https://app.test/api/v1/ai/generate/reference-pack/fal/webhook?job_id=local-job-2&token=secret-token',
                'token' => 'secret-token',
                'enabled' => true,
            ],
            'current_step_index' => 1,
            'current_step' => 2,
            'current_step_label' => 'Look 1: Signature / Side profile',
            'current_url_strategy' => 'stored',
            'current_request' => [
                'prompt' => 'Generate Mina side profile',
                'engine' => 'fal_ai',
                'model' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                'parameters' => [
                    'mode' => 'edit',
                    'source_images' => ['https://app.test/storage/generated/mina-front.png'],
                ],
            ],
            'operation' => [
                'endpoint' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                'resolved_model' => EntityEnum::FAL_NANO_BANANA_2_EDIT,
                'payload' => ['prompt' => 'Generate Mina side profile'],
            ],
            'provider' => [
                'status_url' => 'https://queue.fal.run/status/fal-image-2',
                'response_url' => 'https://queue.fal.run/response/fal-image-2',
                'request_id' => 'fal-image-2',
            ],
            'steps' => [
                1 => [
                    'step' => 2,
                    'label' => 'Look 1: Signature / Side profile',
                    'status' => 'processing',
                    'url_strategy' => 'stored',
                ],
            ],
        ]);

        $retried = $service->handleWebhook('local-job-2', 'secret-token', [
            'status' => 'ERROR',
            'error' => 'Stored URL could not be fetched by FAL',
        ]);

        $this->assertSame('processing', $retried['status']);
        $this->assertSame('provider', $retried['metadata']['current_url_strategy']);
        $this->assertSame('fal-image-2-retry', $retried['metadata']['provider']['request_id']);
        $this->assertSame('provider', $retried['metadata']['steps'][1]['url_strategy']);
        $this->assertTrue($retried['metadata']['steps'][1]['retrying_with_provider_url']);
        $this->assertSame('Stored URL could not be fetched by FAL', $retried['metadata']['steps'][1]['stored_url_error']);
        $this->assertSame(
            ['https://v3.fal.media/files/mina-front.png'],
            $retried['metadata']['current_request']['parameters']['source_images']
        );
    }
}
