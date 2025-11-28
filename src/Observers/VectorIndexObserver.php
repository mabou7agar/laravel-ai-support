<?php

namespace LaravelAIEngine\Observers;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class VectorIndexObserver
{
    protected VectorSearchService $vectorSearch;
    protected bool $queueIndexing;
    protected bool $autoIndex;
    protected bool $autoDelete;

    public function __construct(VectorSearchService $vectorSearch)
    {
        $this->vectorSearch = $vectorSearch;
        $this->queueIndexing = config('ai-engine.vector.queue.enabled', false);
        $this->autoIndex = config('ai-engine.vector.auto_index', false);
        $this->autoDelete = config('ai-engine.vector.auto_delete', true);
    }

    /**
     * Handle the Model "created" event.
     */
    public function created(Model $model): void
    {
        if (!$this->autoIndex) {
            return;
        }

        if (!$this->shouldIndex($model)) {
            return;
        }

        $this->indexModel($model);
    }

    /**
     * Handle the Model "updated" event.
     */
    public function updated(Model $model): void
    {
        if (!$this->autoIndex) {
            return;
        }

        // Check if vectorizable fields changed
        if (!$this->hasVectorizableChanges($model)) {
            return;
        }

        if (!$this->shouldIndex($model)) {
            // If model should no longer be indexed, delete it
            if ($this->autoDelete) {
                $this->deleteFromIndex($model);
            }
            return;
        }

        $this->indexModel($model);
    }

    /**
     * Handle the Model "deleted" event.
     */
    public function deleted(Model $model): void
    {
        if (!$this->autoDelete) {
            return;
        }

        $this->deleteFromIndex($model);
    }

    /**
     * Handle the Model "restored" event.
     */
    public function restored(Model $model): void
    {
        if (!$this->autoIndex) {
            return;
        }

        if ($this->shouldIndex($model)) {
            $this->indexModel($model);
        }
    }

    /**
     * Index a model
     */
    protected function indexModel(Model $model): void
    {
        try {
            if ($this->queueIndexing) {
                // Dispatch to queue
                $queueConnection = config('ai-engine.vector.queue.connection', 'redis');
                $queueName = config('ai-engine.vector.queue.name', 'vector-indexing');

                Queue::connection($queueConnection)
                    ->pushOn($queueName, new \LaravelAIEngine\Jobs\IndexModelJob($model));

                Log::info('Model queued for vector indexing', [
                    'model_type' => get_class($model),
                    'model_id' => $model->id,
                ]);
            } else {
                // Index immediately
                $this->vectorSearch->index($model);

                Log::info('Model indexed in vector database', [
                    'model_type' => get_class($model),
                    'model_id' => $model->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to index model in vector database', [
                'model_type' => get_class($model),
                'model_id' => $model->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete model from index
     */
    protected function deleteFromIndex(Model $model): void
    {
        try {
            $this->vectorSearch->deleteFromIndex($model);

            Log::info('Model deleted from vector index', [
                'model_type' => get_class($model),
                'model_id' => $model->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete model from vector index', [
                'model_type' => get_class($model),
                'model_id' => $model->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if model should be indexed
     */
    protected function shouldIndex(Model $model): bool
    {
        // Check if model has shouldBeIndexed method
        if (method_exists($model, 'shouldBeIndexed')) {
            return $model->shouldBeIndexed();
        }

        // Check if content is empty
        if (method_exists($model, 'getVectorContent')) {
            $content = $model->getVectorContent();
            if (empty(trim($content))) {
                return false;
            }
        }

        // Check status field
        if (isset($model->status) && in_array($model->status, ['draft', 'archived', 'deleted'])) {
            return false;
        }

        // Check soft deletes
        if (method_exists($model, 'trashed') && $model->trashed()) {
            return false;
        }

        return true;
    }

    /**
     * Check if vectorizable fields have changed
     */
    protected function hasVectorizableChanges(Model $model): bool
    {
        if (!$model->wasChanged()) {
            return false;
        }

        // Get vectorizable fields
        $vectorizableFields = [];
        
        if (property_exists($model, 'vectorizable')) {
            $vectorizableFields = $model->vectorizable;
        }

        // If no vectorizable fields defined, check common fields
        if (empty($vectorizableFields)) {
            $vectorizableFields = ['title', 'name', 'content', 'description', 'body', 'text'];
        }

        // Check if any vectorizable field changed
        foreach ($vectorizableFields as $field) {
            if ($model->wasChanged($field)) {
                return true;
            }
        }

        // Also check status changes
        if ($model->wasChanged('status')) {
            return true;
        }

        return false;
    }
}
