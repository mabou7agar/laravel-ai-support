<?php

namespace LaravelAIEngine\Services\Failover\Strategies;

use LaravelAIEngine\Services\Failover\Contracts\FailoverStrategyInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Round-robin failover strategy
 * Distributes load evenly across healthy providers
 */
class RoundRobinStrategy implements FailoverStrategyInterface
{
    protected string $cacheKey = 'ai_engine.round_robin.index';

    /**
     * Get ordered list of providers based on round-robin rotation
     */
    public function getProviderOrder(array $providers, array $healthData): array
    {
        // Filter out unhealthy providers (health score < 0.3)
        $healthyProviders = array_filter($providers, function ($provider) use ($healthData) {
            $health = $healthData[$provider] ?? ['health_score' => 0.5];
            return ($health['health_score'] ?? 0.5) >= 0.3;
        });

        if (empty($healthyProviders)) {
            // If no healthy providers, return all providers as fallback
            return $providers;
        }

        // Get current index and increment for next time
        $currentIndex = Cache::get($this->cacheKey, 0);
        $nextIndex = ($currentIndex + 1) % count($healthyProviders);
        Cache::put($this->cacheKey, $nextIndex, 3600); // 1 hour TTL

        // Reorder array starting from current index
        $reorderedProviders = [];
        $providersList = array_values($healthyProviders);
        
        for ($i = 0; $i < count($providersList); $i++) {
            $index = ($currentIndex + $i) % count($providersList);
            $reorderedProviders[] = $providersList[$index];
        }

        return $reorderedProviders;
    }

    /**
     * Get strategy name
     */
    public function getName(): string
    {
        return 'round_robin';
    }

    /**
     * Get strategy description
     */
    public function getDescription(): string
    {
        return 'Distributes requests evenly across healthy providers in rotation';
    }
}
