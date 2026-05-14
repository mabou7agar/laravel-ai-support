<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Concerns;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AIRequest;

trait BuildsMediaResponses
{
    protected function storeMediaBytes(string $bytes, AIRequest $request, string $extension = 'bin'): string
    {
        $disk = (string) config('ai-engine.media_library.disk', config('filesystems.default', 'public'));
        $directory = trim((string) config('ai-engine.media_library.directory', 'ai-generated'), '/');
        $path = trim($directory.'/'.date('Y/m/d').'/'.Str::uuid().'.'.ltrim($extension, '.'), '/');

        Storage::disk($disk)->put($path, $bytes, [
            'visibility' => (string) config('ai-engine.media_library.visibility', 'public'),
        ]);

        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return $path;
        }
    }

    protected function extensionFromContentType(string $contentType, string $fallback = 'bin'): string
    {
        $contentType = strtolower(strtok($contentType, ';') ?: $contentType);

        return match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/wave', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => $fallback,
        };
    }

    protected function normalizeOutputFiles(mixed $output): array
    {
        if (is_string($output) && $output !== '') {
            return [$output];
        }

        if (!is_array($output)) {
            return [];
        }

        $files = [];
        foreach ($output as $key => $value) {
            if (is_string($value) && $value !== '') {
                $files[] = $value;
                continue;
            }

            if (is_array($value)) {
                foreach (['url', 'file', 'video', 'audio', 'image'] as $field) {
                    if (isset($value[$field]) && is_string($value[$field]) && $value[$field] !== '') {
                        $files[] = $value[$field];
                    }
                }

                if (is_int($key)) {
                    array_push($files, ...$this->normalizeOutputFiles($value));
                }
            }
        }

        return array_values(array_unique($files));
    }
}
