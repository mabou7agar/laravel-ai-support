<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PendingAction extends Model
{
    protected $fillable = [
        'session_id',
        'user_id',
        'action_id',
        'action_type',
        'label',
        'description',
        'params',
        'missing_fields',
        'suggested_params',
        'is_complete',
        'is_executed',
        'executor',
        'model_class',
        'node_slug',
        'executed_at',
        'expires_at',
    ];

    protected $casts = [
        'params' => 'array',
        'missing_fields' => 'array',
        'suggested_params' => 'array',
        'is_complete' => 'boolean',
        'is_executed' => 'boolean',
        'executed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get pending action for session
     */
    public static function getForSession(string $sessionId): ?self
    {
        return static::where('session_id', $sessionId)
            ->where('is_executed', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }

    /**
     * Create or update pending action
     */
    public static function createOrUpdate(string $sessionId, array $data): self
    {
        // Remove expired actions for this session first
        static::where('session_id', $sessionId)
            ->where('expires_at', '<', now())
            ->delete();

        // Get existing pending action
        $existing = static::where('session_id', $sessionId)
            ->where('is_executed', false)
            ->latest()
            ->first();

        // If action_id changed, delete old action and create new one
        if ($existing && $existing->action_id !== $data['action_id']) {
            \Log::channel('ai-engine')->info('Action changed, replacing old pending action', [
                'old_action' => $existing->label,
                'new_action' => $data['label'] ?? 'unknown',
            ]);
            $existing->delete();
            $existing = null;
        }

        if ($existing) {
            $existing->update($data);
            return $existing;
        }

        return static::create(array_merge($data, [
            'session_id' => $sessionId,
            'expires_at' => now()->addHours(24),
        ]));
    }

    /**
     * Mark as executed
     */
    public function markExecuted(): void
    {
        $this->update([
            'is_executed' => true,
            'executed_at' => now(),
        ]);
    }

    /**
     * Check if action is ready to execute
     */
    public function isReady(): bool
    {
        return $this->is_complete && 
               !$this->is_executed && 
               $this->expires_at > now() &&
               empty($this->missing_fields);
    }

    /**
     * Scope: Active (not executed, not expired)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_executed', false)
                    ->where('expires_at', '>', now());
    }

    /**
     * Scope: For user
     */
    public function scopeForUser(Builder $query, $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Clean up expired actions
     */
    public static function cleanupExpired(): int
    {
        return static::where('expires_at', '<', now())->delete();
    }
}
