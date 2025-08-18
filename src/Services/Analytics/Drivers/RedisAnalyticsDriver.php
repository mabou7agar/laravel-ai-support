<?php

namespace LaravelAIEngine\Services\Analytics\Drivers;

use LaravelAIEngine\Services\Analytics\Contracts\AnalyticsDriverInterface;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

/**
 * Redis analytics driver for high-performance analytics storage
 */
class RedisAnalyticsDriver implements AnalyticsDriverInterface
{
    protected string $connection;
    protected string $prefix;

    public function __construct()
    {
        $this->connection = config('ai-engine.analytics.redis.connection', 'default');
        $this->prefix = config('ai-engine.analytics.redis.prefix', 'ai_analytics:');
    }

    /**
     * Track AI request
     */
    public function trackRequest(array $data): void
    {
        $redis = Redis::connection($this->connection);
        $timestamp = Carbon::parse($data['timestamp']);
        $date = $timestamp->format('Y-m-d');
        $hour = $timestamp->format('Y-m-d:H');

        // Store request data
        $requestKey = $this->prefix . "requests:{$data['request_id']}";
        $redis->hMSet($requestKey, [
            'user_id' => $data['user_id'] ?? '',
            'engine' => $data['engine'],
            'model' => $data['model'],
            'tokens' => $data['total_tokens'],
            'cost' => $data['cost'],
            'response_time' => $data['response_time'],
            'success' => $data['success'] ? 1 : 0,
            'timestamp' => $data['timestamp'],
        ]);
        $redis->expire($requestKey, 86400 * 30); // 30 days

        // Update counters
        $redis->incr($this->prefix . 'counters:requests:total');
        $redis->incr($this->prefix . "counters:requests:date:{$date}");
        $redis->incr($this->prefix . "counters:requests:hour:{$hour}");
        $redis->incr($this->prefix . "counters:requests:engine:{$data['engine']}");

        // Update sums
        $redis->incrByFloat($this->prefix . 'sums:tokens:total', $data['total_tokens']);
        $redis->incrByFloat($this->prefix . "sums:tokens:engine:{$data['engine']}", $data['total_tokens']);
        $redis->incrByFloat($this->prefix . 'sums:cost:total', $data['cost']);
        $redis->incrByFloat($this->prefix . "sums:cost:engine:{$data['engine']}", $data['cost']);

        // Track response times (for averages)
        $redis->lPush($this->prefix . 'lists:response_times', $data['response_time']);
        $redis->lTrim($this->prefix . 'lists:response_times', 0, 999); // Keep last 1000

        // Track errors
        if (!$data['success']) {
            $redis->incr($this->prefix . 'counters:errors:total');
            $redis->incr($this->prefix . "counters:errors:engine:{$data['engine']}");
        }

        // Track unique users
        if ($data['user_id']) {
            $redis->sAdd($this->prefix . "sets:users:date:{$date}", $data['user_id']);
            $redis->expire($this->prefix . "sets:users:date:{$date}", 86400 * 7); // 7 days
        }
    }

    /**
     * Track streaming session
     */
    public function trackStreaming(array $data): void
    {
        $redis = Redis::connection($this->connection);
        $timestamp = Carbon::parse($data['timestamp']);
        $date = $timestamp->format('Y-m-d');

        // Store streaming data
        $sessionKey = $this->prefix . "streaming:{$data['session_id']}";
        $redis->hMSet($sessionKey, [
            'user_id' => $data['user_id'] ?? '',
            'engine' => $data['engine'],
            'chunks' => $data['chunks_sent'],
            'content_length' => $data['total_content_length'],
            'duration' => $data['duration'],
            'success' => $data['success'] ? 1 : 0,
            'timestamp' => $data['timestamp'],
        ]);
        $redis->expire($sessionKey, 86400 * 7); // 7 days

        // Update counters
        $redis->incr($this->prefix . 'counters:streaming:sessions');
        $redis->incr($this->prefix . "counters:streaming:date:{$date}");
        $redis->incrBy($this->prefix . 'counters:streaming:chunks', $data['chunks_sent']);
    }

    /**
     * Track interactive action
     */
    public function trackAction(array $data): void
    {
        $redis = Redis::connection($this->connection);
        $timestamp = Carbon::parse($data['timestamp']);
        $date = $timestamp->format('Y-m-d');

        // Store action data
        $actionKey = $this->prefix . "actions:{$data['action_id']}";
        $redis->hMSet($actionKey, [
            'user_id' => $data['user_id'] ?? '',
            'type' => $data['action_type'],
            'label' => $data['action_label'],
            'execution_time' => $data['execution_time'],
            'success' => $data['success'] ? 1 : 0,
            'timestamp' => $data['timestamp'],
        ]);
        $redis->expire($actionKey, 86400 * 7); // 7 days

        // Update counters
        $redis->incr($this->prefix . 'counters:actions:total');
        $redis->incr($this->prefix . "counters:actions:type:{$data['action_type']}");
        $redis->incr($this->prefix . "counters:actions:date:{$date}");
    }

