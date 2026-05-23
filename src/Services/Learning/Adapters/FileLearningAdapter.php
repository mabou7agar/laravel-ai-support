<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning\Adapters;

use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Contracts\Learning\LearningSourceAdapterInterface;
use LaravelAIEngine\DTOs\LearningSourcePayload;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Services\Learning\LearningSourceGuard;

class FileLearningAdapter implements LearningSourceAdapterInterface
{
    public function __construct(protected ?LearningSourceGuard $guard = null)
    {
        $this->guard ??= new LearningSourceGuard();
    }

    public function supports(LearningSourceRequest $request): bool
    {
        return $request->sourceType === 'file'
            && (bool) config('ai-engine.learning.adapters.file.enabled', true);
    }

    public function fetch(LearningSourceRequest $request): LearningSourcePayload
    {
        $disk = $request->metadata['disk'] ?? config('ai-engine.learning.adapters.file.disk');
        if (is_string($disk) && $disk !== '') {
            $canReadDirectly = true;
            try {
                $this->guard->assertContentLengthAllowed(Storage::disk($disk)->size($request->source), $request->source);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'exceeds the configured size limit')) {
                    throw $e;
                }

                $canReadDirectly = false;
            }

            if ($canReadDirectly) {
                $content = Storage::disk($disk)->get($request->source);
            } else {
                $stream = Storage::disk($disk)->readStream($request->source);
                $content = $this->guard->readResourceWithinLimit($stream, $request->source);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } else {
            if (!(bool) config('ai-engine.learning.adapters.file.allow_local_paths', false)) {
                throw new \RuntimeException('Local filesystem learning is disabled. Configure a storage disk or enable allowed local paths explicitly.');
            }

            $path = $this->authorizedLocalPath($request->source);
            $this->guard->assertContentLengthAllowed(filesize($path) ?: null, $request->source);
            $content = file_get_contents($path);
        }

        if (!is_string($content)) {
            throw new \RuntimeException("Unable to read learning file [{$request->source}].");
        }

        $this->guard->assertContentAllowed($content, $request->source);

        return new LearningSourcePayload(
            content: $content,
            title: $request->title ?? basename($request->source),
            metadata: [
                'source_type' => 'file',
                'disk' => $disk,
            ],
        );
    }

    protected function authorizedLocalPath(string $source): string
    {
        $path = realpath($source);
        if ($path === false || !is_file($path)) {
            throw new \RuntimeException("Learning file [{$source}] does not exist.");
        }

        foreach ((array) config('ai-engine.learning.adapters.file.allowed_paths', []) as $allowedPath) {
            $allowed = realpath((string) $allowedPath);
            if ($allowed !== false && str_starts_with($path, rtrim($allowed, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return $path;
            }
        }

        throw new \RuntimeException("Learning file [{$source}] is outside configured allowed paths.");
    }
}
