<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class GoogleMapsGrounding implements ProviderToolInterface
{
    public function __construct(
        protected bool $enableWidget = false,
        protected ?float $latitude = null,
        protected ?float $longitude = null
    ) {}

    public function name(): string
    {
        return 'google_maps';
    }

    public function widget(bool $enabled = true): self
    {
        $this->enableWidget = $enabled;

        return $this;
    }

    public function location(float $latitude, float $longitude): self
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->name(),
            'enable_widget' => $this->enableWidget,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ], static fn ($value): bool => $value !== null);
    }
}
