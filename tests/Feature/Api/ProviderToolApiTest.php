<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\ProviderTools\HostedArtifactService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
use LaravelAIEngine\Tests\TestCase;

class ProviderToolApiTest extends TestCase
{
    public function test_provider_tool_approval_api_lists_and_resolves_pending_approvals(): void
    {
        $run = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'openai',
            'engine' => 'openai',
            'ai_model' => 'gpt-4o',
            'status' => 'awaiting_approval',
            'tool_names' => ['computer_use'],
            'request_payload' => ['model' => 'gpt-4o'],
            'metadata' => [],
        ]);

        $approval = app(ProviderToolApprovalService::class)->requestApproval($run, [
            'type' => 'computer_use',
            'display_width' => 1024,
            'display_height' => 768,
        ], '42');

        $this->getJson('/api/v1/ai/provider-tools/approvals?status=pending')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.0.approval_key', $approval->approval_key);

        $this->postJson("/api/v1/ai/provider-tools/approvals/{$approval->approval_key}/approve", [
            'actor_id' => 'admin-1',
            'reason' => 'Approved for test run.',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.approval.status', 'approved');
    }

    public function test_provider_tool_artifact_download_uses_persisted_media(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example.test/result.png' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $run = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'openai',
            'engine' => 'openai',
            'ai_model' => 'gpt-4o',
            'status' => 'completed',
            'tool_names' => ['code_interpreter'],
            'request_payload' => [],
            'metadata' => [],
        ]);

        $artifact = app(HostedArtifactService::class)->recordFromProviderResponse($run, [
            'result' => ['url' => 'https://cdn.example.test/result.png'],
        ])[0];

        $this->get("/api/v1/ai/provider-tools/artifacts/{$artifact->uuid}/download")
            ->assertOk()
            ->assertHeader('content-type', 'image/png')
            ->assertSee('image-bytes', false);
    }

    public function test_provider_tool_run_can_continue_after_approval(): void
    {
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_123',
                'output_text' => 'continued',
                'output' => [],
            ]),
        ]);

        $run = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'openai',
            'engine' => 'openai',
            'ai_model' => 'gpt-4o',
            'status' => 'awaiting_approval',
            'tool_names' => ['code_interpreter'],
            'request_payload' => [
                'model' => 'gpt-4o',
                'input' => [['role' => 'user', 'content' => 'Analyze']],
                'tools' => [['type' => 'code_interpreter']],
            ],
            'metadata' => [],
        ]);
        $approval = app(ProviderToolApprovalService::class)->requestApproval($run, ['type' => 'code_interpreter']);
        app(ProviderToolApprovalService::class)->approve($approval->approval_key, 'admin-1');

        $this->postJson("/api/v1/ai/provider-tools/runs/{$run->uuid}/continue")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonPath('data.run.provider_request_id', 'resp_123');
    }

    public function test_fal_catalog_api_executes_dynamic_catalog_model(): void
    {
        Storage::fake('public');
        $this->createFalModel();

        Http::fake([
            'https://fal.run/fal-ai/test/image' => Http::response([
                'request_id' => 'fal_req_123',
                'images' => [
                    ['url' => 'https://cdn.example.test/fal.png'],
                ],
            ]),
            'https://cdn.example.test/fal.png' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->postJson('/api/v1/ai/provider-tools/fal/catalog/execute', [
            'model' => 'fal-ai/test/image',
            'prompt' => 'A catalog image',
            'input' => ['image_size' => 'square_hd'],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.files.0', 'https://cdn.example.test/fal.png');
    }

    public function test_fal_catalog_api_queues_and_completes_from_webhook(): void
    {
        Storage::fake('public');
        $this->createFalModel();

        Http::fake([
            'https://queue.fal.run/fal-ai/test/image*' => Http::response([
                'request_id' => 'fal_req_queued',
                'status_url' => 'https://queue.fal.run/status/fal_req_queued',
                'response_url' => 'https://queue.fal.run/response/fal_req_queued',
            ]),
            'https://cdn.example.test/queued.png' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $queued = $this->postJson('/api/v1/ai/provider-tools/fal/catalog/execute', [
            'model' => 'fal-ai/test/image',
            'prompt' => 'A queued catalog image',
            'async' => true,
        ])
            ->assertAccepted()
            ->assertJsonPath('success', true);

        $runId = $queued->json('data.provider_tool_run_id');
        $this->postJson('/api/v1/ai/provider-tools/fal/catalog/webhook', [
            'provider_tool_run_id' => $runId,
            'status' => 'OK',
            'payload' => [
                'request_id' => 'fal_req_queued',
                'images' => [
                    ['url' => 'https://cdn.example.test/queued.png'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('ai_provider_tool_runs', [
            'uuid' => $runId,
            'status' => 'completed',
            'provider_request_id' => 'fal_req_queued',
        ]);
        $this->assertDatabaseHas('ai_provider_tool_artifacts', [
            'provider' => 'fal_ai',
            'source_url' => 'https://cdn.example.test/queued.png',
        ]);
    }

    public function test_fal_catalog_webhook_marks_non_success_status_as_failed(): void
    {
        $this->createFalModel();

        Http::fake([
            'https://queue.fal.run/fal-ai/test/image*' => Http::response([
                'request_id' => 'fal_req_failed',
                'status_url' => 'https://queue.fal.run/status/fal_req_failed',
                'response_url' => 'https://queue.fal.run/response/fal_req_failed',
            ]),
        ]);

        $queued = $this->postJson('/api/v1/ai/provider-tools/fal/catalog/execute', [
            'model' => 'fal-ai/test/image',
            'prompt' => 'A failing queued catalog image',
            'async' => true,
        ])->assertAccepted();

        $runId = $queued->json('data.provider_tool_run_id');
        $this->postJson('/api/v1/ai/provider-tools/fal/catalog/webhook', [
            'provider_tool_run_id' => $runId,
            'status' => 'FAILED',
            'payload' => ['request_id' => 'fal_req_failed'],
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('ai_provider_tool_runs', [
            'uuid' => $runId,
            'status' => 'failed',
        ]);
    }

    private function createFalModel(): void
    {
        AIModel::query()->create([
            'provider' => 'fal_ai',
            'model_id' => 'fal-ai/test/image',
            'name' => 'FAL Test Image',
            'capabilities' => ['image_generation', 'text_to_image'],
            'supports_streaming' => false,
            'supports_vision' => false,
            'supports_function_calling' => false,
            'supports_json_mode' => false,
            'is_active' => true,
            'is_deprecated' => false,
            'metadata' => [
                'schema' => [
                    'required' => ['prompt'],
                    'properties' => [
                        'prompt' => ['type' => 'string'],
                    ],
                ],
            ],
        ]);
    }
}
