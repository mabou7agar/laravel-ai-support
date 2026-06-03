<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\AnalyticsManager;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Enums\EngineEnum;
use Carbon\Carbon;

class UsageReportCommand extends Command
{
    protected $signature = 'ai:usage-report 
                           {--user= : Generate report for specific user ID}
                           {--engine= : Filter by specific engine}
                           {--days=30 : Number of days to include in report}
                           {--format=table : Output format (table, json, csv)}
                           {--export= : Export to file path}';

    protected $description = 'Generate AI usage and cost reports';

    public function handle(): int
    {
        $userId = $this->option('user');
        $engine = $this->option('engine');
        $days = (int) $this->option('days');
        $format = $this->option('format');
        $exportPath = $this->option('export');

        $this->info("📊 Generating AI Usage Report for last {$days} days...");
        $this->newLine();

        $creditManager = app(CreditManager::class);
        
        if ($userId) {
            $this->generateUserReport($creditManager, $userId, $engine, $days, $format, $exportPath);
        } else {
            $this->generateSystemReport($creditManager, $engine, $days, $format, $exportPath);
        }

        return self::SUCCESS;
    }

    private function generateUserReport(CreditManager $creditManager, string $userId, ?string $engine, int $days, string $format, ?string $exportPath): void
    {
        $this->info("👤 User Report for ID: {$userId}");
        
        // Get user credits
        $allCredits = $creditManager->getAllUserCredits($userId);
        $totalCredits = $creditManager->getTotalCredits($userId);
        $hasLowCredits = $creditManager->hasLowCredits($userId);
        
        $this->line("💰 Current Credit Balance: {$totalCredits}");
        if ($hasLowCredits) {
            $this->warn("⚠️  Low credit balance detected!");
        }
        $this->newLine();

        // Credits by engine
        $this->info("💳 Credits by Engine:");
        $creditTable = [];
        foreach ($allCredits as $engineName => $models) {
            if ($engine && $engineName !== $engine) continue;
            
            foreach ($models as $modelName => $creditData) {
                $creditTable[] = [
                    'Engine' => $engineName,
                    'Model' => $modelName,
                    'Balance' => $creditData['is_unlimited'] ? 'Unlimited' : $creditData['balance'],
                    'Status' => $creditData['is_unlimited'] ? '♾️ Unlimited' : '💰 Limited',
                ];
            }
        }
        
        if ($format === 'table') {
            $this->table(['Engine', 'Model', 'Balance', 'Status'], $creditTable);
        } elseif ($format === 'json') {
            $this->line(json_encode($creditTable, JSON_PRETTY_PRINT));
        } elseif ($format === 'csv') {
            $this->outputCsv($creditTable, ['Engine', 'Model', 'Balance', 'Status']);
        }

        // Usage statistics (real data from the analytics store)
        $usageStats = app(AnalyticsManager::class)->getUsageStats(
            $this->buildFilters($userId, $engine, $days)
        );

        $totalRequests = (int) ($usageStats['total_requests'] ?? 0);
        $totalCreditsUsed = (float) ($usageStats['total_credits_used'] ?? 0);
        $avgCreditsPerRequest = $totalRequests > 0
            ? round($totalCreditsUsed / $totalRequests, 2)
            : 0;

        $this->newLine();
        $this->info("📈 Usage Statistics (Last {$days} days):");

        if ($format === 'table') {
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Requests', $totalRequests],
                    ['Total Credits Used', round($totalCreditsUsed, 2)],
                    ['Average Credits/Request', $avgCreditsPerRequest],
                    ['Most Used Engine', $usageStats['most_used_engine'] ?? 'N/A'],
                    ['Most Used Model', $usageStats['most_used_model'] ?? 'N/A'],
                    ['Success Rate', round((float) ($usageStats['success_rate'] ?? 0), 2) . '%'],
                ]
            );
        }

        if ($exportPath) {
            $this->exportReport($creditTable, $usageStats, $exportPath, $format);
        }
    }

    private function generateSystemReport(CreditManager $creditManager, ?string $engine, int $days, string $format, ?string $exportPath): void
    {
        $this->info("🌐 System-wide Usage Report");
        $this->newLine();

        // Real system statistics from the analytics store
        $analytics = app(AnalyticsManager::class);
        $filters = $this->buildFilters(null, $engine, $days);

        $overview = $analytics->getSystemOverview($filters);
        $engineBreakdown = $analytics->getEngineBreakdown($filters);

        $this->info("📊 System Overview (Last {$days} days):");

        if ($format === 'table') {
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Users', (int) ($overview['total_users'] ?? 0)],
                    ['Active Users', (int) ($overview['active_users'] ?? 0)],
                    ['Total Requests', (int) ($overview['total_requests'] ?? 0)],
                    ['Total Credits Used', round((float) ($overview['total_credits_used'] ?? 0), 2)],
                    ['Average Response Time', round((float) ($overview['avg_response_time'] ?? 0), 2) . 'ms'],
                    ['Error Rate', round((float) ($overview['error_rate'] ?? 0), 2) . '%'],
                ]
            );
        }

        $this->newLine();
        $this->info("🔧 Engine Usage Breakdown:");

        $engineRows = array_map(static function (array $row): array {
            return [
                $row['engine'],
                $row['requests'],
                round((float) $row['credits_used'], 2),
                ($row['avg_response_time'] !== null ? round((float) $row['avg_response_time'], 2) : 0) . 'ms',
                round((float) $row['success_rate'], 2) . '%',
            ];
        }, $engineBreakdown);

        if ($format === 'table') {
            $this->table(
                ['Engine', 'Requests', 'Credits Used', 'Avg Response Time', 'Success Rate'],
                $engineRows
            );
        }

        if ($exportPath) {
            $this->exportSystemReport([
                'overview' => $overview,
                'engine_breakdown' => $engineBreakdown,
            ], $exportPath, $format);
        }
    }

    private function buildFilters(?string $userId, ?string $engine, int $days): array
    {
        $filters = [
            'from_date' => Carbon::now()->subDays($days),
        ];

        if ($userId) {
            $filters['user_id'] = $userId;
        }

        if ($engine) {
            $filters['engine'] = $engine;
        }

        return $filters;
    }

    private function outputCsv(array $data, array $headers): void
    {
        $this->line(implode(',', $headers));
        foreach ($data as $row) {
            $this->line(implode(',', array_values($row)));
        }
    }

    private function exportReport(array $creditTable, array $usageStats, string $path, string $format): void
    {
        $data = [
            'credits' => $creditTable,
            'usage_stats' => $usageStats,
            'generated_at' => now()->toISOString(),
        ];

        $content = match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'csv' => $this->convertToCsv($data),
            default => json_encode($data, JSON_PRETTY_PRINT),
        };

        file_put_contents($path, $content);
        $this->info("📁 Report exported to: {$path}");
    }

    private function exportSystemReport(array $systemStats, string $path, string $format): void
    {
        $data = [
            'system_stats' => $systemStats,
            'generated_at' => now()->toISOString(),
        ];

        $content = match ($format) {
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'csv' => $this->convertToCsv($data),
            default => json_encode($data, JSON_PRETTY_PRINT),
        };

        file_put_contents($path, $content);
        $this->info("📁 System report exported to: {$path}");
    }

    private function convertToCsv(array $data): string
    {
        // Simple CSV conversion for complex data
        $csv = "Report Generated At," . $data['generated_at'] . "\n\n";
        
        foreach ($data as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $csv .= strtoupper(str_replace('_', ' ', $key)) . "\n";
                
                if (isset($value[0]) && is_array($value[0])) {
                    // Table data
                    $headers = array_keys($value[0]);
                    $csv .= implode(',', $headers) . "\n";
                    
                    foreach ($value as $row) {
                        $csv .= implode(',', array_values($row)) . "\n";
                    }
                } else {
                    // Key-value data
                    foreach ($value as $k => $v) {
                        $csv .= "{$k},{$v}\n";
                    }
                }
                $csv .= "\n";
            }
        }
        
        return $csv;
    }
}
