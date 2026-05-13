<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class ComputerUse implements ProviderToolInterface
{
    public function __construct(
        protected int $displayWidth = 1024,
        protected int $displayHeight = 768,
        protected string $environment = 'browser',
        protected int $displayNumber = 1
    ) {}

    public function name(): string
    {
        return 'computer_use';
    }

    public function display(int $width, int $height, int $displayNumber = 1): self
    {
        $this->displayWidth = max(1, $width);
        $this->displayHeight = max(1, $height);
        $this->displayNumber = max(1, $displayNumber);

        return $this;
    }

    public function environment(string $environment): self
    {
        $this->environment = $environment;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->name(),
            'display_width' => $this->displayWidth,
            'display_height' => $this->displayHeight,
            'display_number' => $this->displayNumber,
            'environment' => $this->environment,
        ];
    }
}
