<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Models\AINodeCircuitBreaker;
use Illuminate\Support\Facades\Log;

class CircuitBreakerService
{
    const STATE_CLOSED = 'closed';      // Normal operation
    const STATE_OPEN = 'open';          // Failing, reject requests
    const STATE_HALF_OPEN = 'half_open'; // Testing if recovered
    
    protected int $failureThreshold;
    protected int $successThreshold;
    protected int $timeout;
    protected int $retryTimeout;
    
    public function __construct()
    {
        $this->failureThreshold = config('ai-engine.nodes.circuit_breaker.failure_threshold', 5);
        $this->successThreshold = config('ai-engine.nodes.circuit_breaker.success_threshold', 2);
        $this->timeout = config('ai-engine.nodes.circuit_breaker.timeout', 60);
        $this->retryTimeout = config('ai-engine.nodes.circuit_breaker.retry_timeout', 30);
    }
    
    /**
     * Check if circuit is open for a node
     */
    public function isOpen(AINode $node): bool
    {
        $breaker = $this->getOrCreateBreaker($node);
        
        if ($breaker->state === self::STATE_OPEN) {
            // Check if we should try again (half-open)
            if ($breaker->isReadyForRetry()) {
                $this->transitionToHalfOpen($breaker);
                return false;
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if circuit is closed (normal operation)
     */
    public function isClosed(AINode $node): bool
    {
        $breaker = $this->getOrCreateBreaker($node);
        return $breaker->state === self::STATE_CLOSED;
    }
    
    /**
     * Check if circuit is half-open (testing)
     */
    public function isHalfOpen(AINode $node): bool
    {
        $breaker = $this->getOrCreateBreaker($node);
        return $breaker->state === self::STATE_HALF_OPEN;
    }
    
    /**
     * Record success
     */
    public function recordSuccess(AINode $node): void
    {
        $breaker = $this->getOrCreateBreaker($node);
        
        $breaker->increment('success_count');
        $breaker->update([
            'last_success_at' => now(),
        ]);
        
        // If half-open and enough successes, close the circuit
        if ($breaker->state === self::STATE_HALF_OPEN && $breaker->success_count >= $this->successThreshold) {
            $this->transitionToClosed($breaker);
        }
        
        // If closed, reset failure count on success
        if ($breaker->state === self::STATE_CLOSED) {
            $breaker->update(['failure_count' => 0]);
        }
    }
    
    /**
     * Record failure
     */
    public function recordFailure(AINode $node): void
    {
        $breaker = $this->getOrCreateBreaker($node);
        
        $breaker->increment('failure_count');
        $breaker->update([
            'last_failure_at' => now(),
        ]);
        
        // If half-open, immediately open on failure
        if ($breaker->state === self::STATE_HALF_OPEN) {
            $this->transitionToOpen($breaker);
            return;
        }
        
        // If closed and reached threshold, open the circuit
        if ($breaker->state === self::STATE_CLOSED && $breaker->failure_count >= $this->failureThreshold) {
            $this->transitionToOpen($breaker);
        }
    }
    
    /**
     * Transition to CLOSED state
     */
    protected function transitionToClosed(AINodeCircuitBreaker $breaker): void
    {
        $breaker->update([
            'state' => self::STATE_CLOSED,
            'failure_count' => 0,
            'success_count' => 0,
            'opened_at' => null,
            'next_retry_at' => null,
        ]);
        
        Log::channel('ai-engine')->info('Circuit breaker closed', [
            'node_id' => $breaker->node_id,
            'node_slug' => $breaker->node->slug ?? null,
        ]);
    }
    
    /**
     * Transition to OPEN state
     */
    protected function transitionToOpen(AINodeCircuitBreaker $breaker): void
    {
        $nextRetry = now()->addSeconds($this->retryTimeout);
        
        $breaker->update([
            'state' => self::STATE_OPEN,
            'opened_at' => now(),
            'next_retry_at' => $nextRetry,
            'success_count' => 0,
        ]);
        
        Log::channel('ai-engine')->error('Circuit breaker opened', [
            'node_id' => $breaker->node_id,
            'node_slug' => $breaker->node->slug ?? null,
            'failure_count' => $breaker->failure_count,
            'next_retry_at' => $nextRetry->toDateTimeString(),
        ]);
        
        // Update node status
        $breaker->node->update(['status' => 'error']);
    }
    
    /**
     * Transition to HALF_OPEN state
     */
    protected function transitionToHalfOpen(AINodeCircuitBreaker $breaker): void
    {
        $breaker->update([
            'state' => self::STATE_HALF_OPEN,
            'success_count' => 0,
            'failure_count' => 0,
        ]);
        
        Log::channel('ai-engine')->info('Circuit breaker half-open (testing)', [
            'node_id' => $breaker->node_id,
            'node_slug' => $breaker->node->slug ?? null,
        ]);
    }
    
    /**
     * Get or create circuit breaker for node
     */
    protected function getOrCreateBreaker(AINode $node): AINodeCircuitBreaker
    {
        return AINodeCircuitBreaker::firstOrCreate(
            ['node_id' => $node->id],
            [
                'state' => self::STATE_CLOSED,
                'failure_count' => 0,
                'success_count' => 0,
            ]
        );
    }
    
    /**
     * Reset circuit breaker
     */
    public function reset(AINode $node): void
    {
        $breaker = $this->getOrCreateBreaker($node);
        $this->transitionToClosed($breaker);
        
        Log::channel('ai-engine')->info('Circuit breaker manually reset', [
            'node_id' => $node->id,
            'node_slug' => $node->slug,
        ]);
    }
    
    /**
     * Get circuit breaker statistics
     */
    public function getStatistics(AINode $node): array
    {
        $breaker = $this->getOrCreateBreaker($node);
        
        return [
            'state' => $breaker->state,
            'failure_count' => $breaker->failure_count,
            'success_count' => $breaker->success_count,
            'failure_rate' => $breaker->getFailureRate(),
            'last_failure_at' => $breaker->last_failure_at?->toDateTimeString(),
            'last_success_at' => $breaker->last_success_at?->toDateTimeString(),
            'opened_at' => $breaker->opened_at?->toDateTimeString(),
            'next_retry_at' => $breaker->next_retry_at?->toDateTimeString(),
            'is_open' => $breaker->isOpen(),
            'is_ready_for_retry' => $breaker->isReadyForRetry(),
        ];
    }
    
    /**
     * Get all open circuit breakers
     */
    public function getOpenCircuits(): \Illuminate\Support\Collection
    {
        return AINodeCircuitBreaker::open()->with('node')->get();
    }
}
