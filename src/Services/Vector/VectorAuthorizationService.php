<?php

namespace LaravelAIEngine\Services\Vector;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;

class VectorAuthorizationService
{
    /**
     * Check if user can access vector search results
     */
    public function canAccessResults(
        string $userId,
        array $results,
        string $modelClass
    ): array {
        $filtered = [];

        foreach ($results as $result) {
            if ($this->canAccessModel($userId, $result, $modelClass)) {
                $filtered[] = $result;
            }
        }

        return $filtered;
    }

    /**
     * Check if user can access a specific model
     */
    public function canAccessModel(
        string $userId,
        Model $model,
        string $modelClass
    ): bool {
        // Check if model has authorization method
        if (method_exists($model, 'canBeAccessedBy')) {
            return $model->canBeAccessedBy($userId);
        }

        // Check if model belongs to user
        if (isset($model->user_id)) {
            return $model->user_id == $userId;
        }

        // Check visibility/status fields
        if (isset($model->visibility)) {
            if ($model->visibility === 'private' && $model->user_id != $userId) {
                return false;
            }
        }

        if (isset($model->status)) {
            if (in_array($model->status, ['draft', 'private']) && $model->user_id != $userId) {
                return false;
            }
        }

        // Default: allow access (can be configured)
        return config('ai-engine.vector.authorization.default_allow', true);
    }

    /**
     * Apply authorization filters to vector search
     */
    public function applyAuthorizationFilters(
        string $userId,
        array $filters,
        string $modelClass
    ): array {
        // Add user_id filter if enabled
        if (config('ai-engine.vector.authorization.filter_by_user', false)) {
            $filters['user_id'] = $userId;
        }

        // Add visibility filter
        if (config('ai-engine.vector.authorization.filter_by_visibility', true)) {
            // Allow public content or user's own content
            $filters['_or'] = [
                ['visibility' => 'public'],
                ['user_id' => $userId],
            ];
        }

        // Add status filter
        if (config('ai-engine.vector.authorization.filter_by_status', true)) {
            // Exclude drafts unless it's user's own
            $filters['_or'] = array_merge($filters['_or'] ?? [], [
                ['status' => 'published'],
                ['user_id' => $userId],
            ]);
        }

        return $filters;
    }

    /**
     * Check if user can index a model
     */
    public function canIndex(string $userId, Model $model): bool
    {
        // Check if model has authorization method
        if (method_exists($model, 'canBeIndexedBy')) {
            return $model->canBeIndexedBy($userId);
        }

        // Check if user owns the model
        if (isset($model->user_id)) {
            return $model->user_id == $userId;
        }

        // Check if user has permission (using Laravel's Gate/Policy)
        if (class_exists('\Illuminate\Support\Facades\Gate')) {
            try {
                return \Illuminate\Support\Facades\Gate::allows('update', $model);
            } catch (\Exception $e) {
                // Gate not configured, continue
            }
        }

        // Default: allow indexing
        return config('ai-engine.vector.authorization.default_allow_indexing', true);
    }

    /**
     * Check if user can delete from index
     */
    public function canDeleteFromIndex(string $userId, Model $model): bool
    {
        // Check if model has authorization method
        if (method_exists($model, 'canBeDeletedBy')) {
            return $model->canBeDeletedBy($userId);
        }

        // Check if user owns the model
        if (isset($model->user_id)) {
            return $model->user_id == $userId;
        }

        // Check if user has permission
        if (class_exists('\Illuminate\Support\Facades\Gate')) {
            try {
                return \Illuminate\Support\Facades\Gate::allows('delete', $model);
            } catch (\Exception $e) {
                // Gate not configured, continue
            }
        }

        // Default: allow deletion
        return config('ai-engine.vector.authorization.default_allow_deletion', true);
    }

    /**
     * Get accessible collections for user
     */
    public function getAccessibleCollections(string $userId): array
    {
        // This would typically query your database for collections
        // the user has access to. For now, return all.
        return config('ai-engine.vector.authorization.accessible_collections', []);
    }

    /**
     * Check if user can access collection
     */
    public function canAccessCollection(string $userId, string $collectionName): bool
    {
        // Check if collection is restricted
        $restrictedCollections = config('ai-engine.vector.authorization.restricted_collections', []);
        
        if (in_array($collectionName, $restrictedCollections)) {
            // Check if user has permission
            $allowedUsers = config("ai-engine.vector.authorization.collection_access.{$collectionName}", []);
            return in_array($userId, $allowedUsers);
        }

        return true;
    }

    /**
     * Log authorization event
     */
    protected function logAuthorizationEvent(
        string $event,
        string $userId,
        Model $model,
        bool $allowed
    ): void {
        if (config('ai-engine.vector.authorization.log_events', false)) {
            Log::info('Vector authorization event', [
                'event' => $event,
                'user_id' => $userId,
                'model_type' => get_class($model),
                'model_id' => $model->id ?? null,
                'allowed' => $allowed,
            ]);
        }
    }

    /**
     * Apply row-level security to search results
     */
    public function applyRowLevelSecurity(
        string $userId,
        array $results,
        string $modelClass
    ): array {
        $securityRules = config('ai-engine.vector.authorization.row_level_security', []);

        if (empty($securityRules)) {
            return $results;
        }

        return array_filter($results, function ($result) use ($userId, $securityRules) {
            foreach ($securityRules as $rule) {
                if (!$this->evaluateSecurityRule($userId, $result, $rule)) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Evaluate a security rule
     */
    protected function evaluateSecurityRule(
        string $userId,
        Model $model,
        array $rule
    ): bool {
        $field = $rule['field'] ?? null;
        $operator = $rule['operator'] ?? '==';
        $value = $rule['value'] ?? null;

        if (!$field || !isset($model->$field)) {
            return true;
        }

        $fieldValue = $model->$field;

        // Replace {user_id} placeholder
        if ($value === '{user_id}') {
            $value = $userId;
        }

        return match ($operator) {
            '==' => $fieldValue == $value,
            '!=' => $fieldValue != $value,
            '>' => $fieldValue > $value,
            '<' => $fieldValue < $value,
            '>=' => $fieldValue >= $value,
            '<=' => $fieldValue <= $value,
            'in' => in_array($fieldValue, (array) $value),
            'not_in' => !in_array($fieldValue, (array) $value),
            default => true,
        };
    }
}
