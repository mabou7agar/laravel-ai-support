<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIProviderToolRun extends Model
{
    protected $table = 'ai_provider_tool_runs';

    protected $fillable = [
        'uuid',
        'agent_run_id',
        'agent_run_step_id',
        'provider',
        'engine',
        'ai_model',
        'status',
        'request_id',
        'provider_request_id',
        'conversation_id',
        'user_id',
        'tool_names',
        'request_payload',
        'response_payload',
        'continuation_payload',
        'metadata',
        'error',
        'started_at',
        'awaiting_approval_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'tool_names' => 'array',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'continuation_payload' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'awaiting_approval_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function approvals(): HasMany
    {
        return $this->hasMany(AIProviderToolApproval::class, 'tool_run_id');
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AIAgentRun::class, 'agent_run_id');
    }

    public function agentRunStep(): BelongsTo
    {
        return $this->belongsTo(AIAgentRunStep::class, 'agent_run_step_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(AIProviderToolArtifact::class, 'tool_run_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AIProviderToolAuditEvent::class, 'tool_run_id');
    }
}
