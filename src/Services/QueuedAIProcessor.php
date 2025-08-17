<?php

namespace MagicAI\LaravelAIEngine\Services;

use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\Jobs\ProcessAIRequestJob;
use MagicAI\LaravelAIEngine\Jobs\ProcessLongRunningAITaskJob;
use MagicAI\LaravelAIEngine\Jobs\BatchProcessAIRequestsJob;

class QueuedAIProcessor
{
    public function __construct(
        private JobStatusTracker $statusTracker
    ) {}

    /**
     * Queue an AI request for background processing
     */
    public function queueRequest(
        AIRequest $request,
        ?string $callbackUrl = null,
        ?string $queue = null,
        ?string $userId = null
    ): string {
        $jobId = uniqid('ai_job_');
        
        $job = new ProcessAIRequestJob(
            request: $request,
            jobId: $jobId,
            callbackUrl: $callbackUrl,
            userId: $userId ?? $request->userId
        );

        if ($queue) {
            $job->onQueue($queue);
        }

        dispatch($job);

        // Initialize job status
        $this->statusTracker->updateStatus($jobId, 'queued', [
            'queued_at' => now(),
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'user_id' => $request->userId,
        ]);

        return $jobId;
    }

    /**
     * Queue a long-running AI task
     */
    public function queueLongRunningTask(
        AIRequest $request,
        string $taskType,
        ?string $callbackUrl = null,
        array $progressCallbacks = [],
        ?string $queue = null,
        ?string $userId = null
    ): string {
        $jobId = uniqid('long_task_');
        
        $job = new ProcessLongRunningAITaskJob(
            request: $request,
            taskType: $taskType,
            jobId: $jobId,
            callbackUrl: $callbackUrl,
            progressCallbacks: $progressCallbacks,
            userId: $userId ?? $request->userId
        );

        if ($queue) {
            $job->onQueue($queue);
        }

        dispatch($job);

        // Initialize job status
        $this->statusTracker->updateStatus($jobId, 'queued', [
            'queued_at' => now(),
            'task_type' => $taskType,
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'user_id' => $request->userId,
            'estimated_duration_minutes' => $this->getEstimatedDuration($taskType),
        ]);

        return $jobId;
    }

    /**
     * Queue a batch of AI requests
     */
    public function queueBatch(
        array $requests,
        ?string $callbackUrl = null,
        bool $stopOnError = false,
        ?string $queue = null,
        ?string $userId = null
    ): string {
        $batchId = uniqid('batch_');
        
        $job = new BatchProcessAIRequestsJob(
            requests: $requests,
            batchId: $batchId,
            callbackUrl: $callbackUrl,
            stopOnError: $stopOnError,
            userId: $userId
        );

        if ($queue) {
            $job->onQueue($queue);
        }

        dispatch($job);

        // Initialize batch status
        $this->statusTracker->updateStatus($batchId, 'queued', [
            'queued_at' => now(),
            'total_requests' => count($requests),
            'stop_on_error' => $stopOnError,
        ]);

        return $batchId;
    }

    /**
     * Get job status
     */
    public function getJobStatus(string $jobId): ?array
    {
        return $this->statusTracker->getStatus($jobId);
    }

    /**
     * Get multiple job statuses
     */
    public function getJobStatuses(array $jobIds): array
    {
        return $this->statusTracker->getMultipleStatuses($jobIds);
    }

    /**
     * Check if job is completed
     */
    public function isJobCompleted(string $jobId): bool
    {
        return $this->statusTracker->isCompleted($jobId);
    }

    /**
     * Check if job is running
     */
    public function isJobRunning(string $jobId): bool
    {
        return $this->statusTracker->isRunning($jobId);
    }

    /**
     * Get job progress
     */
    public function getJobProgress(string $jobId): int
    {
        return $this->statusTracker->getProgress($jobId);
    }

    /**
     * Get processing statistics
     */
    public function getStatistics(int $lastHours = 24): array
    {
        return $this->statusTracker->getStatistics($lastHours);
    }

    /**
     * Clean up old job statuses
     */
    public function cleanup(int $olderThanHours = 24): int
    {
        return $this->statusTracker->cleanup($olderThanHours);
    }

    /**
     * Queue multiple individual requests (not as a batch)
     */
    public function queueMultipleRequests(
        array $requests,
        ?string $callbackUrl = null,
        ?string $queue = null
    ): array {
        $jobIds = [];
        
        foreach ($requests as $index => $request) {
            if (!$request instanceof AIRequest) {
                throw new \InvalidArgumentException("Invalid AIRequest at index {$index}");
            }
            
            $jobIds[] = $this->queueRequest($request, $callbackUrl, $queue);
        }
        
        return $jobIds;
    }

    /**
     * Get estimated duration for task types
     */
    private function getEstimatedDuration(string $taskType): int
    {
        return match ($taskType) {
            'video_generation' => 30, // 30 minutes
            'large_batch_images' => 15, // 15 minutes
            'document_processing' => 10, // 10 minutes
            default => 20, // 20 minutes
        };
    }
}
