<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts\Federation;

interface NodeMetadataProvider
{
    public function discover(): array;

    public function getActiveNodes(): array;
}
