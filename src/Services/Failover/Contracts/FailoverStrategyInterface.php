<?php

namespace LaravelAIEngine\Services\Failover\Contracts;

/**
 * Interface for failover strategies
 */
interface FailoverStrategyInterface
{
    /**
     * Get ordered list of providers based on strategy
     */
    public function getProviderOrder(array $providers, array $healthData): array;

    /**
     * Get strategy name
     */
    public function getName(): string;

    /**
     * Get strategy description
     */
    public function getDescription(): string;
}
