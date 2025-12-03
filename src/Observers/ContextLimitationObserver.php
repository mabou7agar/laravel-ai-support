<?php

namespace LaravelAIEngine\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\RAG\ContextLimitationService;

/**
 * Context Limitation Observer
 * 
 * Automatically updates context limitations when:
 * - New records are created
 * - Records are updated
 * - Records are deleted
 * - Bulk operations occur
 */
class ContextLimitationObserver
{
    protected ContextLimitationService $limitationService;
    protected bool $enabled;

    public function __construct(ContextLimitationService $limitationService)
    {
        $this->limitationService = $limitationService;
        $this->enabled = config('ai-engine.rag.auto_update_limitations', true);
    }

    /**
     * Handle the Model "created" event
     */
    public function created(Model $model): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->updateLimitations($model, 'created');
    }

    /**
     * Handle the Model "updated" event
     */
    public function updated(Model $model): void
    {
        if (!$this->enabled) {
            return;
        }

        // Only update if significant changes
        if ($this->hasSignificantChanges($model)) {
            $this->updateLimitations($model, 'updated');
        }
    }

    /**
     * Handle the Model "deleted" event
     */
    public function deleted(Model $model): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->updateLimitations($model, 'deleted');
    }

    /**
     * Handle the Model "restored" event
     */
    public function restored(Model $model): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->updateLimitations($model, 'restored');
    }

    /**
     * Update context limitations
     *
     * @param Model $model
     * @param string $event
     * @return void
     */
    protected function updateLimitations(Model $model, string $event): void
    {
        try {
            $modelClass = get_class($model);
            
            // Invalidate cache for this model
            $this->limitationService->invalidateCache(null, $modelClass);
            
            // If model has user_id, also invalidate user-specific cache
            if (isset($model->user_id)) {
                $this->limitationService->invalidateCache((string) $model->user_id, $modelClass);
            }
            
            Log::debug('Updated context limitations', [
                'model' => $modelClass,
                'model_id' => $model->id ?? null,
                'event' => $event,
            ]);
            
        } catch (\Exception $e) {
            Log::warning('Failed to update context limitations', [
                'model' => get_class($model),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if model has significant changes
     *
     * @param Model $model
     * @return bool
     */
    protected function hasSignificantChanges(Model $model): bool
    {
        if (!$model->wasChanged()) {
            return false;
        }

        // Check if vectorizable fields changed
        if (property_exists($model, 'vectorizable')) {
            $vectorizable = $model->vectorizable ?? [];
            
            foreach ($vectorizable as $field) {
                if ($model->wasChanged($field)) {
                    return true;
                }
            }
        }

        // Check if status/visibility changed
        $significantFields = ['status', 'visibility', 'published_at', 'deleted_at'];
        
        foreach ($significantFields as $field) {
            if ($model->wasChanged($field)) {
                return true;
            }
        }

        return false;
    }
}
