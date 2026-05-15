<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\AIModelRegistry;

class SyncAIModelsCommand extends Command
{
    protected $signature = 'ai:sync-models
                            {--provider= : Sync specific provider (openai, anthropic, google, gemini, deepseek, openrouter, fal_ai, fal, media, cloudflare_workers_ai, huggingface, replicate, comfyui, all)}
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

        if ($provider === 'all' || $provider === 'google' || $provider === 'gemini') {
            $this->syncGoogle($registry);
        }

        if ($provider === 'all' || $provider === 'deepseek') {
            $this->syncDeepSeek($registry);
        }

        if ($provider === 'all' || $provider === 'openrouter') {
            $this->syncOpenRouter($registry);
        }

        if ($provider === 'all' || $provider === 'fal_ai' || $provider === 'fal') {
            $this->syncFal($registry);
        }

        if (in_array($provider, ['all', 'media', 'cloudflare_workers_ai', 'huggingface', 'replicate', 'comfyui'], true)) {
            $this->syncMediaProviders($registry, $provider === 'media' || $provider === 'all' ? null : $provider);
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
        
        if (isset($result['new']) && $result['new'] > 0) {
            $this->warn("🆕 Added {$result['new']} new Claude models");
        }
    }

    protected function syncGoogle(AIModelRegistry $registry): void
    {
        $this->line('📡 Syncing Google Gemini models...');
        
        $result = $registry->syncGeminiModels();
        
        $this->info("✅ {$result['message']}");
        
        if (isset($result['new']) && $result['new'] > 0) {
            $this->warn("🆕 Added {$result['new']} new Gemini models");
        }
    }

    protected function syncDeepSeek(AIModelRegistry $registry): void
    {
        $this->line('📡 Syncing DeepSeek models...');
        
        $result = $registry->syncDeepSeekModels();
        
        $this->info("✅ {$result['message']}");
        
        if (isset($result['new']) && $result['new'] > 0) {
            $this->warn("🆕 Added {$result['new']} new DeepSeek models");
        }
    }

    protected function syncOpenRouter(AIModelRegistry $registry): void
    {
        $this->line('📡 Syncing OpenRouter models...');
        
        $result = $registry->syncOpenRouterModels();

        if (isset($result['error'])) {
            // Just skip if not configured - OpenRouter is optional
            if (str_contains($result['error'], 'not configured')) {
                $this->line('   ⏭️  Skipped (API key not configured - optional)');
            } else {
                $this->error("❌ {$result['error']}");
            }
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

    protected function syncFal(AIModelRegistry $registry): void
    {
        $this->line('📡 Syncing FAL models...');

        $result = $registry->syncFalModels();

        if (isset($result['error'])) {
            $this->error("❌ {$result['error']}");
            return;
        }

        $this->info("✅ Synced {$result['total']} FAL models");

        if (($result['new'] ?? 0) > 0) {
            $this->warn("🆕 Discovered {$result['new']} new FAL models");
            foreach (array_slice($result['new_models'] ?? [], 0, 10) as $modelId) {
                $this->line("   - {$modelId}");
            }
            if (($result['new'] ?? 0) > 10) {
                $this->line("   (Showing first 10 of {$result['new']} new models)");
            }
        }

        if (($result['truncated'] ?? false) === true) {
            $this->warn('⚠️  FAL sync stopped at the configured page limit.');
        }
    }

    protected function syncMediaProviders(AIModelRegistry $registry, ?string $provider = null): void
    {
        $this->line('📡 Syncing low-cost media provider models...');

        $result = $registry->syncMediaProviderModels($provider);

        $this->info("✅ Synced {$result['total']} low-cost media models");

        if (($result['new'] ?? 0) > 0) {
            $this->warn("🆕 Registered {$result['new']} new media models");
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
