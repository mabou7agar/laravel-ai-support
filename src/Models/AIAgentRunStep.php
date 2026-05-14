<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIAgentRunStep extends Model
{
    protected $table = 'ai_agent_run_steps';

    protected $fillable = [
        'uuid',
        'run_id',
        'sequence',
        'step_key',
        'type',
        'status',
        'action',
        'source',
        'provider_tool_run_id',
        'input',
        'output',
        'routing_decision',
        'routing_trace',
        'approvals',
        'artifacts',
        'metadata',
        'error',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'input' => 'array',
        'output' => 'array',
        'routing_decision' => 'array',
        'routing_trace' => 'array',
        'approvals' => 'array',
        'artifacts' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AIAgentRun::class, 'run_id');
    }

    public function providerToolRun(): BelongsTo
    {
        return $this->belongsTo(AIProviderToolRun::class, 'provider_tool_run_id');
    }

    public function linkedProviderToolRuns(): HasMany
    {
        return $this->hasMany(AIProviderToolRun::class, 'agent_run_step_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(AIProviderToolApproval::class, 'agent_run_step_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(AIProviderToolArtifact::class, 'agent_run_step_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AIProviderToolAuditEvent::class, 'agent_run_step_id');
    }
}
