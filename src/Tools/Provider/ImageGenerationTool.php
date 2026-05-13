<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class ImageGenerationTool implements ProviderToolInterface
{
    public function __construct(
        protected ?string $size = null,
        protected ?string $quality = null,
        protected ?string $format = null
    ) {}

    public function name(): string
    {
        return 'image_generation';
    }

    public function size(string $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function quality(string $quality): self
    {
        $this->quality = $quality;

        return $this;
    }

    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->name(),
            'size' => $this->size,
            'quality' => $this->quality,
            'format' => $this->format,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }
}
