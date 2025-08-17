<?php

namespace MagicAI\LaravelAIEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\Services\JobStatusTracker;
use MagicAI\LaravelAIEngine\Traits\HandlesRateLimiting;
use Exception;

class BatchProcessAIRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HandlesRateLimiting;

    public int $tries = 2;
    public int $timeout = 1800; // 30 minutes for batch processing
    public int $backoff = 60; // 1 minute between retries

    public function __construct(
        public array $requests, // Array of AIRequest objects
        public string $batchId,
        public ?string $callbackUrl = null,
        public bool $stopOnError = false,
        public ?string $userId = null
    ) {}

    public function handle(JobStatusTracker $statusTracker): void
    {
        $startTime = microtime(true);
        $totalRequests = count($this->requests);
        $processedRequests = 0;
        $successfulRequests = 0;
        $failedRequests = 0;
        $results = [];

        try {
            // Handle rate limiting for batch requests if enabled
            $processableRequests = $this->requests;
            $delayedRequests = [];
            
            if ($this->shouldCheckRateLimit() && !empty($this->requests)) {
                $firstEngine = $this->requests[0]->engine;
                $rateLimitResult = $this->handleBatchRateLimit($firstEngine, $this->requests, $this->batchId);
                $processableRequests = $rateLimitResult['processable'];
                $delayedRequests = $rateLimitResult['delayed'];
            }

            // Update batch status to processing
            $statusTracker->updateStatus($this->batchId, 'processing', [
                'started_at' => now(),
                'total_requests' => $totalRequests,
                'processable_requests' => count($processableRequests),
                'delayed_requests' => count($delayedRequests),
                'processed_requests' => 0,
                'successful_requests' => 0,
                'failed_requests' => 0,
            ]);

            // Schedule delayed requests for later processing if any
            if (!empty($delayedRequests)) {
                $this->scheduleDelayedRequests($delayedRequests, $statusTracker);
            }

            foreach ($processableRequests as $index => $request) {
                if (!$request instanceof AIRequest) {
                    throw new \InvalidArgumentException("Invalid AIRequest at index {$index}");
                }

                try {
                    // Dispatch individual job for each request
                    $jobId = uniqid("batch_{$this->batchId}_req_{$index}_");
                    
                    ProcessAIRequestJob::dispatch($request, $jobId, null, $this->userId)
                        ->onQueue($this->queue ?? 'default');

                    $results[$index] = [
                        'status' => 'queued',
                        'job_id' => $jobId,
                        'request_index' => $index,
                    ];

                    $successfulRequests++;

                } catch (Exception $e) {
                    $results[$index] = [
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                        'request_index' => $index,
                    ];

                    $failedRequests++;

                    if ($this->stopOnError) {
                        throw new Exception("Batch processing stopped due to error at index {$index}: " . $e->getMessage());
                    }
                }

                $processedRequests++;

                // Update progress every 10 requests or at the end
                if ($processedRequests % 10 === 0 || $processedRequests === $totalRequests) {
                    $statusTracker->updateStatus($this->batchId, 'processing', [
                        'processed_requests' => $processedRequests,
                        'successful_requests' => $successfulRequests,
                        'failed_requests' => $failedRequests,
                        'progress_percentage' => round(($processedRequests / $totalRequests) * 100, 2),
                    ]);
                }
            }

            $processingTime = (microtime(true) - $startTime) * 1000;

            // Update final batch status
            $statusTracker->updateStatus($this->batchId, 'completed', [
                'completed_at' => now(),
                'processing_time_ms' => $processingTime,
                'total_requests' => $totalRequests,
                'processed_requests' => $processedRequests,
                'successful_requests' => $successfulRequests,
                'failed_requests' => $failedRequests,
                'results' => $results,
            ]);

            // Send callback if URL provided
            if ($this->callbackUrl) {
                $this->sendBatchCallback($results, $successfulRequests, $failedRequests);
            }

        } catch (Exception $e) {
            $processingTime = (microtime(true) - $startTime) * 1000;

            // Update batch status to failed
            $statusTracker->updateStatus($this->batchId, 'failed', [
                'failed_at' => now(),
                'processing_time_ms' => $processingTime,
                'error' => $e->getMessage(),
                'processed_requests' => $processedRequests,
                'successful_requests' => $successfulRequests,
                'failed_requests' => $failedRequests,
                'results' => $results,
            ]);

            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        app(JobStatusTracker::class)->updateStatus($this->batchId, 'failed', [
            'final_failure_at' => now(),
            'final_error' => $exception->getMessage(),
            'total_attempts' => $this->attempts(),
        ]);
    }

    /**
     * Get user ID for rate limiting
     */
    protected function getRateLimitUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Schedule delayed requests for later processing
     */
    private function scheduleDelayedRequests(array $delayedRequests, JobStatusTracker $statusTracker): void
    {
        if (empty($delayedRequests)) {
            return;
        }

        // Create a new batch job for delayed requests with delay
        $delayedBatchId = $this->batchId . '_delayed_' . uniqid();
        $delaySeconds = $this->calculateRateLimitDelay($delayedRequests[0]->engine);

        $delayedJob = new self(
            $delayedRequests,
            $delayedBatchId,
            $this->callbackUrl,
            $this->stopOnError,
            $this->userId
        );

        // Dispatch with delay
        dispatch($delayedJob)->delay(now()->addSeconds($delaySeconds));

        // Update status tracker
        $statusTracker->updateStatus($delayedBatchId, 'scheduled', [
            'scheduled_at' => now(),
            'delayed_from_batch' => $this->batchId,
            'delay_seconds' => $delaySeconds,
            'total_requests' => count($delayedRequests),
        ]);
    }

    private function sendBatchCallback(array $results, int $successful, int $failed): void
    {
        try {
            $client = new \GuzzleHttp\Client();
            $client->post($this->callbackUrl, [
                'json' => [
                    'batch_id' => $this->batchId,
                    'status' => 'completed',
                    'summary' => [
                        'total_requests' => count($this->requests),
                        'successful_requests' => $successful,
                        'failed_requests' => $failed,
                    ],
                    'results' => $results,
                ],
                'timeout' => 30,
            ]);
        } catch (Exception $e) {
            logger()->warning('Batch AI job callback failed', [
                'batch_id' => $this->batchId,
                'callback_url' => $this->callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
