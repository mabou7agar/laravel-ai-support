<?php

namespace LaravelAIEngine\Services\Analytics;

use LaravelAIEngine\Services\Analytics\Contracts\AnalyticsDriverInterface;
use LaravelAIEngine\Services\Analytics\Drivers\DatabaseAnalyticsDriver;
use LaravelAIEngine\Services\Analytics\Drivers\RedisAnalyticsDriver;
use LaravelAIEngine\Services\Analytics\Metrics\MetricsCollector;
use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Events\AIRequestCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Analytics Manager for comprehensive AI usage monitoring
 */
class AnalyticsManager
{
    protected array $drivers = [];
    protected MetricsCollector $metricsCollector;
    protected string $defaultDriver;

    public function __construct(MetricsCollector $metricsCollector)
    {
        $this->metricsCollector = $metricsCollector;
        $this->defaultDriver = config('ai-engine.analytics.default_driver', 'database');
        $this->registerDefaultDrivers();
        $this->registerEventListeners();
    }

    /**
     * Track AI request
     */
    public function trackRequest(array $data): void
    {
        $requestData = array_merge([
            'timestamp' => Carbon::now()->toISOString(),
            'request_id' => $data['request_id'] ?? uniqid('req_'),
            'user_id' => $data['user_id'] ?? null,
            'engine' => $data['engine'] ?? null,
            'model' => $data['model'] ?? null,
            'input_tokens' => $data['input_tokens'] ?? 0,
            'output_tokens' => $data['output_tokens'] ?? 0,
            'total_tokens' => $data['total_tokens'] ?? 0,
            'response_time' => $data['response_time'] ?? 0,
            'cost' => $data['cost'] ?? 0,
            'success' => $data['success'] ?? true,
            'error_message' => $data['error_message'] ?? null,
            'metadata' => $data['metadata'] ?? []
        ], $data);

        // Store in analytics driver
        $this->driver()->trackRequest($requestData);

        // Update real-time metrics
        $this->metricsCollector->recordRequest($requestData);

        Log::debug('AI request tracked', [
            'request_id' => $requestData['request_id'],
            'engine' => $requestData['engine'],
            'tokens' => $requestData['total_tokens']
        ]);
    }

    /**
     * Track streaming session
     */
    public function trackStreaming(array $data): void
    {
        $streamingData = array_merge([
            'timestamp' => Carbon::now()->toISOString(),
            'session_id' => $data['session_id'] ?? uniqid('stream_'),
            'user_id' => $data['user_id'] ?? null,
            'engine' => $data['engine'] ?? null,
            'chunks_sent' => $data['chunks_sent'] ?? 0,
            'total_content_length' => $data['total_content_length'] ?? 0,
            'duration' => $data['duration'] ?? 0,
            'connection_type' => $data['connection_type'] ?? 'websocket',
            'success' => $data['success'] ?? true,
            'metadata' => $data['metadata'] ?? []
        ], $data);

        $this->driver()->trackStreaming($streamingData);
        $this->metricsCollector->recordStreaming($streamingData);
    }

    /**
     * Track interactive action
     */
    public function trackAction(array $data): void
    {
        $actionData = array_merge([
            'timestamp' => Carbon::now()->toISOString(),
            'action_id' => $data['action_id'] ?? uniqid('action_'),
            'user_id' => $data['user_id'] ?? null,
            'action_type' => $data['action_type'] ?? null,
            'action_label' => $data['action_label'] ?? null,
            'execution_time' => $data['execution_time'] ?? 0,
            'success' => $data['success'] ?? true,
            'error_message' => $data['error_message'] ?? null,
            'metadata' => $data['metadata'] ?? []
        ], $data);

        $this->driver()->trackAction($actionData);
        $this->metricsCollector->recordAction($actionData);
    }

