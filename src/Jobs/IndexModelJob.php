<?php

namespace LaravelAIEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use Illuminate\Support\Facades\Log;

class IndexModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Model $model;
    protected ?string $userId;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(Model $model, ?string $userId = null)
    {
        $this->model = $model;
        $this->userId = $userId;
        
        // Set queue connection and name from config
        $this->onConnection(config('ai-engine.vector.queue.connection', 'redis'));
        $this->onQueue(config('ai-engine.vector.queue.name', 'vector-indexing'));
    }

    /**
     * Execute the job.
     */
    public function handle(VectorSearchService $vectorSearch): void
    {
        try {
            Log::info('Starting vector indexing job', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->id,
            ]);

            $vectorSearch->index($this->model, $this->userId);

            Log::info('Vector indexing job completed', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Vector indexing job failed', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Vector indexing job failed permanently', [
            'model_type' => get_class($this->model),
            'model_id' => $this->model->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
