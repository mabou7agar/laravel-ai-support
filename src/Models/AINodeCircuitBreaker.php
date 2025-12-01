<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AINodeCircuitBreaker extends Model
{
    protected $table = 'ai_node_circuit_breakers';
    
    protected $fillable = [
        'node_id',
        'state',
        'failure_count',
        'success_count',
        'last_failure_at',
        'last_success_at',
        'opened_at',
        'next_retry_at',
    ];
    
    protected $casts = [
        'failure_count' => 'integer',
        'success_count' => 'integer',
        'last_failure_at' => 'datetime',
        'last_success_at' => 'datetime',
        'opened_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];
    
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';
    
    // ==================== Relationships ====================
    
    public function node(): BelongsTo
    {
        return $this->belongsTo(AINode::class, 'node_id');
    }
    
    // ==================== Scopes ====================
    
    public function scopeOpen($query)
    {
        return $query->where('state', self::STATE_OPEN);
    }
    
    public function scopeClosed($query)
    {
        return $query->where('state', self::STATE_CLOSED);
    }
    
    public function scopeHalfOpen($query)
    {
        return $query->where('state', self::STATE_HALF_OPEN);
    }
    
    public function scopeReadyForRetry($query)
    {
        return $query->where('state', self::STATE_OPEN)
                     ->where('next_retry_at', '<=', now());
    }
    
    // ==================== Helper Methods ====================
    
    /**
     * Check if circuit is open
     */
    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }
    
    /**
     * Check if circuit is closed
     */
    public function isClosed(): bool
    {
        return $this->state === self::STATE_CLOSED;
    }
    
    /**
     * Check if circuit is half-open
     */
    public function isHalfOpen(): bool
    {
        return $this->state === self::STATE_HALF_OPEN;
    }
    
    /**
     * Check if ready for retry
     */
    public function isReadyForRetry(): bool
    {
        return $this->isOpen() && $this->next_retry_at && $this->next_retry_at->lte(now());
    }
    
    /**
     * Get failure rate
     */
    public function getFailureRate(): float
    {
        $total = $this->failure_count + $this->success_count;
        
        if ($total === 0) {
            return 0.0;
        }
        
        return round(($this->failure_count / $total) * 100, 2);
    }
}
