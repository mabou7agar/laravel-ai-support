<?php

namespace LaravelAIEngine\Services\Failover;

use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Services\Failover\Contracts\FailoverStrategyInterface;
use LaravelAIEngine\Services\Failover\Strategies\RoundRobinStrategy;
use LaravelAIEngine\Services\Failover\Strategies\PriorityStrategy;
use LaravelAIEngine\Services\Failover\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Failover Manager for automatic provider switching and reliability
 */
class FailoverManager
{
    protected array $strategies = [];
    protected array $circuitBreakers = [];
    protected array $providerHealth = [];
    protected string $defaultStrategy = 'priority';

    public function __construct()
    {
        $this->registerDefaultStrategies();
        $this->initializeCircuitBreakers();
        $this->loadProviderHealth();
    }

    /**
     * Execute request with automatic failover
     */
    public function executeWithFailover(
        callable $callback,
        array $providers,
        string $strategy = null,
        array $options = []
    ): mixed {
        $strategy = $strategy ?? $this->defaultStrategy;
        $failoverStrategy = $this->getStrategy($strategy);
        
        $orderedProviders = $failoverStrategy->getProviderOrder($providers, $this->providerHealth);
        $lastException = null;
        $attemptCount = 0;
        $maxAttempts = $options['max_attempts'] ?? count($orderedProviders);

        foreach ($orderedProviders as $provider) {
            if ($attemptCount >= $maxAttempts) {
                break;
            }

            $attemptCount++;
            $circuitBreaker = $this->getCircuitBreaker($provider);

            // Skip if circuit breaker is open
            if ($circuitBreaker->isOpen()) {
                Log::warning("Circuit breaker open for provider: {$provider}");
                continue;
            }

            try {
                $startTime = microtime(true);
                
                // Execute the callback with the current provider
                $result = $callback($provider);
                
                $responseTime = (microtime(true) - $startTime) * 1000; // ms
                
                // Record success
                $this->recordSuccess($provider, $responseTime);
                $circuitBreaker->recordSuccess();
                
                Log::info("Request successful with provider: {$provider}", [
                    'response_time' => $responseTime,
                    'attempt' => $attemptCount
                ]);
                
                return $result;
                
            } catch (\Exception $e) {
                $lastException = $e;
                
                // Record failure
                $this->recordFailure($provider, $e);
                $circuitBreaker->recordFailure();
                
                Log::warning("Request failed with provider: {$provider}", [
                    'error' => $e->getMessage(),
                    'attempt' => $attemptCount
                ]);
                
                // Continue to next provider
                continue;
            }
        }

        // All providers failed
        $this->recordSystemFailure($providers, $lastException);
        
        throw new AIEngineException(
            "All providers failed after {$attemptCount} attempts. Last error: " . 
            ($lastException ? $lastException->getMessage() : 'Unknown error')
        );
    }

    /**
     * Get provider health status
     */
    public function getProviderHealth(?string $provider = null): array
    {
        if ($provider) {
            return $this->providerHealth[$provider] ?? $this->getDefaultHealth();
        }
        
        return $this->providerHealth;
    }

    /**
     * Update provider health manually
     */
    public function updateProviderHealth(string $provider, array $health): void
    {
        $this->providerHealth[$provider] = array_merge(
            $this->getDefaultHealth(),
            $health,
            ['updated_at' => Carbon::now()->toISOString()]
        );
        
        $this->saveProviderHealth();
    }

    /**
     * Get circuit breaker status
     */
    public function getCircuitBreakerStatus(?string $provider = null): array
    {
        if ($provider) {
            $circuitBreaker = $this->getCircuitBreaker($provider);
            return [
                'state' => $circuitBreaker->getState(),
                'failure_count' => $circuitBreaker->getFailureCount(),
                'last_failure_time' => $circuitBreaker->getLastFailureTime(),
                'next_attempt_time' => $circuitBreaker->getNextAttemptTime(),
            ];
        }
        
        $status = [];
        foreach (array_keys($this->circuitBreakers) as $providerName) {
            $status[$providerName] = $this->getCircuitBreakerStatus($providerName);
        }
        
        return $status;
    }

    /**
     * Reset circuit breaker for provider
     */
    public function resetCircuitBreaker(string $provider): void
    {
        $circuitBreaker = $this->getCircuitBreaker($provider);
        $circuitBreaker->reset();
        
        Log::info("Circuit breaker reset for provider: {$provider}");
    }

    /**
     * Register failover strategy
     */
    public function registerStrategy(string $name, FailoverStrategyInterface $strategy): void
    {
        $this->strategies[$name] = $strategy;
    }

    /**
     * Get failover strategy
     */
    public function getStrategy(string $name): FailoverStrategyInterface
    {
        if (!isset($this->strategies[$name])) {
            throw new \InvalidArgumentException("Failover strategy [{$name}] not found.");
        }
        
        return $this->strategies[$name];
    }

    /**
     * Get available strategies
     */
    public function getAvailableStrategies(): array
    {
        return array_keys($this->strategies);
    }

    /**
     * Get system health overview
     */
    public function getSystemHealth(): array
    {
        $totalProviders = count($this->providerHealth);
        $healthyProviders = 0;
        $degradedProviders = 0;
        $unhealthyProviders = 0;
        
        foreach ($this->providerHealth as $health) {
            $score = $health['health_score'] ?? 0;
            if ($score >= 0.8) {
                $healthyProviders++;
            } elseif ($score >= 0.5) {
                $degradedProviders++;
            } else {
                $unhealthyProviders++;
            }
        }
        
        $systemScore = $totalProviders > 0 ? $healthyProviders / $totalProviders : 0;
        
        return [
            'system_health_score' => $systemScore,
            'total_providers' => $totalProviders,
            'healthy_providers' => $healthyProviders,
            'degraded_providers' => $degradedProviders,
            'unhealthy_providers' => $unhealthyProviders,
            'circuit_breakers' => $this->getCircuitBreakerStatus(),
            'last_updated' => Carbon::now()->toISOString(),
        ];
    }

