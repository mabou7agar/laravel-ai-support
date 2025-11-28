<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Facades\AIEngine;

class SyncModelsCommand extends Command
{
    protected $signature = 'ai-engine:sync-models 
                           {--engine= : Sync models for specific engine only}
                           {--force : Force sync even if models exist}';

    protected $description = 'Sync available models from AI providers';

    public function handle(): int
    {
        $this->info('🔄 Syncing AI Models...');
        $this->newLine();

        $engineFilter = $this->option('engine');
        $force = $this->option('force');

        if ($engineFilter) {
            try {
                $engines = [EngineEnum::from($engineFilter)];
            } catch (\ValueError $e) {
                $this->error("❌ Invalid engine: {$engineFilter}");
                $this->line("Available engines: " . implode(', ', array_map(fn($e) => $e->value, EngineEnum::cases())));
                return self::FAILURE;
            }
        } else {
            $engines = EngineEnum::cases();
        }

        $totalSynced = 0;

        foreach ($engines as $engine) {
            if (!$this->isEngineConfigured($engine)) {
                $this->warn("⚠️  {$engine->value} - API key not configured");
                continue;
            }

            $this->info("🔧 Syncing models for {$engine->value}...");
            
            try {
                $models = $this->syncEngineModels($engine, $force);
                $totalSynced += count($models);
                
                $this->line("  ✅ Synced " . count($models) . " models");
                
                foreach ($models as $model) {
                    $this->line("    - {$model}");
                }
                
            } catch (\Exception $e) {
                $this->error("  ❌ Failed to sync models: " . $e->getMessage());
            }
            
            $this->newLine();
        }

        $this->info("🎉 Sync completed! Total models synced: {$totalSynced}");
        
        return self::SUCCESS;
    }

    private function isEngineConfigured(EngineEnum $engine): bool
    {
        // Build config key from engine value
        $configKey = "ai-engine.engines.{$engine->value}.api_key";
        
        $apiKey = config($configKey);
        
        // Check if API key is a non-empty string
        return is_string($apiKey) && !empty(trim($apiKey));
    }

    private function syncEngineModels(EngineEnum $engine, bool $force): array
    {
        // This would typically call the AI provider's API to get available models
        // For now, return predefined models
        return match ($engine) {
            EngineEnum::OPENAI => [
                'gpt-4o',
                'gpt-4o-mini',
                'gpt-3.5-turbo',
                'dall-e-3',
                'dall-e-2',
                'whisper-1',
                'tts-1',
            ],
            EngineEnum::ANTHROPIC => [
                'claude-3-5-sonnet-20241022',
                'claude-3-haiku-20240307',
                'claude-3-opus-20240229',
            ],
            EngineEnum::GEMINI => [
                'gemini-1.5-pro',
                'gemini-1.5-flash',
                'gemini-pro-vision',
            ],
            default => [],
        };
    }
}
