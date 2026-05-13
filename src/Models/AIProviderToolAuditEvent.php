<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIProviderToolAuditEvent extends Model
{
    protected $table = 'ai_provider_tool_audit_events';

    protected $fillable = [
        'uuid',
        'tool_run_id',
        'approval_id',
        'event',
        'provider',
        'tool_name',
        'actor_id',
        'payload',
        'metadata',
    ];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AIProviderToolRun::class, 'tool_run_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(AIProviderToolApproval::class, 'approval_id');
    }
}
