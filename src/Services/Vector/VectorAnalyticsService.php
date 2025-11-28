<?php

namespace LaravelAIEngine\Services\Vector;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class VectorAnalyticsService
{
    /**
     * Track vector search
     */
    public function trackSearch(
        ?string $userId,
        string $modelType,
        string $query,
        int $resultsCount,
        int $limit,
        float $threshold,
        array $filters,
        float $executionTime,
        int $tokensUsed,
        string $status = 'success',
        ?string $errorMessage = null
    ): void {
        try {
            DB::table('vector_search_logs')->insert([
                'user_id' => $userId,
                'model_type' => $modelType,
                'query' => $query,
                'results_count' => $resultsCount,
                'limit' => $limit,
                'threshold' => $threshold,
                'filters' => json_encode($filters),
                'execution_time' => $executionTime,
                'tokens_used' => $tokensUsed,
                'status' => $status,
                'error_message' => $errorMessage,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Don't fail the search if analytics fails
            \Log::warning('Failed to track vector search', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get search analytics for user
     */
    public function getUserAnalytics(string $userId, int $days = 30): array
    {
        $since = Carbon::now()->subDays($days);

        $stats = DB::table('vector_search_logs')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total_searches,
                SUM(results_count) as total_results,
                AVG(results_count) as avg_results_per_search,
                AVG(execution_time) as avg_execution_time,
                SUM(tokens_used) as total_tokens_used,
                COUNT(CASE WHEN status = "success" THEN 1 END) as successful_searches,
                COUNT(CASE WHEN status = "failed" THEN 1 END) as failed_searches
            ')
            ->first();

        // Get popular queries
        $popularQueries = DB::table('vector_search_logs')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->select('query', DB::raw('COUNT(*) as count'))
            ->groupBy('query')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // Get search trends
        $trends = DB::table('vector_search_logs')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as searches')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'summary' => [
                'total_searches' => $stats->total_searches ?? 0,
                'total_results' => $stats->total_results ?? 0,
                'avg_results_per_search' => round($stats->avg_results_per_search ?? 0, 2),
                'avg_execution_time' => round($stats->avg_execution_time ?? 0, 2),
                'total_tokens_used' => $stats->total_tokens_used ?? 0,
                'successful_searches' => $stats->successful_searches ?? 0,
                'failed_searches' => $stats->failed_searches ?? 0,
                'success_rate' => $stats->total_searches > 0 
                    ? round(($stats->successful_searches / $stats->total_searches) * 100, 2) 
                    : 0,
            ],
            'popular_queries' => $popularQueries,
            'trends' => $trends,
        ];
    }

    /**
     * Get global analytics
     */
    public function getGlobalAnalytics(int $days = 30): array
    {
        $cacheKey = "vector_analytics_global_{$days}";
        
        return Cache::remember($cacheKey, 300, function () use ($days) {
            $since = Carbon::now()->subDays($days);

            $stats = DB::table('vector_search_logs')
                ->where('created_at', '>=', $since)
                ->selectRaw('
                    COUNT(*) as total_searches,
                    COUNT(DISTINCT user_id) as unique_users,
                    SUM(results_count) as total_results,
                    AVG(results_count) as avg_results_per_search,
                    AVG(execution_time) as avg_execution_time,
                    SUM(tokens_used) as total_tokens_used,
                    COUNT(CASE WHEN status = "success" THEN 1 END) as successful_searches
                ')
                ->first();

            // Get most searched models
            $popularModels = DB::table('vector_search_logs')
                ->where('created_at', '>=', $since)
                ->select('model_type', DB::raw('COUNT(*) as count'))
                ->groupBy('model_type')
                ->orderByDesc('count')
                ->limit(10)
                ->get();

            // Get hourly distribution (SQLite compatible)
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $hourlyDistribution = DB::table('vector_search_logs')
                    ->where('created_at', '>=', $since)
                    ->selectRaw('strftime("%H", created_at) as hour, COUNT(*) as searches')
                    ->groupBy('hour')
                    ->orderBy('hour')
                    ->get();
            } else {
                $hourlyDistribution = DB::table('vector_search_logs')
                    ->where('created_at', '>=', $since)
                    ->selectRaw('HOUR(created_at) as hour, COUNT(*) as searches')
                    ->groupBy('hour')
                    ->orderBy('hour')
                    ->get();
            }

            return [
                'summary' => [
                    'total_searches' => $stats->total_searches ?? 0,
                    'unique_users' => $stats->unique_users ?? 0,
                    'total_results' => $stats->total_results ?? 0,
                    'avg_results_per_search' => round($stats->avg_results_per_search ?? 0, 2),
                    'avg_execution_time' => round($stats->avg_execution_time ?? 0, 2),
                    'total_tokens_used' => $stats->total_tokens_used ?? 0,
                    'success_rate' => $stats->total_searches > 0 
                        ? round(($stats->successful_searches / $stats->total_searches) * 100, 2) 
                        : 0,
                ],
                'popular_models' => $popularModels,
                'hourly_distribution' => $hourlyDistribution,
            ];
        });
    }

    /**
     * Get model-specific analytics
     */
    public function getModelAnalytics(string $modelType, int $days = 30): array
    {
        $since = Carbon::now()->subDays($days);

        $stats = DB::table('vector_search_logs')
            ->where('model_type', $modelType)
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total_searches,
                COUNT(DISTINCT user_id) as unique_users,
                SUM(results_count) as total_results,
                AVG(results_count) as avg_results_per_search,
                AVG(execution_time) as avg_execution_time,
                AVG(threshold) as avg_threshold
            ')
            ->first();

        // Get popular queries for this model
        $popularQueries = DB::table('vector_search_logs')
            ->where('model_type', $modelType)
            ->where('created_at', '>=', $since)
            ->select('query', DB::raw('COUNT(*) as count, AVG(results_count) as avg_results'))
            ->groupBy('query')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return [
            'model_type' => $modelType,
            'summary' => [
                'total_searches' => $stats->total_searches ?? 0,
                'unique_users' => $stats->unique_users ?? 0,
                'total_results' => $stats->total_results ?? 0,
                'avg_results_per_search' => round($stats->avg_results_per_search ?? 0, 2),
                'avg_execution_time' => round($stats->avg_execution_time ?? 0, 2),
                'avg_threshold' => round($stats->avg_threshold ?? 0, 2),
            ],
            'popular_queries' => $popularQueries,
        ];
    }

    /**
     * Get slow queries
     */
    public function getSlowQueries(int $limit = 20, float $minExecutionTime = 1000): array
    {
        return DB::table('vector_search_logs')
            ->where('execution_time', '>=', $minExecutionTime)
            ->orderByDesc('execution_time')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get failed searches
     */
    public function getFailedSearches(int $limit = 20): array
    {
        return DB::table('vector_search_logs')
            ->where('status', 'failed')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get search performance metrics
     */
    public function getPerformanceMetrics(int $days = 7): array
    {
        $since = Carbon::now()->subDays($days);
        $driver = DB::connection()->getDriverName();

        // Get basic metrics
        $metrics = DB::table('vector_search_logs')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                MIN(execution_time) as min_time,
                MAX(execution_time) as max_time,
                AVG(execution_time) as avg_time
            ')
            ->first();

        // Calculate percentiles manually for SQLite
        $allTimes = DB::table('vector_search_logs')
            ->where('created_at', '>=', $since)
            ->orderBy('execution_time')
            ->pluck('execution_time')
            ->toArray();

        $count = count($allTimes);
        $median = $count > 0 ? $allTimes[(int)($count * 0.5)] : 0;
        $p95 = $count > 0 ? $allTimes[(int)($count * 0.95)] : 0;
        $p99 = $count > 0 ? $allTimes[(int)($count * 0.99)] : 0;

        // Calculate standard deviation
        $stddev = 0;
        if ($count > 0 && $metrics->avg_time) {
            $variance = array_sum(array_map(function($time) use ($metrics) {
                return pow($time - $metrics->avg_time, 2);
            }, $allTimes)) / $count;
            $stddev = sqrt($variance);
        }

        return [
            'min_time' => round($metrics->min_time ?? 0, 2),
            'max_time' => round($metrics->max_time ?? 0, 2),
            'avg_time' => round($metrics->avg_time ?? 0, 2),
            'median_time' => round($median, 2),
            'p95_time' => round($p95, 2),
            'p99_time' => round($p99, 2),
            'stddev_time' => round($stddev, 2),
        ];
    }

    /**
     * Clean old analytics data
     */
    public function cleanOldData(int $daysToKeep = 90): int
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);

        return DB::table('vector_search_logs')
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }

    /**
     * Export analytics to CSV
     */
    public function exportToCsv(string $userId, int $days = 30): string
    {
        $since = Carbon::now()->subDays($days);

        $logs = DB::table('vector_search_logs')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get();

        $csv = "Date,Query,Model,Results,Execution Time,Tokens,Status\n";

        foreach ($logs as $log) {
            $csv .= sprintf(
                "%s,%s,%s,%d,%.2f,%d,%s\n",
                $log->created_at,
                str_replace(',', ';', $log->query),
                $log->model_type,
                $log->results_count,
                $log->execution_time,
                $log->tokens_used,
                $log->status
            );
        }

        return $csv;
    }
}
