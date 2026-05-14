<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIProviderToolArtifact;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\ProviderToolArtifactRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\AIMediaManager;

class HostedArtifactService
{
    public function __construct(
        private readonly ProviderToolArtifactRepository $artifacts,
        private readonly AIMediaManager $mediaManager
    ) {}

    public function recordFromProviderResponse(AIProviderToolRun $run, array $response, array $context = []): array
    {
        if ((bool) config('ai-engine.provider_tools.artifacts.enabled', true) !== true) {
            return [];
        }

        $candidates = array_slice(
            $this->deduplicateCandidates($this->extractCandidates($response)),
            0,
            (int) config('ai-engine.provider_tools.artifacts.max_per_run', 100)
        );

        $records = [];
        foreach ($candidates as $candidate) {
            $records[] = $this->record($run, array_merge($candidate, [
                'metadata' => array_merge($candidate['metadata'] ?? [], $context),
            ]));
        }

        return $records;
    }

    public function record(AIProviderToolRun $run, array $artifact): AIProviderToolArtifact
    {
        $url = $artifact['download_url'] ?? $artifact['source_url'] ?? $artifact['citation_url'] ?? null;
        $media = null;
        $metadata = $artifact['metadata'] ?? [];
        $ownerType = (string) ($artifact['owner_type'] ?? $metadata['owner_type'] ?? 'provider_tool_run');
        $ownerId = (string) ($artifact['owner_id'] ?? $metadata['owner_id'] ?? $run->id);
        $source = (string) ($artifact['source'] ?? $metadata['source'] ?? $this->inferSource($run, $artifact));

        if (is_string($url) && $this->shouldPersistRemote($artifact, $url)) {
            $media = $this->mediaManager->storeRemoteFile($url, [
                'engine' => $run->engine,
                'ai_model' => $run->ai_model,
                'provider_request_id' => $run->provider_request_id,
                'request_id' => $run->request_id,
                'user_id' => $run->user_id,
                'content_type' => $artifact['artifact_type'] ?? 'file',
                'mime_type' => $artifact['mime_type'] ?? null,
                'file_name' => $artifact['name'] ?? null,
                'collection_name' => 'provider-tools',
            ]);
        }

        $record = $this->artifacts->create([
            'uuid' => (string) Str::uuid(),
            'agent_run_step_id' => $artifact['agent_run_step_id'] ?? $artifact['metadata']['agent_run_step_id'] ?? $run->agent_run_step_id,
            'tool_run_id' => $run->id,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'media_id' => is_array($media) ? ($media['id'] ?? null) : null,
            'provider' => $run->provider,
            'source' => $source,
            'artifact_type' => $artifact['artifact_type'] ?? 'file',
            'name' => $artifact['name'] ?? null,
            'mime_type' => $artifact['mime_type'] ?? null,
            'source_url' => $artifact['source_url'] ?? $url,
            'download_url' => $artifact['download_url'] ?? $url,
            'provider_file_id' => $artifact['provider_file_id'] ?? null,
            'provider_container_id' => $artifact['provider_container_id'] ?? null,
            'citation_title' => $artifact['citation_title'] ?? null,
            'citation_url' => $artifact['citation_url'] ?? null,
            'metadata' => array_merge($metadata, [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'source' => $source,
            ]),
            'expires_at' => $artifact['expires_at'] ?? $metadata['expires_at'] ?? $this->expiresAt(),
        ]);

        $this->emitArtifactEvent($run, $record);

        return $record;
    }

    public function recordForOwner(AIProviderToolRun $run, string $ownerType, int|string $ownerId, array $artifact): AIProviderToolArtifact
    {
        return $this->record($run, array_merge($artifact, [
            'owner_type' => $ownerType,
            'owner_id' => (string) $ownerId,
        ]));
    }

