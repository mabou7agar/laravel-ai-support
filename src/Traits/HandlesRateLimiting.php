<?php

namespace MagicAI\LaravelAIEngine\Traits;

use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Exceptions\RateLimitExceededException;
use MagicAI\LaravelAIEngine\Services\RateLimitManager;
use MagicAI\LaravelAIEngine\Services\JobStatusTracker;

trait HandlesRateLimiting
{
    /**
     * Check rate limits before processing job
     */
    protected function checkRateLimit(
        EngineEnum $engine,
        ?string $userId = null,
        ?string $jobId = null
    ): void {
        $rateLimitManager = app(RateLimitManager::class);
        $statusTracker = app(JobStatusTracker::class);

        try {
            // Check if we're within rate limits
            $rateLimitManager->checkRateLimit($engine, $userId);
            
            // Update job status with rate limit check passed
            if ($jobId) {
                $statusTracker->updateStatus($jobId, 'processing', [
                    'rate_limit_check' => 'passed',
                    'remaining_requests' => $rateLimitManager->getRemainingRequests($engine, $userId),
                ]);
            }
            
        } catch (RateLimitExceededException $e) {
            // Handle rate limit exceeded
            if ($jobId) {
                $statusTracker->updateStatus($jobId, 'rate_limited', [
                    'rate_limit_exceeded_at' => now(),
                    'rate_limit_error' => $e->getMessage(),
                    'remaining_requests' => 0,
                ]);
            }

            // Calculate delay based on rate limit window
            $delaySeconds = $this->calculateRateLimitDelay($engine);
            
            // Release the job back to the queue with delay
            $this->release($delaySeconds);
            
            return; // Exit early, job will be retried after delay
        }
    }

    /**
     * Calculate delay for rate-limited jobs
     */
    protected function calculateRateLimitDelay(EngineEnum $engine): int
    {
        $limits = config("ai-engine.rate_limiting.per_engine.{$engine->value}");
        
        if (!$limits) {
            return 60; // Default 1 minute delay
        }

        // Use the rate limit window as base delay
        $baseDelay = ($limits['per_minute'] ?? 1) * 60;
        
        // Add some jitter to prevent thundering herd
        $jitter = rand(0, 30);
        
        return min($baseDelay + $jitter, 300); // Max 5 minutes
    }

    /**
     * Check if job should respect rate limits
     */
    protected function shouldCheckRateLimit(): bool
    {
        return config('ai-engine.rate_limiting.enabled', true) && 
               config('ai-engine.rate_limiting.apply_to_jobs', true);
    }

    /**
     * Get user ID for rate limiting (can be overridden by jobs)
     */
    protected function getRateLimitUserId(): ?string
    {
        // Default implementation returns null (global rate limiting)
        // Jobs can override this to provide user-specific rate limiting
        return null;
    }

    /**
     * Handle rate limit exceeded for batch operations
     */
    protected function handleBatchRateLimit(
        EngineEnum $engine,
        array $requests,
        ?string $jobId = null
    ): array {
        $rateLimitManager = app(RateLimitManager::class);
        $statusTracker = app(JobStatusTracker::class);
        
        $processableRequests = [];
        $delayedRequests = [];
        
        foreach ($requests as $index => $request) {
            try {
                // Check rate limit for this request
                $remaining = $rateLimitManager->getRemainingRequests($engine, $this->getRateLimitUserId());
                
                if ($remaining > 0) {
                    $processableRequests[] = $request;
                } else {
                    $delayedRequests[] = $request;
                }
                
            } catch (RateLimitExceededException $e) {
                $delayedRequests[] = $request;
            }
        }

        // If we have delayed requests, schedule them for later
        if (!empty($delayedRequests) && $jobId) {
            $statusTracker->updateStatus($jobId, 'partially_rate_limited', [
                'processable_count' => count($processableRequests),
                'delayed_count' => count($delayedRequests),
                'rate_limited_at' => now(),
            ]);
        }

        return [
            'processable' => $processableRequests,
            'delayed' => $delayedRequests,
        ];
    }
}
