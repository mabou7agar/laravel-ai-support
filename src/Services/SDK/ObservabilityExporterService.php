<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use Illuminate\Contracts\Container\Container;
use LaravelAIEngine\Contracts\ObservabilityExporter;
use Throwable;

class ObservabilityExporterService
{
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function export(string $type, array $payload): void
    {
        foreach ((array) config('ai-engine.observability.exporters', []) as $exporterClass) {
            if (!is_string($exporterClass) || $exporterClass === '') {
                continue;
            }

            try {
                $exporter = $this->container->make($exporterClass);
                if ($exporter instanceof ObservabilityExporter) {
                    $exporter->export($type, $payload);
                }
            } catch (Throwable) {
                // Exporters are best-effort; internal package records remain source of truth.
            }
        }
    }
}
