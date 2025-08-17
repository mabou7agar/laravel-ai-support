<?php

namespace MagicAI\LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;

class ClearCacheCommand extends Command
{
    protected $signature = 'ai:clear-cache 
                           {--engine= : Clear cache for specific engine only}
                           {--type= : Cache type to clear (responses, models, analytics, webhooks, all)}
                           {--force : Force clear without confirmation}';

    protected $description = 'Clear AI engine response cache and related cached data';

    public function handle(): int
    {
        $engine = $this->option('engine');
        $type = $this->option('type') ?? 'all';
        $force = $this->option('force');

        if (!$force && !$this->confirm('Are you sure you want to clear the AI cache?')) {
            $this->info('Cache clearing cancelled.');
            return self::SUCCESS;
        }

        $this->info('🧹 Clearing AI Engine Cache...');
        $this->newLine();

        $clearedItems = 0;

        switch ($type) {
            case 'responses':
                $clearedItems += $this->clearResponseCache($engine);
                break;
            case 'models':
                $clearedItems += $this->clearModelCache($engine);
                break;
            case 'analytics':
                $clearedItems += $this->clearAnalyticsCache();
                break;
            case 'webhooks':
                $clearedItems += $this->clearWebhookCache();
                break;
            case 'all':
            default:
                $clearedItems += $this->clearResponseCache($engine);
                $clearedItems += $this->clearModelCache($engine);
                $clearedItems += $this->clearAnalyticsCache();
                $clearedItems += $this->clearWebhookCache();
                break;
        }

        $this->newLine();
        $this->info("✅ Cache cleared successfully! Removed {$clearedItems} cached items.");

        return self::SUCCESS;
    }

    private function clearResponseCache(?string $engine): int
    {
        $this->line('🔄 Clearing response cache...');
        
        $cleared = 0;
        $engines = $engine ? [EngineEnum::from($engine)] : EngineEnum::cases();

        foreach ($engines as $eng) {
            $pattern = "ai_response_cache_{$eng->value}_*";
            $keys = $this->getCacheKeys($pattern);
            
            foreach ($keys as $key) {
                Cache::forget($key);
                $cleared++;
            }
            
            $this->line("  - Cleared {$eng->value} response cache");
        }

        // Clear general response cache
        $generalKeys = $this->getCacheKeys('ai_response_*');
        foreach ($generalKeys as $key) {
            Cache::forget($key);
            $cleared++;
        }

        return $cleared;
    }

    private function clearModelCache(?string $engine): int
    {
        $this->line('🤖 Clearing model cache...');
        
        $cleared = 0;
        $engines = $engine ? [EngineEnum::from($engine)] : EngineEnum::cases();

        foreach ($engines as $eng) {
            $cacheKey = "ai_models_{$eng->value}";
            if (Cache::has($cacheKey)) {
                Cache::forget($cacheKey);
                $cleared++;
                $this->line("  - Cleared {$eng->value} model cache");
            }
        }

        // Clear model availability cache
        $availabilityKeys = $this->getCacheKeys('ai_model_availability_*');
        foreach ($availabilityKeys as $key) {
            Cache::forget($key);
            $cleared++;
        }

        return $cleared;
    }

    private function clearAnalyticsCache(): int
    {
        $this->line('📊 Clearing analytics cache...');
        
        $cleared = 0;
        $analyticsKeys = [
            'ai_analytics_usage_*',
            'ai_analytics_costs_*',
            'ai_analytics_performance_*',
            'ai_usage_stats_*',
            'ai_cost_analysis_*',
        ];

        foreach ($analyticsKeys as $pattern) {
            $keys = $this->getCacheKeys($pattern);
            foreach ($keys as $key) {
                Cache::forget($key);
                $cleared++;
            }
        }

        $this->line("  - Cleared analytics cache");
        return $cleared;
    }

    private function clearWebhookCache(): int
    {
        $this->line('🔗 Clearing webhook cache...');
        
        $cleared = 0;
        $webhookKeys = [
            'ai_engine_webhook_endpoints',
            'ai_engine_webhook_logs_*',
        ];

        foreach ($webhookKeys as $pattern) {
            if (str_contains($pattern, '*')) {
                $keys = $this->getCacheKeys($pattern);
                foreach ($keys as $key) {
                    Cache::forget($key);
                    $cleared++;
                }
            } else {
                if (Cache::has($pattern)) {
                    Cache::forget($pattern);
                    $cleared++;
                }
            }
        }

        $this->line("  - Cleared webhook cache");
        return $cleared;
    }

    private function getCacheKeys(string $pattern): array
    {
        // This is a simplified implementation
        // In a real scenario, you'd need to implement cache key scanning
        // based on your cache driver (Redis, Memcached, etc.)
        
        $keys = [];
        $cacheDriver = config('cache.default');
        
        if ($cacheDriver === 'redis') {
            try {
                $redis = Cache::getRedis();
                $keys = $redis->keys(str_replace('*', '*', $pattern));
            } catch (\Exception $e) {
                // Fallback to known cache keys
                $keys = $this->getKnownCacheKeys($pattern);
            }
        } else {
            // For other drivers, use known cache keys
            $keys = $this->getKnownCacheKeys($pattern);
        }

        return $keys;
    }

    private function getKnownCacheKeys(string $pattern): array
    {
        // Return known cache keys that match the pattern
        $knownKeys = [];
        
        if (str_contains($pattern, 'ai_response_cache_')) {
            foreach (EngineEnum::cases() as $engine) {
                for ($i = 0; $i < 100; $i++) { // Check up to 100 possible cache entries
                    $key = "ai_response_cache_{$engine->value}_{$i}";
                    if (Cache::has($key)) {
                        $knownKeys[] = $key;
                    }
                }
            }
        }

        if (str_contains($pattern, 'ai_analytics_')) {
            $analyticsPatterns = [
                'ai_analytics_usage_daily',
                'ai_analytics_usage_weekly',
                'ai_analytics_usage_monthly',
                'ai_analytics_costs_daily',
                'ai_analytics_costs_weekly',
                'ai_analytics_costs_monthly',
            ];
            
            foreach ($analyticsPatterns as $key) {
                if (Cache::has($key)) {
                    $knownKeys[] = $key;
                }
            }
        }

        if (str_contains($pattern, 'ai_engine_webhook_logs_')) {
            $webhookEvents = ['started', 'completed', 'error', 'low_credits', 'rate_limit'];
            foreach ($webhookEvents as $event) {
                $key = "ai_engine_webhook_logs_{$event}";
                if (Cache::has($key)) {
                    $knownKeys[] = $key;
                }
            }
        }

        return $knownKeys;
    }
}
