<?php

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class GenerateReferencePackApiTest extends TestCase
{
    public function test_preview_endpoint_submits_async_job(): void
    {
        $service = Mockery::mock(FalAsyncReferencePackGenerationService::class);
        $service->shouldReceive('submit')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Generate Mina', $prompt);
                $this->assertSame('character', $options['entity_type']);
                $this->assertTrue($options['preview_only']);
                $this->assertSame('mina-preview', $options['save_as']);
                $this->assertNull($userId);

                return true;
            })
            ->andReturn([
                'job_id' => 'preview-job-1',
                'status' => [
                    'job_id' => 'preview-job-1',
                    'status' => 'queued',
                ],
                'webhook_url' => 'https://app.test/api/v1/ai/generate/preview/fal/webhook?job_id=preview-job-1&token=secret',
            ]);

        $this->app->instance(FalAsyncReferencePackGenerationService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/preview', [
            'prompt' => 'Generate Mina',
            'save_as' => 'mina-preview',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', 'preview-job-1')
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_reference_pack_job_status_endpoint_returns_tracked_job(): void
    {
        $service = Mockery::mock(FalAsyncReferencePackGenerationService::class);
        $service->shouldReceive('getStatus')
            ->once()
            ->with('reference-job-1', true)
            ->andReturn([
                'job_id' => 'reference-job-1',
                'status' => 'processing',
                'metadata' => [
                    'current_step' => 2,
                    'total_steps' => 4,
                ],
            ]);

        $this->app->instance(FalAsyncReferencePackGenerationService::class, $service);

        $response = $this->getJson('/api/v1/ai/generate/reference-pack/jobs/reference-job-1?refresh=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', 'reference-job-1')
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_reference_pack_webhook_endpoint_accepts_completion_callback(): void
    {
        $service = Mockery::mock(FalAsyncReferencePackGenerationService::class);
        $service->shouldReceive('handleWebhook')
            ->once()
            ->withArgs(function (string $jobId, string $token, array $payload): bool {
                $this->assertSame('reference-job-1', $jobId);
                $this->assertSame('secret-token', $token);
                $this->assertSame('OK', $payload['status']);

                return true;
            })
            ->andReturn([
                'job_id' => 'reference-job-1',
                'status' => 'completed',
            ]);

        $this->app->instance(FalAsyncReferencePackGenerationService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/reference-pack/fal/webhook?job_id=reference-job-1&token=secret-token', [
            'request_id' => 'fal-image-2',
            'gateway_request_id' => 'fal-image-2',
            'status' => 'OK',
            'payload' => [
                'images' => [['url' => 'https://example.com/final.png']],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job_id', 'reference-job-1')
            ->assertJsonPath('data.status', 'completed');
    }
}
