<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\AIModelRegistry;

class ListAIModelsCommand extends Command
{
    protected $signature = 'ai-engine:list-models
                            {--provider= : Filter by provider}
                            {--vision : Show only vision models}
                            {--function-calling : Show only function calling models}
                            {--json : Output as JSON}';

    protected $description = 'List all available AI models';

    public function handle(AIModelRegistry $registry): int
    {
        $models = $registry->getAllModels();

        // Apply filters
        if ($provider = $this->option('provider')) {
            $models = $models->where('provider', $provider);
        }

        if ($this->option('vision')) {
            $models = $models->where('supports_vision', true);
        }

        if ($this->option('function-calling')) {
            $models = $models->where('supports_function_calling', true);
        }

        if ($this->option('json')) {
            $this->line($models->toJson(JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->displayModels($models);

        return self::SUCCESS;
    }

    protected function displayModels($models): void
    {
        if ($models->isEmpty()) {
            $this->warn('No models found matching your criteria.');
            return;
        }

        $this->info("📋 Available AI Models ({$models->count()})");
        $this->newLine();

        $grouped = $models->groupBy('provider');

        foreach ($grouped as $provider => $providerModels) {
            $this->line("🤖 <fg=cyan>{$provider}</> ({$providerModels->count()} models)");
            $this->line(str_repeat('─', 80));

            $tableData = $providerModels->map(function ($model) {
                return [
                    $model->model_id,
                    $model->name,
                    $this->formatCapabilities($model),
                    $this->formatPricing($model),
                    $model->is_deprecated ? '⚠️  Deprecated' : '✅ Active',
                ];
            })->toArray();

            $this->table(
                ['Model ID', 'Name', 'Capabilities', 'Pricing (per 1M tokens)', 'Status'],
                $tableData
            );

            $this->newLine();
        }

        // Show recommendations
        $this->showRecommendations($registry);
    }

    protected function formatCapabilities($model): string
    {
        $icons = [];
        
        if ($model->supports_vision) $icons[] = '👁️';
        if ($model->supports_function_calling) $icons[] = '⚙️';
        if ($model->supports('reasoning')) $icons[] = '🧠';
        if ($model->supports('coding')) $icons[] = '💻';
        
        return implode(' ', $icons) ?: '💬';
    }

    protected function formatPricing($model): string
    {
        if (!$model->pricing) {
            return 'N/A';
        }

        $input = $model->pricing['input'] ?? 0;
        $output = $model->pricing['output'] ?? 0;

        return sprintf('$%.2f / $%.2f', $input * 1000, $output * 1000);
    }

    protected function showRecommendations($registry): void
    {
        $this->info('💡 Recommendations:');
        
        $cheapest = $registry->getCheapestModel();
        if ($cheapest) {
            $this->line("   💰 Cheapest: {$cheapest->name} ({$cheapest->model_id})");
        }

        $vision = $registry->getRecommendedModel('vision');
        if ($vision) {
            $this->line("   👁️  Vision: {$vision->name} ({$vision->model_id})");
        }

        $coding = $registry->getRecommendedModel('coding');
        if ($coding) {
            $this->line("   💻 Coding: {$coding->name} ({$coding->model_id})");
        }
    }
}