    /**
     * Get usage analytics
     */
    public function getUsageAnalytics(array $filters = []): array
    {
        $redis = Redis::connection($this->connection);
        
        $totalRequests = $redis->get($this->prefix . 'counters:requests:total') ?: 0;
        $totalTokens = $redis->get($this->prefix . 'sums:tokens:total') ?: 0;

        // Get active users for time range
        $activeUsers = 0;
        if (isset($filters['time_range'])) {
            $days = $this->getTimeRangeDays($filters['time_range']);
            for ($i = 0; $i < $days; $i++) {
                $date = Carbon::now()->subDays($i)->format('Y-m-d');
                $userKey = $this->prefix . "sets:users:date:{$date}";
                if ($redis->exists($userKey)) {
                    $activeUsers += $redis->sCard($userKey);
                }
            }
        }

        // Get trends
        $trends = $this->getUsageTrends($filters);

        return [
            'total_requests' => (int) $totalRequests,
            'total_tokens' => (float) $totalTokens,
            'active_users' => $activeUsers,
            'trends' => $trends,
        ];
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(array $filters = []): array
    {
        $redis = Redis::connection($this->connection);
        
        $totalRequests = $redis->get($this->prefix . 'counters:requests:total') ?: 0;
        $totalErrors = $redis->get($this->prefix . 'counters:errors:total') ?: 0;
        
        $successRate = $totalRequests > 0 ? 1 - ($totalErrors / $totalRequests) : 0;

        // Calculate average response time
        $responseTimes = $redis->lRange($this->prefix . 'lists:response_times', 0, -1);
        $avgResponseTime = 0;
        if (!empty($responseTimes)) {
            $avgResponseTime = array_sum($responseTimes) / count($responseTimes);
        }

        return [
            'avg_response_time' => $avgResponseTime,
            'success_rate' => $successRate,
            'total_requests' => (int) $totalRequests,
            'successful_requests' => (int) ($totalRequests - $totalErrors),
            'trends' => [], // Would need more complex implementation for trends
        ];
    }

    /**
     * Get cost analytics
     */
    public function getCostAnalytics(array $filters = []): array
    {
        $redis = Redis::connection($this->connection);
        
        $totalCost = $redis->get($this->prefix . 'sums:cost:total') ?: 0;
        $totalRequests = $redis->get($this->prefix . 'counters:requests:total') ?: 0;
        
        $avgCostPerRequest = $totalRequests > 0 ? $totalCost / $totalRequests : 0;

        // Get cost by engine
        $engines = ['openai', 'anthropic', 'google', 'cohere'];
        $byEngine = [];
        foreach ($engines as $engine) {
            $cost = $redis->get($this->prefix . "sums:cost:engine:{$engine}");
            if ($cost > 0) {
                $byEngine[] = ['engine' => $engine, 'total_cost' => (float) $cost];
            }
        }

        return [
            'total_cost' => (float) $totalCost,
            'avg_cost_per_request' => $avgCostPerRequest,
            'trends' => [], // Would need more complex implementation
            'by_engine' => $byEngine,
        ];
    }

    /**
     * Get top engines by usage
     */
    public function getTopEngines(array $filters = []): array
    {
        $redis = Redis::connection($this->connection);
        
        $engines = ['openai', 'anthropic', 'google', 'cohere'];
        $results = [];
        
        foreach ($engines as $engine) {
            $requests = $redis->get($this->prefix . "counters:requests:engine:{$engine}");
            $tokens = $redis->get($this->prefix . "sums:tokens:engine:{$engine}");
            
            if ($requests > 0) {
                $results[] = [
                    'engine' => $engine,
                    'requests' => (int) $requests,
                    'tokens' => (float) ($tokens ?: 0),
                ];
            }
        }

        // Sort by requests descending
        usort($results, fn($a, $b) => $b['requests'] <=> $a['requests']);
        
        return array_slice($results, 0, 10);
    }

    /**
     * Get top models by usage
     */
    public function getTopModels(array $filters = []): array
    {
        // Redis implementation would need model tracking
        // For now, return empty array
        return [];
    }

    /**
     * Get error rates
     */
    public function getErrorRates(array $filters = []): array
    {
        $redis = Redis::connection($this->connection);
        
        $engines = ['openai', 'anthropic', 'google', 'cohere'];
        $results = [];
        
        foreach ($engines as $engine) {
            $totalRequests = $redis->get($this->prefix . "counters:requests:engine:{$engine}") ?: 0;
            $errors = $redis->get($this->prefix . "counters:errors:engine:{$engine}") ?: 0;
            
            if ($totalRequests > 0) {
                $results[] = [
                    'engine' => $engine,
                    'total_requests' => (int) $totalRequests,
                    'errors' => (int) $errors,
                    'error_rate' => $errors / $totalRequests,
                ];
            }
        }
        
        return $results;
    }

    /**
     * Get user activity
     */
    public function getUserActivity(string $userId, array $filters = []): array
    {
        // Redis implementation would need user-specific tracking
        // For now, return basic structure
        return [
            'user_id' => $userId,
            'total_requests' => 0,
            'total_tokens' => 0,
            'total_cost' => 0,
            'daily_activity' => [],
        ];
    }

    /**
     * Get usage trends
     */
    protected function getUsageTrends(array $filters): array
    {
        $redis = Redis::connection($this->connection);
        $trends = [];
        
        $days = isset($filters['time_range']) ? $this->getTimeRangeDays($filters['time_range']) : 7;
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $requests = $redis->get($this->prefix . "counters:requests:date:{$date}") ?: 0;
            
            $trends[] = [
                'date' => $date,
                'requests' => (int) $requests,
                'tokens' => 0, // Would need daily token tracking
            ];
        }
        
        return $trends;
    }

    /**
     * Convert time range to days
     */
    protected function getTimeRangeDays(string $timeRange): int
    {
        return match($timeRange) {
            '1h' => 1,
            '24h' => 1,
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };
    }
}
