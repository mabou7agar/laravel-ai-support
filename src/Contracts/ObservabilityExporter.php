<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

interface ObservabilityExporter
{
    /**
     * @param array<string, mixed> $payload
     */
    public function export(string $type, array $payload): void;
}
