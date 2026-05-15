<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\GraphBenchmarkHistoryService;

class GraphBenchmarkHistoryCommand extends Command
{
    protected $signature = 'ai:benchmark-history
                            {--type= : One of retrieval, chat, indexing}
                            {--limit=10 : Number of history entries to show}';

    protected $description = 'Show persisted graph benchmark history';

    public function handle(GraphBenchmarkHistoryService $history): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $type = trim((string) ($this->option('type') ?: ''));
        $rows = $type !== ''
            ? $history->latest($type, $limit)
            : $history->latestAll($limit);

        if ($rows === []) {
            $this->warn('No benchmark history recorded yet.');

            return self::SUCCESS;
        }

        $table = array_map(function (array $row): array {
            return [
                'recorded_at' => $row['recorded_at'] ?? 'n/a',
                'type' => $row['type'] ?? 'n/a',
                'query' => $row['query'] ?? ($row['message'] ?? 'n/a'),
                'avg_ms' => $row['avg_ms'] ?? 'n/a',
                'details' => $row['details'] ?? 'n/a',
            ];
        }, $rows);

        $this->table(['recorded_at', 'type', 'query', 'avg_ms', 'details'], $table);

        return self::SUCCESS;
    }
}