    /**
     * Record successful request
     */
    protected function recordSuccess(string $provider, float $responseTime): void
    {
        $health = $this->providerHealth[$provider] ?? $this->getDefaultHealth();
        
        $health['success_count']++;
        $health['total_requests']++;
        $health['last_success_at'] = Carbon::now()->toISOString();
        $health['avg_response_time'] = $this->calculateAverageResponseTime(
            $health['avg_response_time'] ?? 0,
            $responseTime,
            $health['success_count']
        );
        
        // Update health score
        $health['health_score'] = $this->calculateHealthScore($health);
        
        $this->providerHealth[$provider] = $health;
        $this->saveProviderHealth();
    }

    /**
     * Record failed request
     */
    protected function recordFailure(string $provider, \Exception $exception): void
    {
        $health = $this->providerHealth[$provider] ?? $this->getDefaultHealth();
        
        $health['failure_count']++;
        $health['total_requests']++;
        $health['last_failure_at'] = Carbon::now()->toISOString();
        $health['last_error'] = $exception->getMessage();
        
        // Update health score
        $health['health_score'] = $this->calculateHealthScore($health);
        
        $this->providerHealth[$provider] = $health;
        $this->saveProviderHealth();
    }

    /**
     * Record system-wide failure
     */
    protected function recordSystemFailure(array $providers, ?\Exception $lastException): void
    {
        Log::critical('System-wide AI provider failure', [
            'providers' => $providers,
            'last_error' => $lastException?->getMessage(),
            'health_status' => $this->getSystemHealth()
        ]);
        
        // Trigger alerts/notifications here
        event('ai.system.failure', [
            'providers' => $providers,
            'error' => $lastException?->getMessage(),
            'timestamp' => Carbon::now()->toISOString()
        ]);
    }

    /**
     * Calculate health score based on metrics
     */
    protected function calculateHealthScore(array $health): float
    {
        $totalRequests = $health['total_requests'] ?? 1;
        $successRate = ($health['success_count'] ?? 0) / $totalRequests;
        
        $responseTimeScore = 1.0;
        $avgResponseTime = $health['avg_response_time'] ?? 0;
        if ($avgResponseTime > 0) {
            // Penalize slow response times (>5s = 0 score, <1s = 1.0 score)
            $responseTimeScore = max(0, min(1, (5000 - $avgResponseTime) / 4000));
        }
        
        // Recent failure penalty
        $recentFailurePenalty = 0;
        if (!empty($health['last_failure_at'])) {
            $lastFailure = Carbon::parse($health['last_failure_at']);
            $minutesSinceFailure = $lastFailure->diffInMinutes(Carbon::now());
            if ($minutesSinceFailure < 60) {
                $recentFailurePenalty = (60 - $minutesSinceFailure) / 60 * 0.3;
            }
        }
        
        $score = ($successRate * 0.6 + $responseTimeScore * 0.4) - $recentFailurePenalty;
        
        return max(0, min(1, $score));
    }

    /**
     * Calculate average response time
     */
    protected function calculateAverageResponseTime(float $currentAvg, float $newTime, int $count): float
    {
        if ($count <= 1) {
            return $newTime;
        }
        
        return (($currentAvg * ($count - 1)) + $newTime) / $count;
    }

    /**
     * Get circuit breaker for provider
     */
    protected function getCircuitBreaker(string $provider): CircuitBreaker
    {
        if (!isset($this->circuitBreakers[$provider])) {
            $this->circuitBreakers[$provider] = new CircuitBreaker(
                $provider,
                config('ai-engine.failover.circuit_breaker.failure_threshold', 5),
                config('ai-engine.failover.circuit_breaker.timeout', 60),
                config('ai-engine.failover.circuit_breaker.retry_timeout', 300)
            );
        }
        
        return $this->circuitBreakers[$provider];
    }

    /**
     * Initialize circuit breakers for all providers
     */
    protected function initializeCircuitBreakers(): void
    {
        $providers = config('ai-engine.failover.providers', []);
        
        foreach ($providers as $provider) {
            $this->getCircuitBreaker($provider);
        }
    }

    /**
     * Register default failover strategies
     */
    protected function registerDefaultStrategies(): void
    {
        $this->strategies['priority'] = new PriorityStrategy();
        $this->strategies['round_robin'] = new RoundRobinStrategy();
    }

    /**
     * Load provider health from cache
     */
    protected function loadProviderHealth(): void
    {
        $this->providerHealth = Cache::get('ai_engine.provider_health', []);
    }

    /**
     * Save provider health to cache
     */
    protected function saveProviderHealth(): void
    {
        Cache::put('ai_engine.provider_health', $this->providerHealth, 3600); // 1 hour
    }

    /**
     * Get default health metrics
     */
    protected function getDefaultHealth(): array
    {
        return [
            'health_score' => 1.0,
            'success_count' => 0,
            'failure_count' => 0,
            'total_requests' => 0,
            'avg_response_time' => 0,
            'last_success_at' => null,
            'last_failure_at' => null,
            'last_error' => null,
            'created_at' => Carbon::now()->toISOString(),
            'updated_at' => Carbon::now()->toISOString(),
        ];
    }
}
