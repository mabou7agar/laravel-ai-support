<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Vector\VectorAnalyticsService;

class VectorAnalyticsCommand extends Command
{
    protected $signature = 'ai-engine:vector-analytics
                            {--user= : User ID to get analytics for}
                            {--model= : Model class to get analytics for}
                            {--days=30 : Number of days to analyze}
                            {--export= : Export to CSV file}
                            {--global : Show global analytics}';

    protected $description = 'View vector search analytics';

    public function handle(VectorAnalyticsService $analytics): int
    {
        $days = (int) $this->option('days');

        try {
            if ($this->option('global')) {
                $this->showGlobalAnalytics($analytics, $days);
            } elseif ($userId = $this->option('user')) {
                $this->showUserAnalytics($analytics, $userId, $days);
            } elseif ($modelClass = $this->option('model')) {
                $this->showModelAnalytics($analytics, $modelClass, $days);
            } else {
                $this->showGlobalAnalytics($analytics, $days);
            }

            // Export if requested
            if ($exportPath = $this->option('export')) {
                if ($userId = $this->option('user')) {
                    $csv = $analytics->exportToCsv($userId, $days);
                    file_put_contents($exportPath, $csv);
                    $this->info("✓ Exported to {$exportPath}");
                } else {
                    $this->warn('Export requires --user option');
                }
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Analytics failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function showGlobalAnalytics(VectorAnalyticsService $analytics, int $days): void
    {
        $this->info("Global Vector Search Analytics (Last {$days} days)");
        $this->newLine();

        $stats = $analytics->getGlobalAnalytics($days);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Searches', number_format($stats['summary']['total_searches'])],
                ['Unique Users', number_format($stats['summary']['unique_users'])],
                ['Total Results', number_format($stats['summary']['total_results'])],
                ['Avg Results/Search', $stats['summary']['avg_results_per_search']],
                ['Avg Execution Time', $stats['summary']['avg_execution_time'] . 'ms'],
                ['Total Tokens Used', number_format($stats['summary']['total_tokens_used'])],
                ['Success Rate', $stats['summary']['success_rate'] . '%'],
            ]
        );

        if (!empty($stats['popular_models'])) {
            $this->newLine();
            $this->info('Most Searched Models:');
            $this->table(
                ['Model', 'Searches'],
                collect($stats['popular_models'])->map(fn($m) => [$m->model_type, $m->count])->toArray()
            );
        }
    }

    protected function showUserAnalytics(VectorAnalyticsService $analytics, string $userId, int $days): void
    {
        $this->info("User Analytics for User #{$userId} (Last {$days} days)");
        $this->newLine();

        $stats = $analytics->getUserAnalytics($userId, $days);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Searches', number_format($stats['summary']['total_searches'])],
                ['Total Results', number_format($stats['summary']['total_results'])],
                ['Avg Results/Search', $stats['summary']['avg_results_per_search']],
                ['Avg Execution Time', $stats['summary']['avg_execution_time'] . 'ms'],
                ['Total Tokens Used', number_format($stats['summary']['total_tokens_used'])],
                ['Successful Searches', number_format($stats['summary']['successful_searches'])],
                ['Failed Searches', number_format($stats['summary']['failed_searches'])],
                ['Success Rate', $stats['summary']['success_rate'] . '%'],
            ]
        );

        if (!empty($stats['popular_queries'])) {
            $this->newLine();
            $this->info('Popular Queries:');
            $this->table(
                ['Query', 'Count'],
                collect($stats['popular_queries'])->take(10)->map(fn($q) => [$q->query, $q->count])->toArray()
            );
        }
    }

    protected function showModelAnalytics(VectorAnalyticsService $analytics, string $modelClass, int $days): void
    {
        $this->info("Model Analytics for {$modelClass} (Last {$days} days)");
        $this->newLine();

        $stats = $analytics->getModelAnalytics($modelClass, $days);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Searches', number_format($stats['summary']['total_searches'])],
                ['Unique Users', number_format($stats['summary']['unique_users'])],
                ['Total Results', number_format($stats['summary']['total_results'])],
                ['Avg Results/Search', $stats['summary']['avg_results_per_search']],
                ['Avg Execution Time', $stats['summary']['avg_execution_time'] . 'ms'],
                ['Avg Threshold', $stats['summary']['avg_threshold']],
            ]
        );

        if (!empty($stats['popular_queries'])) {
            $this->newLine();
            $this->info('Popular Queries:');
            $this->table(
                ['Query', 'Searches', 'Avg Results'],
                collect($stats['popular_queries'])->take(10)->map(fn($q) => [
                    $q->query,
                    $q->count,
                    round($q->avg_results, 2)
                ])->toArray()
            );
        }
    }
}
