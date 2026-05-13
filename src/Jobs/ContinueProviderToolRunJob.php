<?php

declare(strict_types=1);

namespace LaravelAIEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelAIEngine\Services\JobStatusTracker;
use LaravelAIEngine\Services\ProviderTools\ProviderToolContinuationService;

class ContinueProviderToolRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(
        public string $jobId,
        public int|string $runId,
        public array $options = []
    ) {}

    public function handle(ProviderToolContinuationService $continuations, JobStatusTracker $tracker): void
    {
        $tracker->updateStatus($this->jobId, 'processing', [
            'provider_tool_run_id' => $this->runId,
            'started_at' => now()->toISOString(),
        ]);

        try {
            $run = $continuations->continueRun($this->runId, $this->options);
            $tracker->updateStatus($this->jobId, 'completed', [
                'provider_tool_run_id' => $run->uuid,
                'status' => $run->status,
                'completed_at' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            $tracker->updateStatus($this->jobId, 'failed', [
                'provider_tool_run_id' => $this->runId,
                'error' => $e->getMessage(),
                'failed_at' => now()->toISOString(),
            ]);

            throw $e;
        }
    }
}