    /**
     * Get usage analytics
     */
    public function getUsageAnalytics(array $filters = []): array
    {
        return $this->driver()->getUsageAnalytics($filters);
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(array $filters = []): array
    {
        return $this->driver()->getPerformanceMetrics($filters);
    }

    /**
     * Get cost analytics
     */
    public function getCostAnalytics(array $filters = []): array
    {
        return $this->driver()->getCostAnalytics($filters);
    }

    /**
     * Get real-time metrics
     */
    public function getRealTimeMetrics(): array
    {
        return $this->metricsCollector->getMetrics();
    }

    /**
     * Get dashboard data
     */
    public function getDashboardData(array $filters = []): array
    {
        $timeRange = $filters['time_range'] ?? '24h';
        $userId = $filters['user_id'] ?? null;

        // Get basic usage stats
        $usage = $this->getUsageAnalytics($filters);
        $performance = $this->getPerformanceMetrics($filters);
        $costs = $this->getCostAnalytics($filters);
        $realTime = $this->getRealTimeMetrics();

        // Get top engines and models
        $topEngines = $this->driver()->getTopEngines($filters);
        $topModels = $this->driver()->getTopModels($filters);

        // Get error rates
        $errorRates = $this->driver()->getErrorRates($filters);

        // Get user activity (if user_id provided)
        $userActivity = $userId ? $this->driver()->getUserActivity($userId, $filters) : null;

        return [
            'overview' => [
                'total_requests' => $usage['total_requests'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'total_cost' => $costs['total_cost'] ?? 0,
                'avg_response_time' => $performance['avg_response_time'] ?? 0,
                'success_rate' => $performance['success_rate'] ?? 0,
                'active_users' => $usage['active_users'] ?? 0,
            ],
            'usage_trends' => $usage['trends'] ?? [],
            'performance_trends' => $performance['trends'] ?? [],
            'cost_trends' => $costs['trends'] ?? [],
            'top_engines' => $topEngines,
            'top_models' => $topModels,
            'error_rates' => $errorRates,
            'real_time' => $realTime,
            'user_activity' => $userActivity,
            'time_range' => $timeRange,
            'generated_at' => Carbon::now()->toISOString(),
        ];
    }

    /**
     * Get system health metrics
     */
    public function getSystemHealth(): array
    {
        $health = [
            'status' => 'healthy',
            'checks' => [],
            'metrics' => $this->getRealTimeMetrics(),
            'timestamp' => Carbon::now()->toISOString()
        ];

        // Check error rates
        $errorRate = $this->metricsCollector->getErrorRate();
        $health['checks']['error_rate'] = [
            'status' => $errorRate < 0.05 ? 'healthy' : ($errorRate < 0.1 ? 'warning' : 'critical'),
            'value' => $errorRate,
            'threshold' => 0.05
        ];

        // Check response times
        $avgResponseTime = $this->metricsCollector->getAverageResponseTime();
        $health['checks']['response_time'] = [
            'status' => $avgResponseTime < 2000 ? 'healthy' : ($avgResponseTime < 5000 ? 'warning' : 'critical'),
            'value' => $avgResponseTime,
            'threshold' => 2000
        ];

        // Check request volume
        $requestRate = $this->metricsCollector->getRequestRate();
        $health['checks']['request_rate'] = [
            'status' => 'healthy', // Always healthy for now
            'value' => $requestRate,
            'threshold' => null
        ];

        // Determine overall status
        $statuses = array_column($health['checks'], 'status');
        if (in_array('critical', $statuses)) {
            $health['status'] = 'critical';
        } elseif (in_array('warning', $statuses)) {
            $health['status'] = 'warning';
        }

        return $health;
    }

    /**
     * Generate analytics report
     */
    public function generateReport(array $options = []): array
    {
        $format = $options['format'] ?? 'array';
        $timeRange = $options['time_range'] ?? '7d';
        $includeCharts = $options['include_charts'] ?? false;

        $filters = ['time_range' => $timeRange];
        $dashboardData = $this->getDashboardData($filters);

        $report = [
            'title' => 'AI Engine Analytics Report',
            'time_range' => $timeRange,
            'generated_at' => Carbon::now()->toISOString(),
            'summary' => $dashboardData['overview'],
            'detailed_metrics' => [
                'usage' => $this->getUsageAnalytics($filters),
                'performance' => $this->getPerformanceMetrics($filters),
                'costs' => $this->getCostAnalytics($filters),
            ],
            'insights' => $this->generateInsights($dashboardData),
        ];

        if ($includeCharts) {
            $report['charts'] = $this->generateChartData($dashboardData);
        }

        return $report;
    }

    /**
     * Register analytics driver
     */
    public function extend(string $name, AnalyticsDriverInterface $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    /**
     * Get analytics driver
     */
    public function driver(?string $name = null): AnalyticsDriverInterface
    {
        $name = $name ?? $this->defaultDriver;

        if (!isset($this->drivers[$name])) {
            throw new \InvalidArgumentException("Analytics driver [{$name}] not found.");
        }

        return $this->drivers[$name];
    }

    /**
     * Register default drivers
     */
    protected function registerDefaultDrivers(): void
    {
        $this->drivers['database'] = app(DatabaseAnalyticsDriver::class);
        $this->drivers['redis'] = app(RedisAnalyticsDriver::class);
    }

    /**
     * Register event listeners for automatic tracking
     */
    protected function registerEventListeners(): void
    {
        Event::listen(AIRequestStarted::class, function ($event) {
            $this->trackRequest([
                'request_id' => $event->requestId,
                'user_id' => $event->userId,
                'engine' => $event->engine,
                'model' => $event->model,
                'status' => 'started',
                'metadata' => $event->metadata ?? []
            ]);
        });

        Event::listen(AIRequestCompleted::class, function ($event) {
            $this->trackRequest([
                'request_id' => $event->requestId,
                'user_id' => $event->userId,
                'engine' => $event->engine,
                'model' => $event->model,
                'input_tokens' => $event->inputTokens,
                'output_tokens' => $event->outputTokens,
                'total_tokens' => $event->totalTokens,
                'response_time' => $event->responseTime,
                'cost' => $event->cost,
                'success' => $event->success,
                'error_message' => $event->errorMessage,
                'status' => 'completed',
                'metadata' => $event->metadata ?? []
            ]);
        });
    }

    /**
     * Generate insights from analytics data
     */
    protected function generateInsights(array $data): array
    {
        $insights = [];

        // Usage insights
        if (isset($data['overview']['total_requests'])) {
            $totalRequests = $data['overview']['total_requests'];
            if ($totalRequests > 1000) {
                $insights[] = [
                    'type' => 'usage',
                    'level' => 'info',
                    'message' => "High usage detected with {$totalRequests} total requests"
                ];
            }
        }

        // Performance insights
        if (isset($data['overview']['avg_response_time'])) {
            $avgResponseTime = $data['overview']['avg_response_time'];
            if ($avgResponseTime > 5000) {
                $insights[] = [
                    'type' => 'performance',
                    'level' => 'warning',
                    'message' => "Slow response times detected (avg: {$avgResponseTime}ms)"
                ];
            }
        }

        // Cost insights
        if (isset($data['overview']['total_cost'])) {
            $totalCost = $data['overview']['total_cost'];
            if ($totalCost > 100) {
                $insights[] = [
                    'type' => 'cost',
                    'level' => 'info',
                    'message' => "Significant AI costs incurred: $" . number_format($totalCost, 2)
                ];
            }
        }

        return $insights;
    }

    /**
     * Generate chart data for visualization
     */
    protected function generateChartData(array $data): array
    {
        return [
            'usage_over_time' => $data['usage_trends'] ?? [],
            'response_time_trends' => $data['performance_trends'] ?? [],
            'cost_breakdown' => $data['cost_trends'] ?? [],
            'engine_distribution' => $data['top_engines'] ?? [],
            'model_usage' => $data['top_models'] ?? [],
        ];
    }
}
