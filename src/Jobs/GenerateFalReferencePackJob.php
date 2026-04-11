<?php

declare(strict_types=1);

namespace LaravelAIEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelAIEngine\Services\Fal\FalReferencePackGenerationService;
use LaravelAIEngine\Services\JobStatusTracker;

class GenerateFalReferencePackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(
        public string $jobId,
        public string $prompt,
        public array $options = [],
        public ?string $userId = null
    ) {}

    public function handle(FalReferencePackGenerationService $referencePackGenerationService, JobStatusTracker $jobStatusTracker): void
    {
        $existing = $jobStatusTracker->getStatus($this->jobId) ?? [
            'job_id' => $this->jobId,
            'metadata' => [],
        ];
        $metadata = is_array($existing['metadata'] ?? null) ? $existing['metadata'] : [];
        $metadata['started_at'] = now()->toISOString();

        $jobStatusTracker->updateStatus($this->jobId, 'processing', $metadata);

        try {
            $result = $referencePackGenerationService->generateAndStore(
                $this->prompt,
                $this->options,
                $this->userId,
                function (array $progress) use ($jobStatusTracker, $metadata): void {
                    $totalSteps = max(1, (int) ($progress['total_steps'] ?? 1));
                    $currentStep = max(1, (int) ($progress['step'] ?? 1));
                    $percentage = (int) floor((($currentStep - 1) / $totalSteps) * 100);

                    $jobStatusTracker->updateStatus($this->jobId, 'processing', array_merge($metadata, [
                        'current_step' => $currentStep,
                        'total_steps' => $totalSteps,
                        'progress_percentage' => $percentage,
                        'progress_message' => $progress['label'] ?? 'Generating references',
                        'current_look_index' => $progress['look_index'] ?? null,
                        'current_view' => $progress['view'] ?? null,
                    ]));
                }
            );

            $response = $result['response'];

            $jobStatusTracker->updateStatus($this->jobId, 'completed', array_merge($metadata, [
                'completed_at' => now()->toISOString(),
                'progress_percentage' => 100,
                'progress_message' => 'Reference pack generated successfully',
                'alias' => $result['alias'],
                'reference_pack' => $result['reference_pack'] ?? $result['character'] ?? null,
                'files' => $response->getFiles(),
                'usage' => $response->getUsage(),
                'response_metadata' => $response->getMetadata(),
            ]));
        } catch (\Throwable $e) {
            $jobStatusTracker->updateStatus($this->jobId, 'failed', array_merge($metadata, [
                'failed_at' => now()->toISOString(),
                'error' => $e->getMessage(),
            ]));

            throw $e;
        }
    }
}
