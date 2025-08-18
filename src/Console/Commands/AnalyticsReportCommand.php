<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Analytics\AnalyticsManager;

class AnalyticsReportCommand extends Command
{
    protected $signature = 'ai-engine:analytics-report 
                            {--period=monthly : Report period (daily, weekly, monthly, yearly)}
                            {--format=table : Output format (table, json, csv)}
                            {--engine= : Filter by specific engine}
                            {--export= : Export to file path}';

    protected $description = 'Generate comprehensive analytics report for AI Engine usage';

    public function handle(AnalyticsManager $analyticsManager): int
    {
        $this->info('Generating AI Engine Analytics Report...');

        $options = [
            'period' => $this->option('period'),
            'format' => $this->option('format'),
            'engine' => $this->option('engine'),
        ];

        try {
            $report = $analyticsManager->generateReport($options);

            if ($this->option('export')) {
                $this->exportReport($report, $this->option('export'));
                $this->info("Report exported to: {$this->option('export')}");
                return 0;
            }

            $this->displayReport($report, $options['format']);

        } catch (\Exception $e) {
            $this->error("Failed to generate report: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }

    protected function displayReport(array $report, string $format): void
    {
        switch ($format) {
            case 'json':
                $this->line(json_encode($report, JSON_PRETTY_PRINT));
                break;

            case 'csv':
                $this->displayCsvReport($report);
                break;

            case 'table':
            default:
                $this->displayTableReport($report);
                break;
        }
    }

    protected function displayTableReport(array $report): void
    {
        $this->info('=== AI Engine Analytics Report ===');
        $this->newLine();

        if (isset($report['summary'])) {
            $this->info('Summary:');
            $this->table(
                ['Metric', 'Value'],
                collect($report['summary'])->map(fn($value, $key) => [
                    ucwords(str_replace('_', ' ', $key)),
                    is_numeric($value) ? number_format($value, 2) : $value
                ])->toArray()
            );
            $this->newLine();
        }

        if (isset($report['engine_usage'])) {
            $this->info('Engine Usage:');
            $this->table(
                ['Engine', 'Requests', 'Cost', 'Avg Response Time'],
                collect($report['engine_usage'])->map(fn($data, $engine) => [
                    $engine,
                    number_format($data['requests'] ?? 0),
                    '$' . number_format($data['cost'] ?? 0, 2),
                    number_format($data['avg_response_time'] ?? 0, 2) . 's'
                ])->toArray()
            );
        }
    }

    protected function displayCsvReport(array $report): void
    {
        if (isset($report['summary'])) {
            $this->line('Summary');
            $this->line('Metric,Value');
            foreach ($report['summary'] as $key => $value) {
                $this->line(ucwords(str_replace('_', ' ', $key)) . ',' . $value);
            }
            $this->newLine();
        }
    }

    protected function exportReport(array $report, string $filePath): void
    {
        $content = json_encode($report, JSON_PRETTY_PRINT);
        file_put_contents($filePath, $content);
    }
}
