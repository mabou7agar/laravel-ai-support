<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AINodeRequest extends Model
{
    protected $table = 'ai_node_requests';
    
    protected $fillable = [
        'node_id',
        'request_type',
        'trace_id',
        'payload',
        'response',
        'status_code',
        'duration_ms',
        'status',
        'error_message',
        'user_agent',
        'ip_address',
    ];
    
    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];
    
    // ==================== Relationships ====================
    
    public function node(): BelongsTo
    {
        return $this->belongsTo(AINode::class, 'node_id');
    }
    
    // ==================== Scopes ====================
    
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }
    
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
    
    public function scopeOfType($query, string $type)
    {
        return $query->where('request_type', $type);
    }
    
    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }
    
    public function scopeWithTrace($query, string $traceId)
    {
        return $query->where('trace_id', $traceId);
    }
    
    public function scopeSlow($query, int $thresholdMs = 1000)
    {
        return $query->where('duration_ms', '>', $thresholdMs);
    }
    
    // ==================== Helper Methods ====================
    
    /**
     * Check if request was successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
    
    /**
     * Check if request failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
    
    /**
     * Check if request is slow
     */
    public function isSlow(int $thresholdMs = 1000): bool
    {
        return $this->duration_ms > $thresholdMs;
    }
    
    /**
     * Get performance rating
     */
    public function getPerformanceRating(): string
    {
        if (!$this->duration_ms) {
            return 'unknown';
        }
        
        return match(true) {
            $this->duration_ms < 100 => 'excellent',
            $this->duration_ms < 500 => 'good',
            $this->duration_ms < 1000 => 'fair',
            default => 'poor',
        };
    }
}
