<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\AIEngineManager;

class SystemHealthCommand extends Command
{
    protected $signature = 'ai-engine:system-health 
                            {--format=table : Output format (table, json)}
                            {--detailed : Show detailed health information}';

    protected $description = 'Check overall AI Engine system health and status';

    public function handle(AIEngineManager $aiEngine): int
    {
        try {
            $this->info('=== AI Engine System Health Check ===');
            $this->newLine();

            $systemStatus = $this->getSystemStatus($aiEngine);

            if ($this->option('format') === 'json') {
                $this->line(json_encode($systemStatus, JSON_PRETTY_PRINT));
                return 0;
            }

            $this->displaySystemOverview($systemStatus);

            if ($this->option('detailed')) {
                $this->displayDetailedHealth($systemStatus);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("Failed to get system health: {$e->getMessage()}");
            return 1;
        }
    }

    protected function displaySystemOverview(array $systemStatus): void
    {
        $this->info('Core Components:');
        $coreTable = [];
        foreach ($systemStatus['core'] as $component => $status) {
            $statusColor = $status ? 'green' : 'red';
            $statusText = $status ? 'Available' : 'Unavailable';
            $coreTable[] = [
                ucwords(str_replace('_', ' ', $component)),
                "<fg={$statusColor}>{$statusText}</>"
            ];
        }
        $this->table(['Component', 'Status'], $coreTable);
        $this->newLine();

        $this->info('Enterprise Features:');
        $enterpriseTable = [];
        foreach ($systemStatus['enterprise'] as $feature => $status) {
            $statusColor = $status ? 'green' : 'red';
            $statusText = $status ? 'Enabled' : 'Disabled';
            $enterpriseTable[] = [
                ucwords(str_replace('_', ' ', $feature)),
                "<fg={$statusColor}>{$statusText}</>"
            ];
        }
        $this->table(['Feature', 'Status'], $enterpriseTable);
        $this->newLine();
    }

    protected function displayDetailedHealth(array $systemStatus): void
    {
        if (isset($systemStatus['health']) && !empty($systemStatus['health'])) {
            $this->info('Provider Health:');
            $health = $systemStatus['health'];
            
            $overallColor = $health['status'] === 'healthy' ? 'green' : 'red';
            $this->line("Overall Status: <fg={$overallColor}>{$health['status']}</>");
            $this->line("Healthy Providers: {$health['healthy_providers']}/{$health['total_providers']}");
            $this->newLine();
        }

        if (isset($systemStatus['metrics']) && !empty($systemStatus['metrics'])) {
            $this->info('Real-time Metrics:');
            $metricsTable = [];
            foreach ($systemStatus['metrics'] as $metric => $value) {
                $displayValue = is_numeric($value) ? number_format($value, 2) : $value;
                $metricsTable[] = [
                    ucwords(str_replace('_', ' ', $metric)),
                    $displayValue
                ];
            }
            $this->table(['Metric', 'Value'], $metricsTable);
            $this->newLine();
        }

        $this->info('System Information:');
        $this->table(
            ['Property', 'Value'],
            [
                ['Timestamp', $systemStatus['timestamp']],
                ['PHP Version', PHP_VERSION],
                ['Laravel Version', app()->version()],
                ['Memory Usage', $this->formatBytes(memory_get_usage(true))],
                ['Peak Memory', $this->formatBytes(memory_get_peak_usage(true))],
            ]
        );
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));
        
        return round($bytes / pow(1024, $factor), 2) . ' ' . $units[$factor];
    }

    /**
     * Get system status from AIEngineManager
     */
    protected function getSystemStatus(AIEngineManager $aiEngine): array
    {
        return [
            'core' => [
                'ai_engine' => true,
                'memory_manager' => app()->bound(\LaravelAIEngine\Services\Memory\MemoryManager::class),
                'action_manager' => app()->bound(\LaravelAIEngine\Services\ActionManager::class),
                'analytics_manager' => app()->bound(\LaravelAIEngine\Services\AnalyticsManager::class),
            ],
            'enterprise' => [
                'failover' => app()->bound(\LaravelAIEngine\Services\Failover\FailoverManager::class),
                'streaming' => app()->bound(\LaravelAIEngine\Services\Streaming\WebSocketManager::class),
                'federated_rag' => config('ai-engine.federated.enabled', false),
            ],
            'health' => [],
            'metrics' => [],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
