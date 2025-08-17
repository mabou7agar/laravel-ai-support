<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
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

        // Usage statistics (mock data for demonstration)
        $usageStats = $this->getMockUsageStats($userId, $engine, $days);
        
        $this->newLine();
        $this->info("📈 Usage Statistics (Last {$days} days):");
        
        if ($format === 'table') {
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Requests', $usageStats['total_requests']],
                    ['Total Credits Used', $usageStats['total_credits_used']],
                    ['Average Credits/Request', $usageStats['avg_credits_per_request']],
                    ['Most Used Engine', $usageStats['most_used_engine']],
                    ['Most Used Model', $usageStats['most_used_model']],
                    ['Success Rate', $usageStats['success_rate'] . '%'],
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

        // Mock system statistics
        $systemStats = $this->getMockSystemStats($engine, $days);
        
        $this->info("📊 System Overview (Last {$days} days):");
        
        if ($format === 'table') {
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Users', $systemStats['total_users']],
                    ['Active Users', $systemStats['active_users']],
                    ['Total Requests', $systemStats['total_requests']],
                    ['Total Credits Used', $systemStats['total_credits_used']],
                    ['Average Response Time', $systemStats['avg_response_time'] . 'ms'],
                    ['Error Rate', $systemStats['error_rate'] . '%'],
                ]
            );
        }

        $this->newLine();
        $this->info("🔧 Engine Usage Breakdown:");
        
        if ($format === 'table') {
            $this->table(
                ['Engine', 'Requests', 'Credits Used', 'Avg Response Time', 'Success Rate'],
                $systemStats['engine_breakdown']
            );
        }

        $this->newLine();
        $this->info("📅 Daily Usage Trend:");
        
        if ($format === 'table') {
            $this->table(
                ['Date', 'Requests', 'Credits Used', 'Unique Users'],
                $systemStats['daily_trend']
            );
        }

        if ($exportPath) {
            $this->exportSystemReport($systemStats, $exportPath, $format);
        }
    }

    private function getMockUsageStats(string $userId, ?string $engine, int $days): array
    {
        // In a real implementation, this would query the database
        return [
            'total_requests' => rand(50, 500),
            'total_credits_used' => rand(100, 1000),
            'avg_credits_per_request' => round(rand(1, 10), 2),
            'most_used_engine' => $engine ?? 'openai',
            'most_used_model' => 'gpt-4o',
            'success_rate' => rand(85, 99),
        ];
    }

    private function getMockSystemStats(?string $engine, int $days): array
    {
        $engines = ['openai', 'anthropic', 'gemini', 'stable_diffusion'];
        if ($engine) {
            $engines = [$engine];
        }

        $engineBreakdown = [];
        foreach ($engines as $eng) {
            $engineBreakdown[] = [
                $eng,
                rand(100, 1000),
                rand(500, 5000),
                rand(500, 2000) . 'ms',
                rand(85, 99) . '%',
            ];
        }

        $dailyTrend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $dailyTrend[] = [
                $date,
                rand(10, 100),
                rand(50, 500),
                rand(5, 50),
            ];
        }

        return [
            'total_users' => rand(100, 1000),
            'active_users' => rand(50, 500),
            'total_requests' => rand(1000, 10000),
            'total_credits_used' => rand(5000, 50000),
            'avg_response_time' => rand(500, 2000),
            'error_rate' => rand(1, 5),
            'engine_breakdown' => $engineBreakdown,
            'daily_trend' => $dailyTrend,
        ];
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
