<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK\Exporters;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Contracts\ObservabilityExporter;

class LogObservabilityExporter implements ObservabilityExporter
{
    public function export(string $type, array $payload): void
    {
        $context = [
            'type' => $type,
            'payload' => $payload,
        ];

        $logger = Log::channel((string) config('ai-engine.observability.log.channel', 'ai-engine'));
        if (is_object($logger) && method_exists($logger, 'info')) {
            $logger->info('AI observability export', $context);

            return;
        }

        Log::info('AI observability export', $context);
    }
}
