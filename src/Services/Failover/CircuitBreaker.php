<?php

namespace LaravelAIEngine\Services\Failover;

use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Circuit Breaker implementation for provider reliability
 */
class CircuitBreaker
{
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';

    protected string $provider;
    protected int $failureThreshold;
    protected int $timeout;
    protected int $retryTimeout;
    protected string $cacheKey;

    public function __construct(
        string $provider,
        int $failureThreshold = 5,
        int $timeout = 60,
        int $retryTimeout = 300
    ) {
        $this->provider = $provider;
        $this->failureThreshold = $failureThreshold;
        $this->timeout = $timeout;
        $this->retryTimeout = $retryTimeout;
        $this->cacheKey = "circuit_breaker:{$provider}";
    }

    /**
     * Check if circuit breaker is open
     */
    public function isOpen(): bool
    {
        $state = $this->getState();
        
        if ($state === self::STATE_OPEN) {
            // Check if we should transition to half-open
            if ($this->shouldAttemptReset()) {
                $this->setState(self::STATE_HALF_OPEN);
                return false;
            }
            return true;
        }
        
        return false;
    }

    /**
     * Record successful request
     */
    public function recordSuccess(): void
    {
        $data = $this->getData();
        
        // Reset failure count and close circuit
        $data['failure_count'] = 0;
        $data['last_success_time'] = Carbon::now()->timestamp;
        $data['state'] = self::STATE_CLOSED;
        
        $this->saveData($data);
    }

    /**
     * Record failed request
     */
    public function recordFailure(): void
    {
        $data = $this->getData();
        
        $data['failure_count'] = ($data['failure_count'] ?? 0) + 1;
        $data['last_failure_time'] = Carbon::now()->timestamp;
        
        // Check if we should open the circuit
        if ($data['failure_count'] >= $this->failureThreshold) {
            $data['state'] = self::STATE_OPEN;
            $data['opened_at'] = Carbon::now()->timestamp;
        }
        
        $this->saveData($data);
    }

    /**
     * Get current state
     */
    public function getState(): string
    {
        $data = $this->getData();
        return $data['state'] ?? self::STATE_CLOSED;
    }

    /**
     * Set state manually
     */
    public function setState(string $state): void
    {
        $data = $this->getData();
        $data['state'] = $state;
        
        if ($state === self::STATE_OPEN) {
            $data['opened_at'] = Carbon::now()->timestamp;
        }
        
        $this->saveData($data);
    }

    /**
     * Get failure count
     */
    public function getFailureCount(): int
    {
        $data = $this->getData();
        return $data['failure_count'] ?? 0;
    }

    /**
     * Get last failure time
     */
    public function getLastFailureTime(): ?int
    {
        $data = $this->getData();
        return $data['last_failure_time'] ?? null;
    }

    /**
     * Get next attempt time (when circuit will try half-open)
     */
    public function getNextAttemptTime(): ?int
    {
        $data = $this->getData();
        
        if (($data['state'] ?? self::STATE_CLOSED) === self::STATE_OPEN && isset($data['opened_at'])) {
            return $data['opened_at'] + $this->retryTimeout;
        }
        
        return null;
    }

    /**
     * Reset circuit breaker
     */
    public function reset(): void
    {
        $data = [
            'state' => self::STATE_CLOSED,
            'failure_count' => 0,
            'last_success_time' => Carbon::now()->timestamp,
            'last_failure_time' => null,
            'opened_at' => null,
        ];
        
        $this->saveData($data);
    }

    /**
     * Get circuit breaker statistics
     */
    public function getStats(): array
    {
        $data = $this->getData();
        
        return [
            'provider' => $this->provider,
            'state' => $data['state'] ?? self::STATE_CLOSED,
            'failure_count' => $data['failure_count'] ?? 0,
            'failure_threshold' => $this->failureThreshold,
            'last_success_time' => $data['last_success_time'] ?? null,
            'last_failure_time' => $data['last_failure_time'] ?? null,
            'opened_at' => $data['opened_at'] ?? null,
            'next_attempt_time' => $this->getNextAttemptTime(),
            'timeout' => $this->timeout,
            'retry_timeout' => $this->retryTimeout,
        ];
    }

    /**
     * Check if circuit should attempt reset (transition to half-open)
     */
    protected function shouldAttemptReset(): bool
    {
        $data = $this->getData();
        
        if (!isset($data['opened_at'])) {
            return false;
        }
        
        $timeSinceOpened = Carbon::now()->timestamp - $data['opened_at'];
        return $timeSinceOpened >= $this->retryTimeout;
    }

    /**
     * Get circuit breaker data from cache
     */
    protected function getData(): array
    {
        return Cache::get($this->cacheKey, []);
    }

    /**
     * Save circuit breaker data to cache
     */
    protected function saveData(array $data): void
    {
        Cache::put($this->cacheKey, $data, $this->timeout * 60); // Convert to seconds
    }
}
