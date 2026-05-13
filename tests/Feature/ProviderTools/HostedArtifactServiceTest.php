<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\ProviderTools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIProviderToolArtifact;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
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
}
