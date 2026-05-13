<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIProviderToolApproval extends Model
{
    protected $table = 'ai_provider_tool_approvals';

    protected $fillable = [
        'approval_key',
        'tool_run_id',
        'provider',
        'tool_name',
        'risk_level',
        'status',
        'requested_by',
        'resolved_by',
        'tool_payload',
        'metadata',
        'reason',
        'requested_at',
        'resolved_at',
    ];

    protected $casts = [
        'tool_payload' => 'array',
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AIProviderToolRun::class, 'tool_run_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AIProviderToolAuditEvent::class, 'approval_id');
    }
}
