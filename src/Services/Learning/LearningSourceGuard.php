<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning;

class LearningSourceGuard
{
    public function assertContentLengthAllowed(?int $bytes, string $source): void
    {
        $maxBytes = $this->maxContentBytes();
        if ($bytes === null || $maxBytes <= 0) {
            return;
        }

        if ($bytes > $maxBytes) {
            throw new \RuntimeException("Learning source [{$source}] exceeds the configured size limit.");
        }
    }

    public function assertContentAllowed(string $content, string $source): void
    {
        $this->assertContentLengthAllowed(strlen($content), $source);
    }

    public function readResourceWithinLimit(mixed $resource, string $source): string
    {
        if (!is_resource($resource)) {
            throw new \RuntimeException("Unable to safely stream learning source [{$source}].");
        }

        $content = '';
        $chunkSize = 8192;
        $maxBytes = $this->maxContentBytes();

        while (!feof($resource)) {
            $chunk = fread($resource, $chunkSize);
            if ($chunk === false) {
                throw new \RuntimeException("Unable to read learning source [{$source}].");
            }

            $content .= $chunk;
            if ($maxBytes > 0 && strlen($content) > $maxBytes) {
                throw new \RuntimeException("Learning source [{$source}] exceeds the configured size limit.");
            }
        }

        return $content;
    }

    public function readPsrStreamWithinLimit(mixed $stream, string $source): string
    {
        if (!is_object($stream) || !method_exists($stream, 'eof') || !method_exists($stream, 'read')) {
            throw new \RuntimeException("Unable to safely stream learning source [{$source}].");
        }

        $content = '';
        $chunkSize = 8192;
        $maxBytes = $this->maxContentBytes();

        while (!$stream->eof()) {
            $content .= $stream->read($chunkSize);
            if ($maxBytes > 0 && strlen($content) > $maxBytes) {
                throw new \RuntimeException("Learning source [{$source}] exceeds the configured size limit.");
            }
        }

        return $content;
    }

    public function assertContentTypeAllowed(?string $contentType, string $source): void
    {
        $contentType = strtolower(trim((string) preg_replace('/;.*/', '', (string) $contentType)));
        if ($contentType === '') {
            return;
        }

        $allowed = array_values(array_filter(array_map(
            static fn (mixed $type): string => strtolower(trim((string) $type)),
            (array) config('ai-engine.learning.allowed_content_types', [])
        )));

        if ($allowed === [] || in_array($contentType, $allowed, true)) {
            return;
        }

        throw new \RuntimeException("Learning source [{$source}] returned unsupported content type [{$contentType}].");
    }

    private function maxContentBytes(): int
    {
        return max(0, (int) config('ai-engine.learning.max_content_bytes', 1048576));
    }
}
