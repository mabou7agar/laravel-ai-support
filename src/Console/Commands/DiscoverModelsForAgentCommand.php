<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentCollectionAdapter;
use Illuminate\Support\Facades\Cache;

class DiscoverModelsForAgentCommand extends Command
{
    protected $signature = 'ai:discover-agent-models 
                            {--refresh : Refresh cached models}
                            {--show-details : Show detailed information for each model}';

    protected $description = 'Discover models for AI Agent using RAG discovery and model analysis';

    public function handle(AgentCollectionAdapter $adapter)
    {
        $this->info('🔍 Discovering models for AI Agent...');
        $this->info('Using: RAGCollectionDiscovery + ModelAnalyzer');
        $this->newLine();

        $useCache = !$this->option('refresh');

        if ($this->option('refresh')) {
            $this->warn('Refreshing cache...');
            Cache::forget('agent_discovered_models');
            Cache::forget('ai_action_registry_actions');
        }

        try {
            $models = $adapter->discoverForAgent($useCache);

            if (empty($models)) {
                $this->warn('⚠️  No models discovered.');
                $this->newLine();
                $this->line('Make sure your models:');
                $this->line('  1. Use the Vectorizable trait');
                $this->line('  2. Are in the configured discovery paths');
                $this->line('  3. Have proper namespace declarations');
                return 1;
            }

            // Sort by complexity score (descending)
            usort($models, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

            $this->displayModels($models);

            // Cache for ComplexityAnalyzer
            Cache::put('agent_discovered_models', $models, now()->addDay());

            $this->newLine();
            $this->info('✅ Discovery complete! Models cached for AI Agent.');
            $this->line('Cache expires in: 24 hours');

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Discovery failed: ' . $e->getMessage());

            if ($this->option('verbose')) {
                $this->error($e->getTraceAsString());
            }

            return 1;
        }
    }

    protected function displayModels(array $models): void
    {
        $high = [];
        $medium = [];
        $simple = [];

        foreach ($models as $model) {
            $icon = match ($model['complexity']) {
                'HIGH' => '🔴',
                'MEDIUM' => '🟡',
                'SIMPLE' => '🟢',
            };

            $this->line("{$icon} {$model['display_name']} ({$model['complexity']})");
            $this->line("   Strategy: {$model['strategy']}");
            $this->line("   Relationships: {$model['relationship_count']}");

            if ($this->option('show-details')) {
                $this->line("   Description: {$model['description']}");
                $this->line("   Keywords: " . implode(', ', $model['keywords']));
                $this->line("   Score: {$model['score']}");

                if (!empty($model['relationships'])) {
                    $this->line("   Relations:");
                    foreach ($model['relationships'] as $rel) {
                        $required = $rel['required'] ? '(required)' : '(optional)';
                        $this->line("     • {$rel['name']} ({$rel['type']}) {$required}");
                    }
                }
            }

            $this->newLine();

            match ($model['complexity']) {
                'HIGH' => $high[] = $model,
                'MEDIUM' => $medium[] = $model,
                'SIMPLE' => $simple[] = $model,
            };
        }

        $this->displaySummary($high, $medium, $simple);
    }

    protected function displaySummary(array $high, array $medium, array $simple): void
    {
        $this->info('📊 Summary:');
        $this->newLine();

        $this->table(
            ['Complexity', 'Count', 'Strategy', 'Models'],
            [
                [
                    'HIGH',
                    count($high),
                    'guided_flow',
                    $this->formatModelList($high, 3)
                ],
                [
                    'MEDIUM',
                    count($medium),
                    'guided_flow',
                    $this->formatModelList($medium, 3)
                ],
                [
                    'SIMPLE',
                    count($simple),
                    'quick_action',
                    $this->formatModelList($simple, 3)
                ],
            ]
        );

        $this->newLine();

        // Show what this means
        $this->info('💡 What this means:');
        $this->line('  🔴 HIGH (guided_flow): Multi-step data collection with validation');
        $this->line('  🟡 MEDIUM (guided_flow): Step-by-step data collection');
        $this->line('  🟢 SIMPLE (quick_action): Immediate execution');
    }

    protected function formatModelList(array $models, int $limit = 3): string
    {
        if (empty($models)) {
            return '-';
        }

        $names = array_map(fn($m) => $m['name'], $models);

        if (count($names) <= $limit) {
            return implode(', ', $names);
        }

        $shown = array_slice($names, 0, $limit);
        $remaining = count($names) - $limit;

        return implode(', ', $shown) . " (+{$remaining} more)";
    }
}
