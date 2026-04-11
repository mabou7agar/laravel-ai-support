<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Fal;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Jobs\GenerateFalReferencePackJob;
use LaravelAIEngine\Services\JobStatusTracker;

class FalAsyncReferencePackGenerationService
{
    public function __construct(
        private FalReferencePackGenerationService $referencePackGenerationService,
        private JobStatusTracker $jobStatusTracker
    ) {}

    public function submit(string $prompt, array $options = [], ?string $userId = null): array
    {
        $workflow = $this->referencePackGenerationService->prepareWorkflow($prompt, $options, $userId);
        $jobId = (string) Str::uuid();

        $metadata = [
            'type' => 'fal_reference_pack',
            'prompt' => $prompt,
            'user_id' => $userId,
            'options' => $options,
            'workflow' => $workflow,
            'total_steps' => count($workflow),
        ];

        $this->jobStatusTracker->updateStatus($jobId, 'queued', $metadata);

        Queue::push(
            (new GenerateFalReferencePackJob($jobId, $prompt, $options, $userId))
                ->onQueue((string) config('ai-engine.engines.fal_ai.reference_pack.queue', 'ai-media'))
        );

        return [
            'job_id' => $jobId,
            'status' => $this->getStatus($jobId),
        ];
    }

    public function getStatus(string $jobId): ?array
    {
        return $this->jobStatusTracker->getStatus($jobId);
    }

    public function waitForCompletion(string $jobId, int $timeoutSeconds = 900, int $pollIntervalSeconds = 5): array
    {
        $deadline = time() + max(1, $timeoutSeconds);
        $interval = max(1, $pollIntervalSeconds);

        do {
            $status = $this->getStatus($jobId);
            if ($status === null) {
                throw new AIEngineException("Reference pack generation job [{$jobId}] was not found.");
            }

            if (in_array($status['status'], ['completed', 'failed', 'cancelled'], true)) {
                return $status;
            }

            sleep($interval);
        } while (time() < $deadline);

        throw new AIEngineException("Reference pack generation job [{$jobId}] did not complete within {$timeoutSeconds} seconds.");
    }
}
