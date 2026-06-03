<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;

class AnalyticsManager
{
    public function __construct(
        private Application $app
    ) {}

    /**
     * Record AI request and response
     */
    public function recordRequest(AIRequest $request, AIResponse $response): void
    {
        if (!config('ai-engine.analytics.enabled', true)) {
            return;
        }

        $data = [
            'user_id' => $request->userId,
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'content_type' => $request->getContentType(),
            'prompt_length' => strlen($request->prompt),
            'tokens_used' => $response->tokensUsed,
            'credits_used' => $response->creditsUsed,
            'latency_ms' => $response->latency,
            'cached' => $response->cached,
            'success' => $response->success,
            'request_id' => $response->requestId,
            'created_at' => now(),
        ];

        $this->storeAnalytics('ai_requests', $data);
    }

    /**
     * Record cache hit
     */
    public function recordCacheHit(AIRequest $request): void
    {
        if (!config('ai-engine.analytics.enabled', true)) {
            return;
        }

        $data = [
            'user_id' => $request->userId,
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'content_type' => $request->getContentType(),
            'created_at' => now(),
        ];

        $this->storeAnalytics('ai_cache_hits', $data);
    }

    /**
     * Record error
     */
    public function recordError(AIRequest $request, \Exception $exception): void
    {
        if (!config('ai-engine.analytics.enabled', true)) {
            return;
        }

        $data = [
            'user_id' => $request->userId,
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'content_type' => $request->getContentType(),
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'created_at' => now(),
        ];

        $this->storeAnalytics('ai_errors', $data);
    }

    /**
     * Track a request (simple array-based tracking)
     */
    public function trackRequest(array $data): void
    {
        if (!config('ai-engine.analytics.enabled', true)) {
            return;
        }

        $this->storeAnalytics('ai_request_tracking', array_merge($data, [
            'created_at' => now(),
        ]));
    }

    /**
     * Track an action
     */
    public function trackAction(array $data): void
    {
        if (!config('ai-engine.analytics.enabled', true)) {
            return;
        }

        $this->storeAnalytics('ai_action_tracking', array_merge($data, [
            'created_at' => now(),
        ]));
    }

    /**
     * Track streaming
     */
    public function trackStreaming(array $data): void
    {
        if (!config('ai-engine.analytics.enabled', true)) {
            return;
        }

        $this->storeAnalytics('ai_streaming_tracking', array_merge($data, [
            'created_at' => now(),
        ]));
    }

    /**
     * Get dashboard data
     */
    public function getDashboardData(array $filters = []): array
    {
        return [
            'usage' => $this->getUsageStats($filters),
            'performance' => $this->getPerformanceMetrics($filters),
            'costs' => $this->getCostAnalytics($filters),
        ];
    }

    /**
     * Get usage statistics
     */
    public function getUsageStats(array $filters = []): array
    {
        $query = $this->applyFilters(DB::table('ai_requests'), $filters);

        // Each aggregate runs on a fresh clone so that adding a constraint
        // (e.g. the success filter below) never leaks into later aggregates.
        $totalRequests = (clone $query)->count();
        $successCount = (clone $query)->where('success', true)->count();

        return [
            'total_requests' => $totalRequests,
            'total_credits_used' => (clone $query)->sum('credits_used'),
            'total_tokens_used' => (clone $query)->sum('tokens_used'),
            'average_latency' => (clone $query)->avg('latency_ms'),
            'success_rate' => $totalRequests > 0 ? (float) (($successCount / $totalRequests) * 100) : 0.0,
            'cache_hit_rate' => $this->getCacheHitRate($filters),
            'most_used_engine' => $this->getMostUsedEngine($filters),
            'most_used_model' => $this->getMostUsedModel($filters),
            'requests_by_content_type' => $this->getRequestsByContentType($filters),
        ];
    }

    /**
     * Get cost analytics
     */
    public function getCostAnalytics(array $filters = []): array
    {
        $query = $this->applyFilters(DB::table('ai_requests'), $filters);

        // Grouped selects mutate the builder, so each runs on its own clone.
        return [
            'total_credits_spent' => (clone $query)->sum('credits_used'),
            'credits_by_engine' => (clone $query)->groupBy('engine')
                ->selectRaw('engine, SUM(credits_used) as total_credits')
                ->pluck('total_credits', 'engine'),
            'credits_by_model' => (clone $query)->groupBy('model')
                ->selectRaw('model, SUM(credits_used) as total_credits')
                ->pluck('total_credits', 'model'),
            'daily_spend' => (clone $query)->groupBy(DB::raw('DATE(created_at)'))
                ->selectRaw('DATE(created_at) as date, SUM(credits_used) as total_credits')
                ->pluck('total_credits', 'date'),
        ];
    }

    /**
     * Get system-wide overview totals across all users.
     */
    public function getSystemOverview(array $filters = []): array
    {
        $query = $this->applyFilters(DB::table('ai_requests'), $filters);

        $totalRequests = (clone $query)->count();
        $errorCount = (clone $query)->where('success', false)->count();

        return [
            'total_users' => (clone $query)->distinct()->count('user_id'),
            'active_users' => (clone $query)
                ->where('created_at', '>=', now()->subDays(7))
                ->distinct()->count('user_id'),
            'total_requests' => $totalRequests,
            'total_credits_used' => (clone $query)->sum('credits_used'),
            'avg_response_time' => (clone $query)->avg('latency_ms'),
            'error_rate' => $totalRequests > 0 ? (float) (($errorCount / $totalRequests) * 100) : 0.0,
        ];
    }

