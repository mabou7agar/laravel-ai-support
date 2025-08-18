<?php

namespace LaravelAIEngine\Services\Analytics\Metrics;

use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Real-time metrics collector for AI engine analytics
 */
class MetricsCollector
{
    protected string $cachePrefix = 'ai_metrics:';
    protected int $ttl = 3600; // 1 hour

    /**
     * Record AI request metrics
     */
    public function recordRequest(array $data): void
    {
        $timestamp = Carbon::now();
        $minute = $timestamp->format('Y-m-d H:i');
        
        // Increment request counters
        $this->incrementCounter('requests:total');
        $this->incrementCounter("requests:minute:{$minute}");
        $this->incrementCounter("requests:engine:{$data['engine']}");
        
        if ($data['user_id']) {
            $this->incrementCounter("requests:user:{$data['user_id']}");
        }
        
        // Track tokens
        if ($data['total_tokens'] > 0) {
            $this->addToSum('tokens:total', $data['total_tokens']);
            $this->addToSum("tokens:engine:{$data['engine']}", $data['total_tokens']);
        }
        
        // Track response times
        if ($data['response_time'] > 0) {
            $this->recordResponseTime($data['response_time']);
        }
        
        // Track costs
        if ($data['cost'] > 0) {
            $this->addToSum('cost:total', $data['cost']);
            $this->addToSum("cost:engine:{$data['engine']}", $data['cost']);
        }
        
        // Track errors
        if (!$data['success']) {
            $this->incrementCounter('errors:total');
            $this->incrementCounter("errors:engine:{$data['engine']}");
        }
    }

    /**
     * Record streaming session metrics
     */
    public function recordStreaming(array $data): void
    {
        $this->incrementCounter('streaming:sessions');
        $this->addToSum('streaming:chunks', $data['chunks_sent']);
        $this->addToSum('streaming:content_length', $data['total_content_length']);
        
        if ($data['duration'] > 0) {
            $this->recordStreamingDuration($data['duration']);
        }
        
        if (!$data['success']) {
            $this->incrementCounter('streaming:errors');
        }
    }

    /**
     * Record interactive action metrics
     */
    public function recordAction(array $data): void
    {
        $this->incrementCounter('actions:total');
        $this->incrementCounter("actions:type:{$data['action_type']}");
        
        if ($data['execution_time'] > 0) {
            $this->recordActionExecutionTime($data['execution_time']);
        }
        
        if (!$data['success']) {
            $this->incrementCounter('actions:errors');
        }
    }

    /**
     * Get current metrics
     */
    public function getMetrics(): array
    {
        return [
            'requests' => [
                'total' => $this->getCounter('requests:total'),
                'per_minute' => $this->getRequestsPerMinute(),
                'by_engine' => $this->getEngineMetrics(),
            ],
            'tokens' => [
                'total' => $this->getSum('tokens:total'),
                'by_engine' => $this->getTokensByEngine(),
            ],
            'performance' => [
                'avg_response_time' => $this->getAverageResponseTime(),
                'response_time_p95' => $this->getResponseTimePercentile(95),
                'error_rate' => $this->getErrorRate(),
            ],
            'costs' => [
                'total' => $this->getSum('cost:total'),
                'by_engine' => $this->getCostsByEngine(),
            ],
            'streaming' => [
                'sessions' => $this->getCounter('streaming:sessions'),
                'total_chunks' => $this->getSum('streaming:chunks'),
                'avg_duration' => $this->getAverageStreamingDuration(),
            ],
            'actions' => [
                'total' => $this->getCounter('actions:total'),
                'by_type' => $this->getActionsByType(),
                'avg_execution_time' => $this->getAverageActionExecutionTime(),
            ],
            'timestamp' => Carbon::now()->toISOString(),
        ];
    }

    /**
     * Get error rate
     */
    public function getErrorRate(): float
    {
        $totalRequests = $this->getCounter('requests:total');
        $totalErrors = $this->getCounter('errors:total');
        
        return $totalRequests > 0 ? $totalErrors / $totalRequests : 0;
    }

    /**
     * Get average response time
     */
    public function getAverageResponseTime(): float
    {
        $times = $this->getList('response_times');
        return !empty($times) ? array_sum($times) / count($times) : 0;
    }

    /**
     * Get request rate (requests per minute)
     */
    public function getRequestRate(): float
    {
        $currentMinute = Carbon::now()->format('Y-m-d H:i');
        return $this->getCounter("requests:minute:{$currentMinute}");
    }

    /**
     * Reset all metrics
     */
    public function resetMetrics(): void
    {
        $keys = Cache::getRedis()->keys($this->cachePrefix . '*');
        if (!empty($keys)) {
            Cache::getRedis()->del($keys);
        }
    }

