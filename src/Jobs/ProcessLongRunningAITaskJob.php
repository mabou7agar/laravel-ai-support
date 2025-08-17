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

class ProcessLongRunningAITaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, HandlesRateLimiting;

    public int $tries = 2;
    public int $timeout = 3600; // 1 hour for long-running tasks
    public int $backoff = 300; // 5 minutes between retries

    public function __construct(
        public AIRequest $request,
        public string $taskType, // 'video_generation', 'large_batch_images', 'document_processing'
        public ?string $jobId = null,
        public ?string $callbackUrl = null,
        public array $progressCallbacks = [],
        public ?string $userId = null
    ) {
        $this->jobId = $jobId ?? uniqid('long_task_');

        // Set appropriate queue based on task type
        $this->onQueue($this->getQueueForTaskType($taskType));
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
                'task_type' => $this->taskType,
                'engine' => $this->request->engine->value,
                'model' => $this->request->model->value,
                'estimated_duration_minutes' => $this->getEstimatedDuration(),
            ]);

            // Dispatch request started event
            Event::dispatch(new AIRequestStarted(
                request: $this->request,
                requestId: $requestId,
                metadata: [
                    'job_id' => $this->jobId,
                    'task_type' => $this->taskType,
                    'is_long_running' => true,
                ]
            ));

            // Send initial progress update
            $this->sendProgressUpdate(0, 'Task started');

            // Process the AI request with progress tracking
            $response = $this->processWithProgress($aiEngineService, $statusTracker);

            $processingTime = (microtime(true) - $startTime) * 1000;

            // Update job status to completed
            $statusTracker->updateStatus($this->jobId, 'completed', [
                'completed_at' => now(),
                'processing_time_ms' => $processingTime,
                'success' => $response->success,
                'credits_used' => $response->creditsUsed,
                'tokens_used' => $response->tokensUsed,
                'files_generated' => count($response->files ?? []),
            ]);

            // Send final progress update
            $this->sendProgressUpdate(100, 'Task completed successfully');

            // Dispatch request completed event
            Event::dispatch(new AIRequestCompleted(
                request: $this->request,
                response: $response,
                requestId: $requestId,
                executionTime: $processingTime,
                metadata: [
                    'job_id' => $this->jobId,
                    'task_type' => $this->taskType,
                    'is_long_running' => true,
                ]
            ));

            // Send callback if URL provided
            if ($this->callbackUrl && $response->success) {
                $this->sendCallback($response, $processingTime);
            }

        } catch (Exception $e) {
            $processingTime = (microtime(true) - $startTime) * 1000;

            // Update job status to failed
            $statusTracker->updateStatus($this->jobId, 'failed', [
                'failed_at' => now(),
                'processing_time_ms' => $processingTime,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'task_type' => $this->taskType,
            ]);

            // Send error progress update
            $this->sendProgressUpdate(-1, 'Task failed: ' . $e->getMessage());

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
                metadata: [
                    'job_id' => $this->jobId,
                    'task_type' => $this->taskType,
                    'error' => $e->getMessage(),
                ]
            ));

            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        app(JobStatusTracker::class)->updateStatus($this->jobId, 'failed', [
            'final_failure_at' => now(),
            'final_error' => $exception->getMessage(),
            'total_attempts' => $this->attempts(),
            'task_type' => $this->taskType,
        ]);

        $this->sendProgressUpdate(-1, 'Task permanently failed: ' . $exception->getMessage());
    }

    /**
     * Get user ID for rate limiting
     */
    protected function getRateLimitUserId(): ?string
    {
        return $this->userId;
    }

    private function processWithProgress(AIEngineService $aiEngineService, JobStatusTracker $statusTracker): AIResponse
    {
        // Send progress updates during processing
        $this->sendProgressUpdate(25, 'Validating request and checking credits');

        // Add progress tracking metadata to request
        $requestWithProgress = new AIRequest(
            prompt:       $this->request->prompt,
            engine:       $this->request->engine,
            model:        $this->request->model,
            parameters:   array_merge($this->request->parameters, [
                'progress_callback' => function ($progress, $message) {
                    $this->sendProgressUpdate($progress, $message);
                }
            ]),
            userId:       $this->request->userId,
            context:      $this->request->context, // Long-running tasks don't stream
            files:        $this->request->files,
            stream:       false,
            systemPrompt: $this->request->systemPrompt,
            messages:     $this->request->messages,
            maxTokens:    $this->request->maxTokens,
            temperature:  $this->request->temperature,
            seed:         $this->request->seed,
            metadata:     array_merge($this->request->metadata, [
                'job_id' => $this->jobId,
                'task_type' => $this->taskType,
            ])
        );

        $this->sendProgressUpdate(50, 'Processing AI request');

        $response = $aiEngineService->generate($requestWithProgress);

        $this->sendProgressUpdate(90, 'Finalizing response');

        return $response;
    }

    private function sendProgressUpdate(int $percentage, string $message): void
    {
        // Update job status with progress
        app(JobStatusTracker::class)->updateProgress($this->jobId, $percentage, $message);

        // Send progress callbacks
        foreach ($this->progressCallbacks as $callbackUrl) {
            try {
                $client = new \GuzzleHttp\Client();
                $client->post($callbackUrl, [
                    'json' => [
                        'job_id' => $this->jobId,
                        'task_type' => $this->taskType,
                        'progress_percentage' => $percentage,
                        'message' => $message,
                        'timestamp' => now()->toISOString(),
                    ],
                    'timeout' => 5,
                ]);
            } catch (Exception $e) {
                // Log but don't fail the job for progress callback failures
                logger()->debug('Progress callback failed', [
                    'job_id' => $this->jobId,
                    'callback_url' => $callbackUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendCallback(AIResponse $response, float $processingTime): void
    {
        try {
            $client = new \GuzzleHttp\Client();
            $client->post($this->callbackUrl, [
                'json' => [
                    'job_id' => $this->jobId,
                    'task_type' => $this->taskType,
                    'status' => 'completed',
                    'processing_time_ms' => $processingTime,
                    'response' => $response->toArray(),
                ],
                'timeout' => 30,
            ]);
        } catch (Exception $e) {
            logger()->warning('Long-running AI job callback failed', [
                'job_id' => $this->jobId,
                'task_type' => $this->taskType,
                'callback_url' => $this->callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getQueueForTaskType(string $taskType): string
    {
        return match ($taskType) {
            'video_generation' => 'video-processing',
            'large_batch_images' => 'image-processing',
            'document_processing' => 'document-processing',
            default => 'long-running',
        };
    }

    private function getEstimatedDuration(): int
    {
        return match ($this->taskType) {
            'video_generation' => 30, // 30 minutes
            'large_batch_images' => 15, // 15 minutes
            'document_processing' => 10, // 10 minutes
            default => 20, // 20 minutes
        };
    }
}
