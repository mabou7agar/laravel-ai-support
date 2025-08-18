<?php

namespace LaravelAIEngine\Services\Failover\Strategies;

use LaravelAIEngine\Services\Failover\Contracts\FailoverStrategyInterface;

/**
 * Priority-based failover strategy
 * Orders providers by health score and configured priority
 */
class PriorityStrategy implements FailoverStrategyInterface
{
    /**
     * Get ordered list of providers based on priority and health
     */
    public function getProviderOrder(array $providers, array $healthData): array
    {
        $priorities = config('ai-engine.failover.provider_priorities', []);
        
        // Create provider data with health and priority
        $providerData = [];
        foreach ($providers as $provider) {
            $health = $healthData[$provider] ?? ['health_score' => 0.5];
            $priority = $priorities[$provider] ?? 50; // Default priority
            
            $providerData[] = [
                'provider' => $provider,
                'health_score' => $health['health_score'] ?? 0.5,
                'priority' => $priority,
                'combined_score' => $this->calculateCombinedScore($health['health_score'] ?? 0.5, $priority)
            ];
        }
        
        // Sort by combined score (descending)
        usort($providerData, function ($a, $b) {
            return $b['combined_score'] <=> $a['combined_score'];
        });
        
        return array_column($providerData, 'provider');
    }

    /**
     * Get strategy name
     */
    public function getName(): string
    {
        return 'priority';
    }

    /**
     * Get strategy description
     */
    public function getDescription(): string
    {
        return 'Orders providers by configured priority and current health score';
    }

    /**
     * Calculate combined score from health and priority
     */
    protected function calculateCombinedScore(float $healthScore, int $priority): float
    {
        // Normalize priority (0-100) to 0-1 scale
        $normalizedPriority = $priority / 100;
        
        // Weight: 70% health, 30% priority
        return ($healthScore * 0.7) + ($normalizedPriority * 0.3);
    }
}
