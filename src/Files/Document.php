<?php

declare(strict_types=1);

namespace LaravelAIEngine\Files;

use Illuminate\Support\Facades\Storage;

class Document
{
    public function __construct(
        public readonly string $source,
        public readonly ?string $disk = null,
        public readonly array $metadata = []
    ) {}

    public static function fromPath(string $path, array $metadata = []): self
    {
        return new self($path, null, $metadata);
    }

    public static function fromStorage(string $path, ?string $disk = null, array $metadata = []): self
    {
        return new self($path, $disk, $metadata);
    }

    public function id(): string
    {
        return hash('sha256', implode('|', [$this->disk ?? 'path', $this->source]));
    }

    public function content(): string
    {
        if ($this->disk !== null) {
            return (string) Storage::disk($this->disk)->get($this->source);
        }

        $content = file_get_contents($this->source);

        return is_string($content) ? $content : '';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'source' => $this->source,
            'disk' => $this->disk,
            'metadata' => $this->metadata,
        ];
    }
}
