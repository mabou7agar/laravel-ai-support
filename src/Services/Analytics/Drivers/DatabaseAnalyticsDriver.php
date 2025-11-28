<?php

namespace LaravelAIEngine\Services\Analytics\Drivers;

use LaravelAIEngine\Services\Analytics\Contracts\AnalyticsDriverInterface;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Database analytics driver for persistent analytics storage
 */
class DatabaseAnalyticsDriver implements AnalyticsDriverInterface
{
    protected string $connection;

    public function __construct()
    {
        $this->connection = config('ai-engine.analytics.database.connection', config('database.default'));
    }

    /**
     * Track AI request
     */
    public function trackRequest(array $data): void
    {
        DB::connection($this->connection)->table('ai_analytics_requests')->updateOrInsert(
            ['request_id' => $data['request_id']],
            [
                'user_id' => $data['user_id'],
                'engine' => $data['engine'],
                'model' => $data['model'],
                'input_tokens' => $data['input_tokens'],
                'output_tokens' => $data['output_tokens'],
                'total_tokens' => $data['total_tokens'],
                'response_time' => $data['response_time'],
                'cost' => $data['cost'],
                'success' => $data['success'],
                'error_message' => $data['error_message'],
                'metadata' => json_encode($data['metadata']),
                'created_at' => Carbon::parse($data['timestamp'])->toDateTimeString(),
            ]
        );
    }

    /**
     * Track streaming session
     */
    public function trackStreaming(array $data): void
    {
        DB::connection($this->connection)->table('ai_analytics_streaming')->insert([
            'session_id' => $data['session_id'],
            'user_id' => $data['user_id'],
            'engine' => $data['engine'],
            'chunks_sent' => $data['chunks_sent'],
            'total_content_length' => $data['total_content_length'],
            'duration' => $data['duration'],
            'connection_type' => $data['connection_type'],
            'success' => $data['success'],
            'metadata' => json_encode($data['metadata']),
            'created_at' => Carbon::parse($data['timestamp'])->toDateTimeString(),
        ]);
    }

    /**
     * Track interactive action
     */
    public function trackAction(array $data): void
    {
        DB::connection($this->connection)->table('ai_analytics_actions')->insert([
            'action_id' => $data['action_id'],
            'user_id' => $data['user_id'],
            'action_type' => $data['action_type'],
            'action_label' => $data['action_label'],
            'execution_time' => $data['execution_time'],
            'success' => $data['success'],
            'error_message' => $data['error_message'],
            'metadata' => json_encode($data['metadata']),
            'created_at' => Carbon::parse($data['timestamp'])->toDateTimeString(),
        ]);
    }

