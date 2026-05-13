<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

interface ProviderToolInterface
{
    public function name(): string;

    public function toArray(): array;
}
