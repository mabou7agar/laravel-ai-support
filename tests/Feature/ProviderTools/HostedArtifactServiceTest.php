<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\ProviderTools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIProviderToolArtifact;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\ProviderTools\HostedArtifactService;
use LaravelAIEngine\Tests\TestCase;

class HostedArtifactServiceTest extends TestCase
{
    public function test_hosted_artifacts_are_extracted_and_media_files_are_persisted(): void
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
            'status' => 'running',
            'tool_names' => ['code_interpreter'],
            'request_payload' => [],
            'metadata' => [],
        ]);

        $artifacts = app(HostedArtifactService::class)->recordFromProviderResponse($run, [
            'output' => [
                [
                    'type' => 'image_generation_call',
                    'result' => ['url' => 'https://cdn.example.test/result.png'],
                ],
                [
                    'type' => 'message',
                    'content' => [
                        [
                            'annotations' => [
                                [
                                    'title' => 'Source',
                                    'url' => 'https://docs.example.test/source',
                                    'start_index' => 0,
                                    'end_index' => 5,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'container_id' => 'cntr_123',
            'file_id' => 'file_123',
        ]);

        $this->assertCount(4, $artifacts);
        $this->assertDatabaseHas('ai_provider_tool_artifacts', [
            'tool_run_id' => $run->id,
            'artifact_type' => 'image',
            'source_url' => 'https://cdn.example.test/result.png',
        ]);
        $this->assertDatabaseHas('ai_provider_tool_artifacts', [
            'tool_run_id' => $run->id,
            'artifact_type' => 'citation',
            'citation_title' => 'Source',
            'citation_url' => 'https://docs.example.test/source',
        ]);
        $this->assertSame(1, AIProviderToolArtifact::query()->whereNotNull('media_id')->count());
    }

    public function test_hosted_artifacts_support_generic_owners_sources_and_expiry(): void
    {
        config()->set('ai-agent.run_retention.artifact_days', 7);

        $agentRun = app(AgentRunRepository::class)->create([
            'session_id' => 'artifact-owner-session',
            'status' => 'running',
        ]);
        $agentStep = app(AgentRunStepRepository::class)->create($agentRun, [
            'type' => 'provider_tool',
            'status' => 'running',
            'action' => 'fal',
        ]);
        $providerRun = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'agent_run_id' => $agentRun->id,
            'agent_run_step_id' => $agentStep->id,
            'provider' => 'fal',
            'engine' => 'fal',
            'ai_model' => 'fal/image',
            'status' => 'completed',
            'tool_names' => ['fal'],
            'metadata' => [],
        ]);

        $service = app(HostedArtifactService::class);
        $runArtifact = $service->recordForOwner($providerRun, 'agent_run', $agentRun->id, [
            'artifact_type' => 'image',
            'source_url' => 'https://cdn.example.test/image.png',
            'source' => 'image_generation',
        ]);
        $stepArtifact = $service->recordForOwner($providerRun, 'agent_step', $agentStep->id, [
            'artifact_type' => 'video',
            'source_url' => 'https://cdn.example.test/video.mp4',
            'source' => 'video_generation',
        ]);
        $manualArtifact = $service->recordForOwner($providerRun, 'agent_step', $agentStep->id, [
            'artifact_type' => 'file',
            'provider_file_id' => 'manual_file',
            'source' => 'manual_upload',
        ]);
        $langGraphArtifact = $service->recordForOwner($providerRun, 'agent_step', $agentStep->id, [
            'artifact_type' => 'file',
            'provider_file_id' => 'langgraph_file',
            'source' => 'langgraph',
        ]);

        $this->assertSame('agent_run', $runArtifact->owner_type);
        $this->assertSame((string) $agentRun->id, $runArtifact->owner_id);
        $this->assertSame('image_generation', $runArtifact->source);
        $this->assertSame('agent_step', $stepArtifact->owner_type);
        $this->assertSame('video_generation', $stepArtifact->source);
        $this->assertSame('manual_upload', $manualArtifact->source);
        $this->assertSame('langgraph', $langGraphArtifact->source);
        $this->assertNotNull($runArtifact->expires_at);
        $this->assertTrue(collect(app(AgentRunEventStreamService::class)->fallbackEvents($agentRun))
            ->contains(fn (array $event): bool => ($event['name'] ?? null) === 'artifact.created'));
        $this->assertDatabaseHas('ai_provider_tool_artifacts', [
            'owner_type' => 'agent_step',
            'owner_id' => (string) $agentStep->id,
            'source' => 'manual_upload',
            'provider_file_id' => 'manual_file',
        ]);
    }
}
