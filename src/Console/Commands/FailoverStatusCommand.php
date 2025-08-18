<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Failover\FailoverManager;

class FailoverStatusCommand extends Command
{
    protected $signature = 'ai-engine:failover-status 
                            {--provider= : Check specific provider}
                            {--reset= : Reset provider health status}
                            {--format=table : Output format (table, json)}';

    protected $description = 'Check AI provider failover status and health';

    public function handle(FailoverManager $failoverManager): int
    {
        try {
            if ($resetProvider = $this->option('reset')) {
                return $this->resetProviderHealth($failoverManager, $resetProvider);
            }

            if ($provider = $this->option('provider')) {
                return $this->showProviderStatus($failoverManager, $provider);
            }

            return $this->showSystemStatus($failoverManager);

        } catch (\Exception $e) {
            $this->error("Failed to get failover status: {$e->getMessage()}");
            return 1;
        }
    }

    protected function showSystemStatus(FailoverManager $failoverManager): int
    {
        $this->info('=== AI Engine Failover System Status ===');
        $this->newLine();

        $systemHealth = $failoverManager->getSystemHealth();
        $providerHealth = $failoverManager->getProviderHealth();
        $stats = $failoverManager->getFailoverStats();

        // System overview
        $this->info('System Health:');
        $statusColor = $systemHealth['status'] === 'healthy' ? 'green' : 'red';
        $this->line("<fg={$statusColor}>{$systemHealth['status']}</>");
        $this->line("Healthy Providers: {$systemHealth['healthy_providers']}/{$systemHealth['total_providers']}");
        $this->newLine();

        // Provider details
        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'system_health' => $systemHealth,
                'provider_health' => $providerHealth,
                'stats' => $stats
            ], JSON_PRETTY_PRINT));
        } else {
            $this->displayProviderTable($providerHealth);
            $this->displayStatsTable($stats);
        }

        return 0;
    }

    protected function showProviderStatus(FailoverManager $failoverManager, string $provider): int
    {
        $this->info("=== Provider Status: {$provider} ===");
        $this->newLine();

        $health = $failoverManager->getProviderHealth($provider);

        if (empty($health)) {
            $this->error("Provider '{$provider}' not found.");
            return 1;
        }

        if ($this->option('format') === 'json') {
            $this->line(json_encode($health, JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Property', 'Value'],
                [
                    ['Status', $health['status']],
                    ['Last Check', $health['last_check']],
                    ['Failure Count', $health['failure_count']],
                    ['Success Rate', number_format($health['success_rate'] ?? 0, 2) . '%'],
                    ['Avg Response Time', number_format($health['avg_response_time'] ?? 0, 2) . 's'],
                ]
            );
        }

        return 0;
    }

    protected function resetProviderHealth(FailoverManager $failoverManager, string $provider): int
    {
        $this->info("Resetting health status for provider: {$provider}");

        $failoverManager->resetProviderHealth($provider);
        
        $this->info("✅ Provider health reset successfully.");
        return 0;
    }

    protected function displayProviderTable(array $providerHealth): void
    {
        $this->info('Provider Health:');
        
        $tableData = [];
        foreach ($providerHealth as $provider => $health) {
            $statusColor = $health['status'] === 'healthy' ? 'green' : 'red';
            $tableData[] = [
                $provider,
                "<fg={$statusColor}>{$health['status']}</>",
                $health['failure_count'],
                number_format($health['success_rate'] ?? 0, 1) . '%',
                number_format($health['avg_response_time'] ?? 0, 2) . 's',
                $health['last_check']
            ];
        }

        $this->table(
            ['Provider', 'Status', 'Failures', 'Success Rate', 'Avg Time', 'Last Check'],
            $tableData
        );
        $this->newLine();
    }

    protected function displayStatsTable(array $stats): void
    {
        $this->info('Failover Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Requests', number_format($stats['total_requests'] ?? 0)],
                ['Failover Count', number_format($stats['failover_count'] ?? 0)],
                ['Success Rate', number_format($stats['success_rate'] ?? 0, 2) . '%'],
                ['Failover Rate', number_format($stats['failover_rate'] ?? 0, 2) . '%'],
            ]
        );
    }
}
