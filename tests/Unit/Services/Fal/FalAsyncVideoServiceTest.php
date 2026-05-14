<?php

namespace LaravelAIEngine\Tests\Unit\Services\Fal;

use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Services\Fal\FalAsyncVideoService;
use LaravelAIEngine\Services\Fal\FalMediaWorkflowService;
use LaravelAIEngine\Services\JobStatusTracker;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class FalAsyncVideoServiceTest extends TestCase
{
    public function test_submit_tracks_job_and_deducts_credits_once(): void
    {
        config()->set('app.url', 'https://app.test');

        $user = $this->createTestUser([
            'entity_credits' => json_encode([
                'fal_ai' => [
                    EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO => ['balance' => 100.0, 'is_unlimited' => false],
                ],
            ]),
        ]);

        $request = new AIRequest(
            prompt: 'Animate this still image',
            engine: 'fal_ai',
            model: EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            parameters: ['start_image_url' => 'https://example.com/start.png'],
            userId: (string) $user->id
        );

        $workflow = Mockery::mock(FalMediaWorkflowService::class);
        $workflow->shouldReceive('prepareRequest')->once()->andReturn($request);

        $driver = Mockery::mock(FalAIEngineDriver::class);
        $driver->shouldReceive('validateRequest')->once()->with($request);
        $driver->shouldReceive('submitVideoAsync')
            ->once()
            ->withArgs(function (AIRequest $submittedRequest, ?string $webhookUrl) use ($request): bool {
                $this->assertSame('https://app.test/api/v1/ai/generate/video/fal/webhook', strtok((string) $webhookUrl, '?'));

                return $submittedRequest === $request;
            })
            ->andReturn([
                'request_id' => 'fal-job-1',
                'status_url' => 'https://queue.fal.run/status/fal-job-1',
                'response_url' => 'https://queue.fal.run/response/fal-job-1',
                'operation' => [
                    'endpoint' => EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
                    'resolved_model' => EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
                    'payload' => ['image_url' => 'https://example.com/start.png'],
                ],
            ]);

        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')->once()->andReturn($driver);

        $service = new FalAsyncVideoService(
            $workflow,
            $registry,
            app(JobStatusTracker::class),
            app(CreditManager::class)
        );

        $submitted = $service->submit('Animate this still image', [], (string) $user->id);

        $status = $submitted['status'];
        $this->assertSame('queued', $status['status']);
        $this->assertSame('fal-job-1', $status['metadata']['provider']['request_id']);
        $this->assertTrue($status['metadata']['credits']['charged']);
        $this->assertArrayNotHasKey('token', $status['metadata']['webhook']);

        $user->refresh();
        $credits = $user->entity_credits['fal_ai'][EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO]['balance'];
        $this->assertEquals(88.95, $credits);
    }

    public function test_refresh_finalizes_completed_job_from_provider_response(): void
    {
        $workflow = Mockery::mock(FalMediaWorkflowService::class);
        $registry = Mockery::mock(DriverRegistry::class);
        $tracker = app(JobStatusTracker::class);

        $driver = Mockery::mock(FalAIEngineDriver::class);
        $driver->shouldReceive('getAsyncStatus')
            ->once()
            ->with('https://queue.fal.run/status/fal-job-1', true)
            ->andReturn([
                'status' => 'COMPLETED',
                'response_url' => 'https://queue.fal.run/response/fal-job-1',
            ]);
        $driver->shouldReceive('getAsyncResult')
            ->once()
            ->with('https://queue.fal.run/response/fal-job-1')
            ->andReturn([
                'video' => ['url' => 'https://example.com/out.mp4'],
            ]);
        $driver->shouldReceive('buildVideoResponseFromOperation')
            ->once()
            ->andReturn(
                AIResponse::success(
                    '{"url":"https://example.com/out.mp4"}',
                    'fal_ai',
                    EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO
                )->withFiles(['https://example.com/out.mp4'])->withMetadata([
                    'video' => ['url' => 'https://example.com/out.mp4'],
                ])
            );

        $registry->shouldReceive('resolve')->times(3)->andReturn($driver);

        $service = new FalAsyncVideoService(
            $workflow,
            $registry,
            $tracker,
            app(CreditManager::class)
        );

        $tracker->updateStatus('local-job-1', 'queued', [
            'request' => [
                'prompt' => 'Animate this still image',
                'engine' => 'fal_ai',
                'model' => EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
                'parameters' => ['start_image_url' => 'https://example.com/start.png'],
            ],
            'provider' => [
                'status_url' => 'https://queue.fal.run/status/fal-job-1',
                'response_url' => 'https://queue.fal.run/response/fal-job-1',
            ],
            'operation' => [
                'endpoint' => EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
                'resolved_model' => EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
                'payload' => ['image_url' => 'https://example.com/start.png'],
            ],
            'webhook' => [
                'token' => 'secret-token',
                'enabled' => true,
            ],
        ]);

        $status = $service->refresh('local-job-1');
        $publicStatus = $service->toPublicStatus($status);

        $this->assertSame('completed', $status['status']);
        $this->assertSame('https://example.com/out.mp4', $status['metadata']['response']['metadata']['video']['url']);
        $this->assertArrayNotHasKey('token', $publicStatus['metadata']['webhook']);
    }

    public function test_submit_checks_credits_before_async_provider_submission(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'fal_ai' => [
                    EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO => ['balance' => 1.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $request = new AIRequest(
            prompt: 'Animate this still image',
            engine: 'fal_ai',
            model: EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            parameters: ['start_image_url' => 'https://example.com/start.png'],
            userId: (string) $user->id
        );

        $workflow = Mockery::mock(FalMediaWorkflowService::class);
        $workflow->shouldReceive('prepareRequest')->once()->andReturn($request);

        $driver = Mockery::mock(FalAIEngineDriver::class);
        $driver->shouldReceive('validateRequest')->once()->with($request);
        $driver->shouldReceive('submitVideoAsync')->never();

        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')->once()->andReturn($driver);

        $service = new FalAsyncVideoService(
            $workflow,
            $registry,
            app(JobStatusTracker::class),
            app(CreditManager::class)
        );

        $this->expectException(InsufficientCreditsException::class);

        $service->submit('Animate this still image', [], (string) $user->id);
    }

    public function test_submit_does_not_deduct_credits_when_async_provider_submission_fails(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'fal_ai' => [
                    EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO => ['balance' => 100.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $request = new AIRequest(
            prompt: 'Animate this still image',
            engine: 'fal_ai',
            model: EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            parameters: ['start_image_url' => 'https://example.com/start.png'],
            userId: (string) $user->id
        );

        $workflow = Mockery::mock(FalMediaWorkflowService::class);
        $workflow->shouldReceive('prepareRequest')->once()->andReturn($request);

        $driver = Mockery::mock(FalAIEngineDriver::class);
        $driver->shouldReceive('validateRequest')->once()->with($request);
        $driver->shouldReceive('submitVideoAsync')
            ->once()
            ->andThrow(new \RuntimeException('fal queue failed'));

        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')->once()->andReturn($driver);

        $service = new FalAsyncVideoService(
            $workflow,
            $registry,
            app(JobStatusTracker::class),
            app(CreditManager::class)
        );

        try {
            $service->submit('Animate this still image', [], (string) $user->id);
            $this->fail('Expected provider failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('fal queue failed', $exception->getMessage());
        }

        $user->refresh();
        $credits = $user->entity_credits['fal_ai'][EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO]['balance'];
        $this->assertEqualsWithDelta(100.0, $credits, 0.0001);
    }
}
