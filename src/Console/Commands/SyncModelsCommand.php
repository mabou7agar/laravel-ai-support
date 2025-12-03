<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Facades\AIEngine;

class SyncModelsCommand extends Command
{
    protected $signature = 'ai-engine:sync-engine-models 
                           {--engine= : Sync models for specific engine only}
                           {--force : Force sync even if models exist}';

    protected $description = 'Sync available models from AI providers (simple list)';

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
        try {
            return match ($engine->value) {
                'openai' => $this->syncOpenAIModels(),
                'anthropic' => $this->syncAnthropicModels(),
                'openrouter' => $this->syncOpenRouterModels(),
                default => [],
            };
        } catch (\Exception $e) {
            $this->error("Failed to sync {$engine->value}: " . $e->getMessage());
            return [];
        }
    }

    private function syncOpenAIModels(): array
    {
        $apiKey = config('ai-engine.engines.openai.api_key');
        $baseUrl = config('ai-engine.engines.openai.base_url', 'https://api.openai.com/v1');
        
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->get($baseUrl . '/models');

        if (!$response->successful()) {
            throw new \Exception('OpenAI API request failed: ' . $response->body());
        }

        $models = collect($response->json('data', []))
            ->pluck('id')
            ->filter(fn($id) => str_contains($id, 'gpt') || str_contains($id, 'dall-e') || str_contains($id, 'whisper') || str_contains($id, 'tts'))
            ->sort()
            ->values()
            ->toArray();

        return $models;
    }

    private function syncAnthropicModels(): array
    {
        // Anthropic doesn't have a public models API endpoint
        // Return manually maintained list
        return [
            'claude-3-5-sonnet-20241022',
            'claude-3-5-sonnet-20240620',
            'claude-3-opus-20240229',
            'claude-3-haiku-20240307',
        ];
    }

    private function syncOpenRouterModels(): array
    {
        $apiKey = config('ai-engine.engines.openrouter.api_key');
        
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'HTTP-Referer' => config('app.url', 'http://localhost'),
            'X-Title' => config('app.name', 'Laravel'),
        ])->get('https://openrouter.ai/api/v1/models');

        if (!$response->successful()) {
            throw new \Exception('OpenRouter API request failed: ' . $response->body());
        }

        $models = collect($response->json('data', []))
            ->pluck('id')
            ->sort()
            ->values()
            ->toArray();

        return $models;
    }
}