    /**
     * Get usage analytics
     */
    public function getUsageAnalytics(array $filters = []): array
    {
        $query = DB::connection($this->connection)->table('ai_analytics_requests');
        $this->applyFilters($query, $filters);

        $totalRequests = $query->count();
        $totalTokens = $query->sum('total_tokens');
        $activeUsers = $query->distinct('user_id')->count('user_id');

        // Get trends
        $trendsQuery = clone $query;
        $trends = $trendsQuery
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as requests'),
                DB::raw('SUM(total_tokens) as tokens')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        return [
            'total_requests' => $totalRequests,
            'total_tokens' => $totalTokens,
            'active_users' => $activeUsers,
            'trends' => $trends,
        ];
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(array $filters = []): array
    {
        $query = DB::connection($this->connection)->table('ai_analytics_requests');
        $this->applyFilters($query, $filters);

        $totalRequests = $query->count();
        $successfulRequests = $query->where('success', true)->count();
        $avgResponseTime = $query->avg('response_time');

        $successRate = $totalRequests > 0 ? $successfulRequests / $totalRequests : 0;

        // Get performance trends
        $trendsQuery = clone $query;
        $trends = $trendsQuery
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('AVG(response_time) as avg_response_time'),
                DB::raw('COUNT(CASE WHEN success = 1 THEN 1 END) / COUNT(*) as success_rate')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        return [
            'avg_response_time' => $avgResponseTime,
            'success_rate' => $successRate,
            'total_requests' => $totalRequests,
            'successful_requests' => $successfulRequests,
            'trends' => $trends,
        ];
    }

    /**
     * Get cost analytics
     */
    public function getCostAnalytics(array $filters = []): array
    {
        $query = DB::connection($this->connection)->table('ai_analytics_requests');
        $this->applyFilters($query, $filters);

        $totalCost = $query->sum('cost');
        $avgCostPerRequest = $query->avg('cost');

        // Get cost trends
        $trendsQuery = clone $query;
        $trends = $trendsQuery
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(cost) as total_cost'),
                DB::raw('AVG(cost) as avg_cost')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        // Get cost by engine
        $costByEngine = $query
            ->select('engine', DB::raw('SUM(cost) as total_cost'))
            ->groupBy('engine')
            ->orderBy('total_cost', 'desc')
            ->get()
            ->toArray();

        return [
            'total_cost' => $totalCost,
            'avg_cost_per_request' => $avgCostPerRequest,
            'trends' => $trends,
            'by_engine' => $costByEngine,
        ];
    }

    /**
     * Get top engines by usage
     */
    public function getTopEngines(array $filters = []): array
    {
        $query = DB::connection($this->connection)->table('ai_analytics_requests');
        $this->applyFilters($query, $filters);

        return $query
            ->select('engine', DB::raw('COUNT(*) as requests'), DB::raw('SUM(total_tokens) as tokens'))
            ->groupBy('engine')
            ->orderBy('requests', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Get top models by usage
     */
    public function getTopModels(array $filters = []): array
    {
        $query = DB::connection($this->connection)->table('ai_analytics_requests');
        $this->applyFilters($query, $filters);

        return $query
            ->select('model', 'engine', DB::raw('COUNT(*) as requests'))
            ->groupBy('model', 'engine')
            ->orderBy('requests', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Get error rates
     */
    public function getErrorRates(array $filters = []): array
    {
        $query = DB::connection($this->connection)->table('ai_analytics_requests');
        $this->applyFilters($query, $filters);

        $errorsByEngine = $query
            ->select(
                'engine',
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('COUNT(CASE WHEN success = 0 THEN 1 END) as errors'),
                DB::raw('COUNT(CASE WHEN success = 0 THEN 1 END) / COUNT(*) as error_rate')
            )
            ->groupBy('engine')
            ->get()
            ->toArray();

        return $errorsByEngine;
    }

    /**
     * Get user activity
     */
    public function getUserActivity(string $userId, array $filters = []): array
    {
        $filters['user_id'] = $userId;
        
        $query = DB::connection($this->connection)->table('ai_analytics_requests');
        $this->applyFilters($query, $filters);

        $totalRequests = $query->count();
        $totalTokens = $query->sum('total_tokens');
        $totalCost = $query->sum('cost');

        // Get daily activity
        $dailyActivity = $query
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as requests'),
                DB::raw('SUM(total_tokens) as tokens'),
                DB::raw('SUM(cost) as cost')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        return [
            'user_id' => $userId,
            'total_requests' => $totalRequests,
            'total_tokens' => $totalTokens,
            'total_cost' => $totalCost,
            'daily_activity' => $dailyActivity,
        ];
    }

    /**
     * Apply filters to query
     */
    protected function applyFilters($query, array $filters): void
    {
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['engine'])) {
            $query->where('engine', $filters['engine']);
        }

        if (isset($filters['time_range'])) {
            $timeRange = $filters['time_range'];
            $startDate = match($timeRange) {
                '1h' => Carbon::now()->subHour(),
                '24h' => Carbon::now()->subDay(),
                '7d' => Carbon::now()->subWeek(),
                '30d' => Carbon::now()->subMonth(),
                '90d' => Carbon::now()->subMonths(3),
                default => Carbon::now()->subDay(),
            };
            
            $query->where('created_at', '>=', $startDate);
        }

        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }
    }
}
