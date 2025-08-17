<?php

namespace MagicAI\LaravelAIEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;
use MagicAI\LaravelAIEngine\Events\AIRequestStarted;
use MagicAI\LaravelAIEngine\Events\AIRequestCompleted;
use MagicAI\LaravelAIEngine\Services\AIEngineService;
use MagicAI\LaravelAIEngine\Services\JobStatusTracker;
use MagicAI\LaravelAIEngine\Traits\HandlesRateLimiting;
use Exception;

class ProcessAIRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HandlesRateLimiting;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes
    public int $backoff = 30; // 30 seconds between retries

    public function __construct(
        public AIRequest $request,
        public ?string $jobId = null,
        public ?string $callbackUrl = null,
        public ?string $userId = null
    ) {
        $this->jobId = $jobId ?? uniqid('ai_job_');
    }

    public function handle(AIEngineService $aiEngineService, JobStatusTracker $statusTracker): void
    {
        $startTime = microtime(true);
        $requestId = uniqid('ai_req_');

        try {
            // Check rate limits before processing
            if ($this->shouldCheckRateLimit()) {
                $this->checkRateLimit($this->request->engine, $this->getRateLimitUserId(), $this->jobId);
            }

            // Update job status to processing
            $statusTracker->updateStatus($this->jobId, 'processing', [
                'started_at' => now(),
                'request_id' => $requestId,
                'engine' => $this->request->engine->value,
                'model' => $this->request->model->value,
            ]);

            // Dispatch request started event
            Event::dispatch(new AIRequestStarted(
                request: $this->request,
                requestId: $requestId,
                metadata: ['job_id' => $this->jobId]
            ));

            // Process the AI request
            $response = $aiEngineService->generate($this->request);

            $processingTime = (microtime(true) - $startTime) * 1000;

            // Update job status to completed
            $statusTracker->updateStatus($this->jobId, 'completed', [
                'completed_at' => now(),
                'processing_time_ms' => $processingTime,
                'success' => $response->success,
                'credits_used' => $response->creditsUsed,
                'tokens_used' => $response->tokensUsed,
            ]);

            // Dispatch request completed event
            Event::dispatch(new AIRequestCompleted(
                request: $this->request,
                response: $response,
                requestId: $requestId,
                executionTime: $processingTime,
                metadata: ['job_id' => $this->jobId]
            ));

            // Send callback if URL provided
            if ($this->callbackUrl && $response->success) {
                $this->sendCallback($response);
            }

        } catch (Exception $e) {
            $processingTime = (microtime(true) - $startTime) * 1000;

            // Update job status to failed
            $statusTracker->updateStatus($this->jobId, 'failed', [
                'failed_at' => now(),
                'processing_time_ms' => $processingTime,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Dispatch error event
            $errorResponse = AIResponse::error(
                $e->getMessage(),
                $this->request->engine,
                $this->request->model
            );

            Event::dispatch(new AIRequestCompleted(
                request: $this->request,
                response: $errorResponse,
                requestId: $requestId,
                executionTime: $processingTime,
                metadata: ['job_id' => $this->jobId, 'error' => $e->getMessage()]
            ));

            throw $e; // Re-throw to trigger retry logic
        }
    }

    public function failed(Exception $exception): void
    {
        app(JobStatusTracker::class)->updateStatus($this->jobId, 'failed', [
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

    private function sendCallback(AIResponse $response): void
    {
        try {
            $client = new \GuzzleHttp\Client();
            $client->post($this->callbackUrl, [
                'json' => [
                    'job_id' => $this->jobId,
                    'status' => 'completed',
                    'response' => $response->toArray(),
                ],
                'timeout' => 10,
            ]);
        } catch (Exception $e) {
            // Log callback failure but don't fail the job
            logger()->warning('AI job callback failed', [
                'job_id' => $this->jobId,
                'callback_url' => $this->callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
