<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Vector\VectorSearchService;

class VectorSearchCommand extends Command
{
    protected $signature = 'ai-engine:vector-search
                            {model : The model class to search}
                            {query : The search query}
                            {--limit=10 : Number of results to return}
                            {--threshold=0.3 : Minimum similarity threshold}
                            {--json : Output results as JSON}';

    protected $description = 'Search the vector database';

    public function handle(VectorSearchService $vectorSearch): int
    {
        $modelClass = $this->argument('model');
        $query = $this->argument('query');
        
        if (!class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");
            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $threshold = (float) $this->option('threshold');

        $this->info("Searching {$modelClass} for: \"{$query}\"");
        $this->info("Limit: {$limit}, Threshold: {$threshold}");
        $this->newLine();

        try {
            $startTime = microtime(true);
            
            $results = $vectorSearch->search(
                $modelClass,
                $query,
                $limit,
                $threshold
            );

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($this->option('json')) {
                $this->line(json_encode([
                    'query' => $query,
                    'results_count' => $results->count(),
                    'execution_time' => $executionTime,
                    'results' => $results->map(function ($result) {
                        return [
                            'id' => $result->id,
                            'score' => $result->vector_score ?? null,
                            'data' => $result->toArray(),
                        ];
                    }),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->info("Found {$results->count()} results in {$executionTime}ms");
                $this->newLine();

                if ($results->isEmpty()) {
                    $this->warn('No results found');
                    return self::SUCCESS;
                }

                $headers = ['ID', 'Score', 'Preview'];
                $rows = [];

                foreach ($results as $result) {
                    $preview = '';
                    if (method_exists($result, 'getVectorContent')) {
                        $preview = substr($result->getVectorContent(), 0, 100) . '...';
                    }

                    $rows[] = [
                        $result->id,
                        round($result->vector_score ?? 0, 4),
                        $preview,
                    ];
                }

                $this->table($headers, $rows);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Search failed: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