    /**
     * Increment counter
     */
    protected function incrementCounter(string $key, int $amount = 1): void
    {
        $cacheKey = $this->cachePrefix . $key;
        Cache::increment($cacheKey, $amount);
        Cache::expire($cacheKey, $this->ttl);
    }

    /**
     * Add to sum
     */
    protected function addToSum(string $key, float $value): void
    {
        $cacheKey = $this->cachePrefix . $key;
        $current = Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $current + $value, $this->ttl);
    }

    /**
     * Get counter value
     */
    protected function getCounter(string $key): int
    {
        return Cache::get($this->cachePrefix . $key, 0);
    }

    /**
     * Get sum value
     */
    protected function getSum(string $key): float
    {
        return Cache::get($this->cachePrefix . $key, 0);
    }

    /**
     * Record response time
     */
    protected function recordResponseTime(float $time): void
    {
        $times = $this->getList('response_times');
        $times[] = $time;
        
        // Keep only last 100 response times
        if (count($times) > 100) {
            $times = array_slice($times, -100);
        }
        
        $this->setList('response_times', $times);
    }

    /**
     * Record streaming duration
     */
    protected function recordStreamingDuration(float $duration): void
    {
        $durations = $this->getList('streaming_durations');
        $durations[] = $duration;
        
        if (count($durations) > 100) {
            $durations = array_slice($durations, -100);
        }
        
        $this->setList('streaming_durations', $durations);
    }

    /**
     * Record action execution time
     */
    protected function recordActionExecutionTime(float $time): void
    {
        $times = $this->getList('action_execution_times');
        $times[] = $time;
        
        if (count($times) > 100) {
            $times = array_slice($times, -100);
        }
        
        $this->setList('action_execution_times', $times);
    }

    /**
     * Get list from cache
     */
    protected function getList(string $key): array
    {
        return Cache::get($this->cachePrefix . $key, []);
    }

    /**
     * Set list in cache
     */
    protected function setList(string $key, array $list): void
    {
        Cache::put($this->cachePrefix . $key, $list, $this->ttl);
    }

    /**
     * Get requests per minute for last 10 minutes
     */
    protected function getRequestsPerMinute(): array
    {
        $data = [];
        $now = Carbon::now();
        
        for ($i = 9; $i >= 0; $i--) {
            $minute = $now->copy()->subMinutes($i)->format('Y-m-d H:i');
            $data[$minute] = $this->getCounter("requests:minute:{$minute}");
        }
        
        return $data;
    }

    /**
     * Get metrics by engine
     */
    protected function getEngineMetrics(): array
    {
        $engines = ['openai', 'anthropic', 'google', 'cohere']; // Common engines
        $metrics = [];
        
        foreach ($engines as $engine) {
            $requests = $this->getCounter("requests:engine:{$engine}");
            if ($requests > 0) {
                $metrics[$engine] = $requests;
            }
        }
        
        return $metrics;
    }

    /**
     * Get tokens by engine
     */
    protected function getTokensByEngine(): array
    {
        $engines = ['openai', 'anthropic', 'google', 'cohere'];
        $metrics = [];
        
        foreach ($engines as $engine) {
            $tokens = $this->getSum("tokens:engine:{$engine}");
            if ($tokens > 0) {
                $metrics[$engine] = $tokens;
            }
        }
        
        return $metrics;
    }

    /**
     * Get costs by engine
     */
    protected function getCostsByEngine(): array
    {
        $engines = ['openai', 'anthropic', 'google', 'cohere'];
        $metrics = [];
        
        foreach ($engines as $engine) {
            $cost = $this->getSum("cost:engine:{$engine}");
            if ($cost > 0) {
                $metrics[$engine] = $cost;
            }
        }
        
        return $metrics;
    }

    /**
     * Get actions by type
     */
    protected function getActionsByType(): array
    {
        $types = ['button', 'form', 'link', 'quick_reply', 'file_upload'];
        $metrics = [];
        
        foreach ($types as $type) {
            $count = $this->getCounter("actions:type:{$type}");
            if ($count > 0) {
                $metrics[$type] = $count;
            }
        }
        
        return $metrics;
    }

    /**
     * Get response time percentile
     */
    protected function getResponseTimePercentile(int $percentile): float
    {
        $times = $this->getList('response_times');
        
        if (empty($times)) {
            return 0;
        }
        
        sort($times);
        $index = (int) ceil(($percentile / 100) * count($times)) - 1;
        
        return $times[$index] ?? 0;
    }

    /**
     * Get average streaming duration
     */
    protected function getAverageStreamingDuration(): float
    {
        $durations = $this->getList('streaming_durations');
        return !empty($durations) ? array_sum($durations) / count($durations) : 0;
    }

    /**
     * Get average action execution time
     */
    protected function getAverageActionExecutionTime(): float
    {
        $times = $this->getList('action_execution_times');
        return !empty($times) ? array_sum($times) / count($times) : 0;
    }
}
