<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Assistant\AssistantResourceRegistry;
use LaravelAIEngine\Services\Knowledge\KnowledgeSourceRegistry;
use LaravelAIEngine\Services\Routing\ModelRouteReadinessService;

final class AssistantRuntimeReadinessCommand extends Command
{
    protected $signature = 'ai:assistant-readiness
                            {task? : Inspect one configured model-route task}
                            {--json : Print a machine-readable report}';

    protected $description = 'Inspect headless assistant model routes and host provider registrations.';

    public function handle(
        ModelRouteReadinessService $routes,
        AssistantResourceRegistry $resources,
        KnowledgeSourceRegistry $knowledge,
    ): int {
        $reports = array_map(
            static fn ($report): array => $report->toArray(),
            $routes->inspect($this->argument('task') ?: null),
        );
        $payload = [
            'ready' => collect($reports)->every(static fn (array $report): bool => (bool) $report['ready']),
            'model_routes' => $reports,
            'resource_providers' => array_map(static fn (object $provider): string => $provider::class, $resources->providers()),
            'knowledge_sources' => array_map(static fn (object $provider): string => $provider::class, $knowledge->providers()),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $payload['ready'] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Task', 'Engine', 'Model', 'Fallback', 'Ready', 'Issues'],
            array_map(static fn (array $report): array => [
                $report['task'],
                $report['engine'],
                $report['model'],
                ($report['fallback_engine'] ?? '-').'/'.($report['fallback_model'] ?? '-'),
                $report['ready'] ? 'yes' : 'no',
                implode(', ', $report['issues']),
            ], $reports),
        );
        $this->line('Resource providers: '.count($payload['resource_providers']));
        $this->line('Knowledge sources: '.count($payload['knowledge_sources']));

        return $payload['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
