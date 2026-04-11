<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIMedia;

class AIMediaManager
{
    public function storeRemoteFile(string $sourceUrl, array $attributes = []): array
    {
        if (!$this->isEnabled()) {
            return $this->fallbackFileData($sourceUrl, $attributes);
        }

        $contents = $this->downloadRemoteFile($sourceUrl);
        if ($contents === false) {
            return $this->fallbackFileData($sourceUrl, $attributes);
        }

        $extension = $this->resolveExtension(
            $attributes['extension'] ?? null,
            $attributes['file_name'] ?? basename(parse_url($sourceUrl, PHP_URL_PATH) ?: ''),
            $attributes['mime_type'] ?? null,
            $attributes['content_type'] ?? null
        );

        $fileName = $this->normalizeFileName(
            $attributes['file_name'] ?? ('ai-media-' . Str::uuid() . '.' . $extension),
            $extension
        );

        return $this->storeBinary($contents, $fileName, array_merge($attributes, [
            'source_url' => $sourceUrl,
            'size' => strlen($contents),
        ]));
    }

    public function storeBinary(string $contents, string $fileName, array $attributes = []): array
    {
        if (!$this->isEnabled()) {
            return $this->fallbackFileData($attributes['source_url'] ?? null, array_merge($attributes, [
                'file_name' => $fileName,
            ]));
        }

        $disk = (string) ($attributes['disk'] ?? config('ai-engine.media_library.disk', 'public'));
        $path = $this->buildPath($fileName, $attributes);
        $visibility = config('ai-engine.media_library.visibility');

        $options = [];
        if (is_string($visibility) && trim($visibility) !== '') {
            $options['visibility'] = trim($visibility);
        }

        try {
            Storage::disk($disk)->put($path, $contents, $options);
        } catch (\Throwable) {
            return $this->fallbackFileData($attributes['source_url'] ?? null, array_merge($attributes, [
                'file_name' => $fileName,
                'size' => strlen($contents),
            ]));
        }

        $url = $this->resolveUrl($disk, $path, $attributes['source_url'] ?? null);

        $record = $this->persistRecord([
            'uuid' => (string) Str::uuid(),
            'model_type' => (($attributes['model'] ?? null) instanceof Model) ? $attributes['model']->getMorphClass() : null,
            'model_id' => (($attributes['model'] ?? null) instanceof Model) ? (string) $attributes['model']->getKey() : null,
            'user_id' => $attributes['user_id'] ?? null,
            'request_id' => $attributes['request_id'] ?? null,
            'provider_request_id' => $attributes['provider_request_id'] ?? null,
            'engine' => $attributes['engine'] ?? null,
            'ai_model' => $attributes['ai_model'] ?? null,
            'content_type' => $attributes['content_type'] ?? null,
            'collection_name' => $attributes['collection_name'] ?? 'default',
            'name' => $attributes['name'] ?? pathinfo($fileName, PATHINFO_FILENAME),
            'file_name' => $fileName,
            'mime_type' => $attributes['mime_type'] ?? $this->guessMimeType($path, $disk),
            'disk' => $disk,
            'conversions_disk' => $attributes['conversions_disk'] ?? null,
            'size' => (int) ($attributes['size'] ?? strlen($contents)),
            'path' => $path,
            'url' => $url,
            'source_url' => $attributes['source_url'] ?? null,
            'width' => $attributes['width'] ?? null,
            'height' => $attributes['height'] ?? null,
            'duration' => $attributes['duration'] ?? null,
            'manipulations' => $attributes['manipulations'] ?? [],
            'custom_properties' => $attributes['custom_properties'] ?? [],
            'generated_conversions' => $attributes['generated_conversions'] ?? [],
            'responsive_images' => $attributes['responsive_images'] ?? [],
            'order_column' => $attributes['order_column'] ?? null,
        ]);

        return [
            'id' => $record?->id,
            'uuid' => $record?->uuid,
            'disk' => $disk,
            'path' => $path,
            'url' => $url,
            'file_name' => $fileName,
            'mime_type' => $attributes['mime_type'] ?? $this->guessMimeType($path, $disk),
            'size' => (int) ($attributes['size'] ?? strlen($contents)),
            'source_url' => $attributes['source_url'] ?? null,
            'width' => $attributes['width'] ?? null,
            'height' => $attributes['height'] ?? null,
            'duration' => $attributes['duration'] ?? null,
        ];
    }

    private function isEnabled(): bool
    {
        return config('ai-engine.media_library.enabled', true) === true;
    }

    private function buildPath(string $fileName, array $attributes): string
    {
        $root = trim((string) config('ai-engine.media_library.directory', 'ai-generated'), '/');
        $engine = trim((string) ($attributes['engine'] ?? 'unknown'), '/');
        $contentType = trim((string) ($attributes['content_type'] ?? 'files'), '/');

        return implode('/', array_filter([
            $root,
            $engine,
            $contentType,
            now()->format('Y/m/d'),
            $fileName,
        ]));
    }

    private function persistRecord(array $attributes): ?AIMedia
    {
        if (config('ai-engine.media_library.persist_records', true) !== true) {
            return null;
        }

        if (!Schema::hasTable('ai_media')) {
            return null;
        }

        return AIMedia::create($attributes);
    }

    private function resolveUrl(string $disk, string $path, ?string $fallback = null): ?string
    {
        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function guessMimeType(string $path, string $disk): ?string
    {
        try {
            return Storage::disk($disk)->mimeType($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveExtension(?string $explicit, string $fileName, ?string $mimeType, ?string $contentType): string
    {
        if (is_string($explicit) && trim($explicit) !== '') {
            return ltrim(trim($explicit), '.');
        }

        $fromFileName = pathinfo($fileName, PATHINFO_EXTENSION);
        if ($fromFileName !== '') {
            return $fromFileName;
        }

        return match (true) {
            $mimeType === 'video/mp4', $contentType === 'video' => 'mp4',
            str_starts_with((string) $mimeType, 'audio/') => 'mp3',
            default => 'png',
        };
    }

    private function normalizeFileName(string $fileName, string $extension): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $safeBaseName = Str::slug($baseName);
        if ($safeBaseName === '') {
            $safeBaseName = 'ai-media-' . Str::uuid();
        }

        return $safeBaseName . '.' . ltrim($extension, '.');
    }

    private function fallbackFileData(?string $sourceUrl, array $attributes = []): array
    {
        $fileName = $attributes['file_name'] ?? (($sourceUrl !== null && $sourceUrl !== '') ? basename(parse_url($sourceUrl, PHP_URL_PATH) ?: '') : null);

        return [
            'id' => null,
            'uuid' => null,
            'disk' => null,
            'path' => null,
            'url' => $sourceUrl,
            'file_name' => $fileName,
            'mime_type' => $attributes['mime_type'] ?? null,
            'size' => $attributes['size'] ?? null,
            'source_url' => $sourceUrl,
            'width' => $attributes['width'] ?? null,
            'height' => $attributes['height'] ?? null,
            'duration' => $attributes['duration'] ?? null,
        ];
    }

    private function downloadRemoteFile(string $sourceUrl): string|false
    {
        $timeout = max(1, (int) config('ai-engine.media_library.remote_timeout', 20));

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'User-Agent' => 'Laravel-AI-Engine/1.0',
                ])
                ->get($sourceUrl);

            if (!$response->successful()) {
                return false;
            }

            return $response->body();
        } catch (\Throwable) {
            return false;
        }
    }
}
