<?php

namespace LaravelAIEngine\Services\Analytics\Contracts;

/**
 * Interface for analytics storage drivers
 */
interface AnalyticsDriverInterface
{
    /**
     * Track AI request
     */
    public function trackRequest(array $data): void;

    /**
     * Track streaming session
     */
    public function trackStreaming(array $data): void;

    /**
     * Track interactive action
     */
    public function trackAction(array $data): void;

    /**
     * Get usage analytics
     */
    public function getUsageAnalytics(array $filters = []): array;

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(array $filters = []): array;

    /**
     * Get cost analytics
     */
    public function getCostAnalytics(array $filters = []): array;

    /**
     * Get top engines by usage
     */
    public function getTopEngines(array $filters = []): array;

    /**
     * Get top models by usage
     */
    public function getTopModels(array $filters = []): array;

    /**
     * Get error rates
     */
    public function getErrorRates(array $filters = []): array;

    /**
     * Get user activity
     */
    public function getUserActivity(string $userId, array $filters = []): array;
}
