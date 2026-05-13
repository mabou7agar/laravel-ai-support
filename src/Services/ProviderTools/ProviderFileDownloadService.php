<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Models\AIProviderToolArtifact;

class ProviderFileDownloadService
{
    public function download(AIProviderToolArtifact $artifact): array
    {
        if ($artifact->media !== null && is_string($artifact->media->disk) && is_string($artifact->media->path)) {
            return [
                'contents' => Storage::disk($artifact->media->disk)->get($artifact->media->path),
                'file_name' => $artifact->media->file_name,
                'mime_type' => $artifact->media->mime_type ?? 'application/octet-stream',
            ];
        }

        if ($artifact->provider === 'openai' && is_string($artifact->provider_file_id) && $artifact->provider_file_id !== '') {
            return $this->downloadOpenAIFile($artifact);
        }

        $url = $artifact->download_url ?? $artifact->source_url;
        if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            $response = Http::timeout((int) config('ai-engine.media_library.remote_timeout', 20))->get($url);
            if (!$response->successful()) {
                throw new AIEngineException('Unable to download provider artifact URL: ' . $response->body());
            }

            return [
                'contents' => $response->body(),
                'file_name' => $artifact->name ?: basename(parse_url($url, PHP_URL_PATH) ?: 'artifact.bin'),
                'mime_type' => $artifact->mime_type ?? $response->header('Content-Type', 'application/octet-stream'),
            ];
        }

        throw new AIEngineException("Provider artifact [{$artifact->uuid}] is not downloadable.");
    }

    private function downloadOpenAIFile(AIProviderToolArtifact $artifact): array
    {
        $response = Http::timeout((int) config('ai-engine.engines.openai.timeout', 120))
            ->withToken((string) config('ai-engine.engines.openai.api_key'))
            ->get(rtrim((string) config('ai-engine.engines.openai.base_url', 'https://api.openai.com/v1'), '/') . '/files/' . $artifact->provider_file_id . '/content');

        if (!$response->successful()) {
            throw new AIEngineException('Unable to download OpenAI provider file: ' . $response->body());
        }

        return [
            'contents' => $response->body(),
            'file_name' => $artifact->name ?: ($artifact->provider_file_id . '.bin'),
            'mime_type' => $artifact->mime_type ?? $response->header('Content-Type', 'application/octet-stream'),
        ];
    }
}
