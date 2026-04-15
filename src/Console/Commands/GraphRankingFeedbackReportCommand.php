<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\GraphRankingFeedbackService;

class GraphRankingFeedbackReportCommand extends Command
{
    protected $signature = 'ai-engine:graph-ranking-feedback
                            {query_kind=generic : Query kind to inspect}';

    protected $description = 'Show adaptive graph ranking feedback state for a query kind';

    public function handle(GraphRankingFeedbackService $feedback): int
    {
        $queryKind = trim((string) $this->argument('query_kind')) ?: 'generic';
        $report = $feedback->report($queryKind);

        $this->table(['Metric', 'Value'], [
            ['query_kind', $report['query_kind'] ?? $queryKind],
            ['samples', $report['samples'] ?? 0],
            ['vector_dominant', $report['vector_dominant'] ?? 0],
            ['lexical_dominant', $report['lexical_dominant'] ?? 0],
            ['relation_helpful', $report['relation_helpful'] ?? 0],
            ['selected_seed_helpful', $report['selected_seed_helpful'] ?? 0],
            ['empty_results', $report['empty_results'] ?? 0],
            ['cache_hits', $report['cache_hits'] ?? 0],
        ]);

        return self::SUCCESS;
    }
}
