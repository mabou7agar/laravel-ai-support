<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        if (is_string($artifact->provider_file_id) && $artifact->provider_file_id !== ''
            && $this->fileDownloadDescriptor($artifact->provider) !== null) {
            return $this->downloadProviderFile($artifact);
        }

        $url = $artifact->download_url ?? $artifact->source_url;
        if (is_string($url) && $url !== '') {
            // SSRF guard: the URL comes from the provider response and must not point at
            // internal infrastructure. Redirects are disabled so a public URL can't bounce
            // to an internal one.
            if (!RemoteUrlGuard::isFetchable($url)) {
                Log::channel('ai-engine')->warning('Blocked provider artifact download to a disallowed URL', [
                    'artifact' => $artifact->uuid,
                    'host' => parse_url($url, PHP_URL_HOST),
                ]);

                throw new AIEngineException("Provider artifact [{$artifact->uuid}] URL is not allowed.");
            }

            $response = Http::timeout((int) config('ai-engine.media_library.remote_timeout', 20))
                ->withoutRedirecting()
                ->get($url);
            if (!$response->successful()) {
                // Do NOT echo the upstream body to the caller — for a blocked-but-reached
                // internal host that would turn SSRF into a full-read oracle.
                Log::channel('ai-engine')->warning('Provider artifact URL download failed', [
                    'artifact' => $artifact->uuid,
                    'status' => $response->status(),
                ]);

                throw new AIEngineException('Unable to download provider artifact URL.');
            }

            return [
                'contents' => $response->body(),
                'file_name' => $artifact->name ?: basename(parse_url($url, PHP_URL_PATH) ?: 'artifact.bin'),
                'mime_type' => $artifact->mime_type ?? $response->header('Content-Type', 'application/octet-stream'),
            ];
        }

        throw new AIEngineException("Provider artifact [{$artifact->uuid}] is not downloadable.");
    }

    /**
     * Download a provider-hosted file using the configured descriptor for its
     * provider. Any provider declared under provider_tools.file_download is
     * supported without code changes.
     */
    private function downloadProviderFile(AIProviderToolArtifact $artifact): array
    {
        $provider = (string) $artifact->provider;
        $descriptor = $this->fileDownloadDescriptor($provider);

        if ($descriptor === null) {
            throw new AIEngineException("No file download descriptor configured for provider [{$provider}].");
        }

        $engine = (array) config("ai-engine.engines.{$provider}", []);
        $baseUrl = (string) ($descriptor['base_url'] ?? $engine['base_url'] ?? '');
        $apiKey = (string) ($descriptor['api_key'] ?? $engine['api_key'] ?? '');
        $timeout = (int) ($descriptor['timeout'] ?? $engine['timeout'] ?? 120);

        if ($apiKey === '') {
            throw new AIEngineException("Missing API key for provider [{$provider}] file download.");
        }

        $url = strtr((string) $descriptor['content_url'], [
            '{base_url}' => rtrim($baseUrl, '/'),
            // Encode the provider-supplied id so it can't inject extra path segments
            // (e.g. "../models") into the provider API request.
            '{file_id}' => rawurlencode((string) $artifact->provider_file_id),
        ]);

        $request = Http::timeout($timeout)
            ->withoutRedirecting()
            ->withHeaders((array) ($descriptor['headers'] ?? []));

        $request = match ($descriptor['auth'] ?? 'bearer') {
            'x-api-key' => $request->withHeaders(['x-api-key' => $apiKey]),
            default => $request->withToken($apiKey),
        };

        $response = $request->get($url);

        if (!$response->successful()) {
            Log::channel('ai-engine')->warning('Provider file download failed', [
                'provider' => $provider,
                'status' => $response->status(),
            ]);

            throw new AIEngineException("Unable to download {$provider} provider file.");
        }

        return [
            'contents' => $response->body(),
            'file_name' => $artifact->name ?: ($artifact->provider_file_id . '.bin'),
            'mime_type' => $artifact->mime_type ?? $response->header('Content-Type', 'application/octet-stream'),
        ];
    }

    /**
     * Resolve the configured file-download descriptor for a provider, if any.
     */
    private function fileDownloadDescriptor(?string $provider): ?array
    {
        if (!is_string($provider) || $provider === '') {
            return null;
        }

        $descriptor = config("ai-engine.provider_tools.file_download.{$provider}");

        return is_array($descriptor) && isset($descriptor['content_url']) ? $descriptor : null;
    }
}
