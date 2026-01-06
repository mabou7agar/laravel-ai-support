<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Models\PendingAction;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use Illuminate\Support\Facades\Log;

class PendingActionService
{
    /**
     * Store pending action in database
     */
    public function store(string $sessionId, InteractiveAction $action, ?int $userId = null): PendingAction
    {
        $data = [
            'user_id' => $userId,
            'action_id' => $action->id,
            'action_type' => $action->type->value,
            'label' => $action->label,
            'description' => $action->description,
            'params' => $action->data['params'] ?? [],
            'missing_fields' => $action->data['missing_fields'] ?? [],
            'suggested_params' => $action->data['suggested_params'] ?? [],
            'is_complete' => empty($action->data['missing_fields'] ?? []),
            'executor' => $action->data['executor'] ?? null,
            'model_class' => $action->data['model_class'] ?? null,
            'node_slug' => $action->data['node_slug'] ?? null,
            'ai_config' => $action->data['ai_config'] ?? null, // Store AI config for remote models
        ];

        Log::channel('ai-engine')->info('Storing pending action in database', [
            'session_id' => $sessionId,
            'action_id' => $action->id,
            'label' => $action->label,
            'is_complete' => $data['is_complete'],
            'executor' => $data['executor'],
            'node_slug' => $data['node_slug'],
            'model_class' => $data['model_class'],
        ]);

        return PendingAction::createOrUpdate($sessionId, $data);
    }

    /**
     * Get pending action for session
     */
    public function get(string $sessionId): ?InteractiveAction
    {
        $pendingAction = PendingAction::getForSession($sessionId);

        if (!$pendingAction) {
            return null;
        }

        Log::channel('ai-engine')->info('Retrieved pending action from database', [
            'session_id' => $sessionId,
            'action_id' => $pendingAction->action_id,
            'label' => $pendingAction->label,
            'is_complete' => $pendingAction->is_complete,
        ]);

        return new InteractiveAction(
            id: $pendingAction->action_id,
            type: ActionTypeEnum::from($pendingAction->action_type),
            label: $pendingAction->label,
            description: $pendingAction->description ?? '',
            data: [
                'params' => $pendingAction->params,
                'missing_fields' => $pendingAction->missing_fields ?? [],
                'suggested_params' => $pendingAction->suggested_params ?? [],
                'ready_to_execute' => $pendingAction->isReady(),
                'executor' => $pendingAction->executor,
                'model_class' => $pendingAction->model_class,
                'node_slug' => $pendingAction->node_slug,
                'ai_config' => $pendingAction->ai_config ?? null, // Include AI config for remote models
            ]
        );
    }

    /**
     * Update pending action parameters
     */
    public function updateParams(string $sessionId, array $newParams): ?PendingAction
    {
        $pendingAction = PendingAction::getForSession($sessionId);

        if (!$pendingAction) {
            return null;
        }

        $mergedParams = array_merge($pendingAction->params, $newParams);
        
        // Recalculate missing fields based on new params
        $oldMissingFields = $pendingAction->missing_fields ?? [];
        $stillMissing = [];
        
        foreach ($oldMissingFields as $field) {
            if (empty($mergedParams[$field])) {
                $stillMissing[] = $field;
            }
        }
        
        $pendingAction->update([
            'params' => $mergedParams,
            'missing_fields' => $stillMissing,
            'is_complete' => empty($stillMissing),
        ]);

        Log::channel('ai-engine')->info('Updated pending action parameters', [
            'session_id' => $sessionId,
            'new_params' => array_keys($newParams),
            'old_missing' => $oldMissingFields,
            'still_missing' => $stillMissing,
            'is_complete' => $pendingAction->is_complete,
        ]);

        return $pendingAction;
    }

    /**
     * Mark action as executed
     */
    public function markExecuted(string $sessionId): void
    {
        $pendingAction = PendingAction::getForSession($sessionId);

        if ($pendingAction) {
            $pendingAction->markExecuted();

            Log::channel('ai-engine')->info('Marked pending action as executed', [
                'session_id' => $sessionId,
                'action_id' => $pendingAction->action_id,
            ]);
        }
    }

    /**
     * Check if session has pending action
     */
    public function has(string $sessionId): bool
    {
        return PendingAction::where('session_id', $sessionId)
            ->active()
            ->exists();
    }

    /**
     * Delete pending action
     */
    public function delete(string $sessionId): void
    {
        PendingAction::where('session_id', $sessionId)
            ->where('is_executed', false)
            ->delete();

        Log::channel('ai-engine')->info('Deleted pending action', [
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Clean up expired actions
     */
    public function cleanupExpired(): int
    {
        $count = PendingAction::cleanupExpired();

        if ($count > 0) {
            Log::channel('ai-engine')->info('Cleaned up expired pending actions', [
                'count' => $count,
            ]);
        }

        return $count;
    }
}