    private function extractCandidates(array $payload, string $path = ''): array
    {
        $candidates = [];

        foreach ($payload as $key => $value) {
            $currentPath = $path === '' ? (string) $key : "{$path}.{$key}";

            if (is_array($value)) {
                if ($this->looksLikeCitation($value)) {
                    $candidates[] = [
                        'artifact_type' => 'citation',
                        'citation_title' => $value['title'] ?? $value['citation_title'] ?? null,
                        'citation_url' => $value['url'] ?? $value['citation_url'] ?? null,
                        'metadata' => ['path' => $currentPath, 'raw' => $value],
                    ];

                    continue;
                }

                $candidates = array_merge($candidates, $this->extractCandidates($value, $currentPath));
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $stringValue = (string) $value;
            if ($this->isUrlKey((string) $key) && filter_var($stringValue, FILTER_VALIDATE_URL)) {
                $candidates[] = [
                    'artifact_type' => $this->inferArtifactType($stringValue, $currentPath),
                    'source_url' => $stringValue,
                    'download_url' => $stringValue,
                    'name' => basename(parse_url($stringValue, PHP_URL_PATH) ?: '') ?: null,
                    'metadata' => ['path' => $currentPath],
                ];
            }

            if (in_array((string) $key, ['file_id', 'output_file_id'], true) && $stringValue !== '') {
                $candidates[] = [
                    'artifact_type' => 'provider_file',
                    'provider_file_id' => $stringValue,
                    'metadata' => ['path' => $currentPath],
                ];
            }

            if ((string) $key === 'container_id' && $stringValue !== '') {
                $candidates[] = [
                    'artifact_type' => 'provider_container',
                    'provider_container_id' => $stringValue,
                    'metadata' => ['path' => $currentPath],
                ];
            }
        }

        return $candidates;
    }

    private function deduplicateCandidates(array $candidates): array
    {
        $seen = [];

        return array_values(array_filter($candidates, static function (array $candidate) use (&$seen): bool {
            $key = implode('|', array_filter([
                $candidate['artifact_type'] ?? 'file',
                $candidate['download_url'] ?? $candidate['source_url'] ?? $candidate['citation_url'] ?? null,
                $candidate['provider_file_id'] ?? null,
                $candidate['provider_container_id'] ?? null,
            ]));

            if ($key === '' || isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));
    }

    private function looksLikeCitation(array $value): bool
    {
        return isset($value['url'])
            && (isset($value['title']) || isset($value['citation_title']) || isset($value['start_index']) || isset($value['end_index']));
    }

    private function isUrlKey(string $key): bool
    {
        return in_array($key, [
            'url',
            'download_url',
            'file_url',
            'source_url',
            'image_url',
            'video_url',
            'audio_url',
        ], true);
    }

    private function inferArtifactType(string $url, string $path): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $path = strtolower($path);

        return match (true) {
            in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) || str_contains($path, 'image') => 'image',
            in_array($extension, ['mp4', 'mov', 'webm'], true) || str_contains($path, 'video') => 'video',
            in_array($extension, ['mp3', 'wav', 'm4a', 'ogg'], true) || str_contains($path, 'audio') => 'audio',
            default => 'file',
        };
    }

    private function shouldPersistRemote(array $artifact, string $url): bool
    {
        if ((bool) config('ai-engine.provider_tools.artifacts.persist_remote_files', true) !== true) {
            return false;
        }

        if (($artifact['artifact_type'] ?? null) === 'citation') {
            return false;
        }

        $type = (string) ($artifact['artifact_type'] ?? $this->inferArtifactType($url, ''));

        return in_array($type, ['image', 'video', 'audio', 'file'], true);
    }

    private function inferSource(AIProviderToolRun $run, array $artifact): string
    {
        $toolNames = array_values(array_filter((array) ($run->tool_names ?? [])));
        $firstTool = (string) ($toolNames[0] ?? '');
        if ($firstTool !== '') {
            return $firstTool;
        }

        return match ((string) ($artifact['artifact_type'] ?? 'file')) {
            'image' => 'image_generation',
            'video' => 'video_generation',
            default => 'provider_tool',
        };
    }

    private function expiresAt(): mixed
    {
        $days = (int) config('ai-agent.run_retention.artifact_days', 90);

        return $days > 0 ? now()->addDays($days) : null;
    }

    private function emitArtifactEvent(AIProviderToolRun $run, AIProviderToolArtifact $artifact): void
    {
        app(AgentRunEventStreamService::class)->emit(
            AgentRunEventStreamService::ARTIFACT_CREATED,
            $run->agent_run_id,
            $artifact->agent_run_step_id,
            [
                'artifact_id' => $artifact->uuid,
                'artifact_type' => $artifact->artifact_type,
                'source' => $artifact->source,
                'provider' => $artifact->provider,
                'tool_run_id' => $run->uuid,
            ],
            ['trace_id' => $run->metadata['trace_id'] ?? $artifact->metadata['trace_id'] ?? null]
        );
    }
}