    /**
     * Get a per-engine usage breakdown.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEngineBreakdown(array $filters = []): array
    {
        $query = $this->applyFilters(DB::table('ai_requests'), $filters);

        $rows = (clone $query)->groupBy('engine')
            ->selectRaw(
                'engine, COUNT(*) as requests, SUM(credits_used) as credits_used, '
                . 'AVG(latency_ms) as avg_response_time, '
                . 'SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count'
            )
            ->orderByDesc('requests')
            ->get();

        return $rows->map(function ($row) {
            $requests = (int) $row->requests;

            return [
                'engine' => $row->engine,
                'requests' => $requests,
                'credits_used' => (float) $row->credits_used,
                'avg_response_time' => $row->avg_response_time !== null
                    ? round((float) $row->avg_response_time, 2)
                    : null,
                'success_rate' => $requests > 0
                    ? round(((int) $row->success_count / $requests) * 100, 2)
                    : 0,
            ];
        })->all();
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(array $filters = []): array
    {
        $query = $this->applyFilters(DB::table('ai_requests'), $filters);

        return [
            'average_latency_by_engine' => (clone $query)->groupBy('engine')
                ->selectRaw('engine, AVG(latency_ms) as avg_latency')
                ->pluck('avg_latency', 'engine'),
            'p95_latency' => (clone $query)->selectRaw('PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY latency_ms) as p95')
                ->value('p95'),
            'error_rate_by_engine' => $this->getErrorRateByEngine($filters),
            'throughput_by_hour' => (clone $query)->groupBy(DB::raw('HOUR(created_at)'))
                ->selectRaw('HOUR(created_at) as hour, COUNT(*) as requests')
                ->pluck('requests', 'hour'),
        ];
    }

    /**
     * Apply the shared usage filters to a query builder.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Query\Builder
     */
    private function applyFilters($query, array $filters)
    {
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['engine'])) {
            $query->where('engine', $filters['engine']);
        }

        if (isset($filters['model'])) {
            $query->where('model', $filters['model']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query;
    }

    /**
     * Store analytics data
     */
    private function storeAnalytics(string $table, array $data): void
    {
        $driver = config('ai-engine.analytics.driver', 'database');

        switch ($driver) {
            case 'database':
                DB::table($table)->insert($data);
                break;
            case 'log':
                logger()->info("AI Analytics: {$table}", $data);
                break;
            // Add other drivers as needed (Redis, InfluxDB, etc.)
        }
    }

    /**
     * Get cache hit rate
     */
    private function getCacheHitRate(array $filters): float
    {
        $totalRequests = DB::table('ai_requests');
        $cacheHits = DB::table('ai_requests')->where('cached', true);

        // Apply filters to both queries
        if (isset($filters['user_id'])) {
            $totalRequests->where('user_id', $filters['user_id']);
            $cacheHits->where('user_id', $filters['user_id']);
        }

        if (isset($filters['engine'])) {
            $totalRequests->where('engine', $filters['engine']);
            $cacheHits->where('engine', $filters['engine']);
        }

        $total = $totalRequests->count();
        $hits = $cacheHits->count();

        return $total > 0 ? ($hits / $total) * 100 : 0;
    }

    /**
     * Get most used engine
     */
    private function getMostUsedEngine(array $filters): ?string
    {
        $query = DB::table('ai_requests');

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->groupBy('engine')
            ->selectRaw('engine, COUNT(*) as count')
            ->orderByDesc('count')
            ->value('engine');
    }

    /**
     * Get most used model
     */
    private function getMostUsedModel(array $filters): ?string
    {
        $query = DB::table('ai_requests');

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->groupBy('model')
            ->selectRaw('model, COUNT(*) as count')
            ->orderByDesc('count')
            ->value('model');
    }

    /**
     * Get requests by content type
     */
    private function getRequestsByContentType(array $filters): array
    {
        $query = DB::table('ai_requests');

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->groupBy('content_type')
            ->selectRaw('content_type, COUNT(*) as count')
            ->pluck('count', 'content_type')
            ->toArray();
    }

    /**
     * Get error rate by engine
     */
    private function getErrorRateByEngine(array $filters): array
    {
        $totalQuery = DB::table('ai_requests');
        $errorQuery = DB::table('ai_requests')->where('success', false);

        if (isset($filters['from_date'])) {
            $totalQuery->where('created_at', '>=', $filters['from_date']);
            $errorQuery->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $totalQuery->where('created_at', '<=', $filters['to_date']);
            $errorQuery->where('created_at', '<=', $filters['to_date']);
        }

        $totalByEngine = $totalQuery->groupBy('engine')
            ->selectRaw('engine, COUNT(*) as total')
            ->pluck('total', 'engine');

        $errorsByEngine = $errorQuery->groupBy('engine')
            ->selectRaw('engine, COUNT(*) as errors')
            ->pluck('errors', 'engine');

        $errorRates = [];
        foreach ($totalByEngine as $engine => $total) {
            $errors = $errorsByEngine[$engine] ?? 0;
            $errorRates[$engine] = $total > 0 ? ($errors / $total) * 100 : 0;
        }

        return $errorRates;
    }
}
