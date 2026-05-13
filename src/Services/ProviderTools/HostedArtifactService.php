<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIProviderToolArtifact;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\ProviderToolArtifactRepository;
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

        return $this->artifacts->create([
            'uuid' => (string) Str::uuid(),
            'tool_run_id' => $run->id,
            'media_id' => is_array($media) ? ($media['id'] ?? null) : null,
            'provider' => $run->provider,
            'artifact_type' => $artifact['artifact_type'] ?? 'file',
            'name' => $artifact['name'] ?? null,
            'mime_type' => $artifact['mime_type'] ?? null,
            'source_url' => $artifact['source_url'] ?? $url,
            'download_url' => $artifact['download_url'] ?? $url,
            'provider_file_id' => $artifact['provider_file_id'] ?? null,
            'provider_container_id' => $artifact['provider_container_id'] ?? null,
            'citation_title' => $artifact['citation_title'] ?? null,
            'citation_url' => $artifact['citation_url'] ?? null,
            'metadata' => $artifact['metadata'] ?? [],
        ]);
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
}
