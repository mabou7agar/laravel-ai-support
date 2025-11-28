<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\AIModelRegistry;

class SyncAIModelsCommand extends Command
{
    protected $signature = 'ai-engine:sync-models
                            {--provider= : Sync specific provider (openai, anthropic, openrouter, all)}
                            {--auto-discover : Auto-discover new models from APIs}';

    protected $description = 'Sync AI models from providers and auto-discover new models';

    public function handle(AIModelRegistry $registry): int
    {
        $this->info('🔄 Syncing AI Models...');
        $this->newLine();

        $provider = $this->option('provider') ?? 'all';

        if ($provider === 'all' || $provider === 'openai') {
            $this->syncOpenAI($registry);
        }

        if ($provider === 'all' || $provider === 'anthropic') {
            $this->syncAnthropic($registry);
        }

        if ($provider === 'all' || $provider === 'openrouter') {
            $this->syncOpenRouter($registry);
        }

        $this->newLine();
        $this->displayStatistics($registry);

        return self::SUCCESS;
    }

    protected function syncOpenAI(AIModelRegistry $registry): void
    {
        $this->line('📡 Syncing OpenAI models...');
        
        $result = $registry->syncOpenAIModels();

        if (isset($result['error'])) {
            $this->error("❌ {$result['error']}");
            return;
        }

        $this->info("✅ Synced {$result['total']} OpenAI models");
        
        if ($result['new'] > 0) {
            $this->warn("🆕 Discovered {$result['new']} new models:");
            foreach ($result['new_models'] as $modelId) {
                $this->line("   - {$modelId}");
            }
        }
    }

    protected function syncAnthropic(AIModelRegistry $registry): void
    {
        $this->line('📡 Syncing Anthropic models...');
        
        $result = $registry->syncAnthropicModels();
        
        $this->info("✅ {$result['message']}");
    }

    protected function syncOpenRouter(AIModelRegistry $registry): void
    {
        $this->line('📡 Syncing OpenRouter models...');
        
        $result = $registry->syncOpenRouterModels();

        if (isset($result['error'])) {
            $this->error("❌ {$result['error']}");
            return;
        }

        $this->info("✅ Synced {$result['total']} OpenRouter models");
        
        if ($result['new'] > 0) {
            $this->warn("🆕 Discovered {$result['new']} new models");
            if (count($result['new_models']) <= 10) {
                foreach ($result['new_models'] as $modelId) {
                    $this->line("   - {$modelId}");
                }
            } else {
                $this->line("   (Showing first 10 of {$result['new']} new models)");
                foreach (array_slice($result['new_models'], 0, 10) as $modelId) {
                    $this->line("   - {$modelId}");
                }
            }
        }
    }

    protected function displayStatistics(AIModelRegistry $registry): void
    {
        $stats = $registry->getStatistics();

        $this->info('📊 Model Statistics:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Models', $stats['total']],
                ['Active Models', $stats['active']],
                ['Deprecated Models', $stats['deprecated']],
                ['Vision Models', $stats['with_vision']],
                ['Function Calling', $stats['with_function_calling']],
            ]
        );

        $this->info('📦 Models by Provider:');
        foreach ($stats['by_provider'] as $provider => $count) {
            $this->line("   {$provider}: {$count} models");
        }
    }
}
