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
     * Get usage statistics
     */
    public function getUsageStats(array $filters = []): array
    {
        $query = DB::table('ai_requests');

        // Apply filters
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

        return [
            'total_requests' => $query->count(),
            'total_credits_used' => $query->sum('credits_used'),
            'total_tokens_used' => $query->sum('tokens_used'),
            'average_latency' => $query->avg('latency_ms'),
            'success_rate' => $query->where('success', true)->count() / max(1, $query->count()) * 100,
            'cache_hit_rate' => $this->getCacheHitRate($filters),
            'most_used_engine' => $this->getMostUsedEngine($filters),
            'most_used_model' => $this->getMostUsedModel($filters),
            'requests_by_content_type' => $this->getRequestsByContentType($filters),
        ];
    }

    /**
     * Get cost analysis
     */
    public function getCostAnalysis(array $filters = []): array
    {
        $query = DB::table('ai_requests');

        // Apply same filters as usage stats
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['engine'])) {
            $query->where('engine', $filters['engine']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return [
            'total_credits_spent' => $query->sum('credits_used'),
            'credits_by_engine' => $query->groupBy('engine')
                ->selectRaw('engine, SUM(credits_used) as total_credits')
                ->pluck('total_credits', 'engine'),
            'credits_by_model' => $query->groupBy('model')
                ->selectRaw('model, SUM(credits_used) as total_credits')
                ->pluck('total_credits', 'model'),
            'daily_spend' => $query->groupBy(DB::raw('DATE(created_at)'))
                ->selectRaw('DATE(created_at) as date, SUM(credits_used) as total_credits')
                ->pluck('total_credits', 'date'),
        ];
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(array $filters = []): array
    {
        $query = DB::table('ai_requests');

        // Apply filters
        if (isset($filters['engine'])) {
            $query->where('engine', $filters['engine']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return [
            'average_latency_by_engine' => $query->groupBy('engine')
                ->selectRaw('engine, AVG(latency_ms) as avg_latency')
                ->pluck('avg_latency', 'engine'),
            'p95_latency' => $query->selectRaw('PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY latency_ms) as p95')
                ->value('p95'),
            'error_rate_by_engine' => $this->getErrorRateByEngine($filters),
            'throughput_by_hour' => $query->groupBy(DB::raw('HOUR(created_at)'))
                ->selectRaw('HOUR(created_at) as hour, COUNT(*) as requests')
                ->pluck('requests', 'hour'),
        ];
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
