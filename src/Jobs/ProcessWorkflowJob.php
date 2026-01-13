<?php

namespace LaravelAIEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\ChatService;

class ProcessWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes max

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $jobId,
        public string $message,
        public string $sessionId,
        public string $userId,
        public string $engine = 'openai',
        public string $model = 'gpt-4o-mini',
        public bool $useMemory = true,
        public bool $useActions = true,
        public bool $useIntelligentRAG = true,
        public array $ragCollections = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ChatService $chatService): void
    {
        try {
            // Update status: started
            $this->updateStatus('processing', 0, 'Starting workflow...');

            Log::channel('ai-engine')->info('ProcessWorkflowJob: Started', [
                'job_id' => $this->jobId,
                'session_id' => $this->sessionId,
                'message' => substr($this->message, 0, 100),
            ]);

            // Process the message
            $response = $chatService->processMessage(
                message: $this->message,
                sessionId: $this->sessionId,
                engine: $this->engine,
                model: $this->model,
                useMemory: $this->useMemory,
                useActions: $this->useActions,
                useIntelligentRAG: $this->useIntelligentRAG,
                ragCollections: $this->ragCollections,
                userId: $this->userId
            );

            // Update status: completed
            $this->updateStatus('completed', 100, 'Workflow completed', [
                'response' => $response->getContent(),
                'metadata' => $response->getMetadata(),
                'actions' => $response->getMetadata()['actions'] ?? [],
                'sources' => $response->getMetadata()['sources'] ?? [],
            ]);

            Log::channel('ai-engine')->info('ProcessWorkflowJob: Completed', [
                'job_id' => $this->jobId,
                'response_length' => strlen($response->getContent()),
            ]);

        } catch (\Exception $e) {
            // Update status: failed
            $this->updateStatus('failed', 0, 'Workflow failed', [
                'error' => $e->getMessage(),
            ]);

            Log::channel('ai-engine')->error('ProcessWorkflowJob: Failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Update workflow status in cache
     */
    protected function updateStatus(string $status, int $progress, string $message, array $data = []): void
    {
        $statusData = [
            'status' => $status,
            'progress' => $progress,
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ];

        if (!empty($data)) {
            $statusData = array_merge($statusData, $data);
        }

        // Store in cache for 10 minutes
        Cache::put("workflow:{$this->jobId}", $statusData, 600);
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        $this->updateStatus('failed', 0, 'Job failed: ' . $exception->getMessage(), [
            'error' => $exception->getMessage(),
        ]);
    }
}
