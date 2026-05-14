<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIProviderToolArtifact extends Model
{
    protected $table = 'ai_provider_tool_artifacts';

    protected $fillable = [
        'uuid',
        'agent_run_step_id',
        'tool_run_id',
        'owner_type',
        'owner_id',
        'media_id',
        'provider',
        'source',
        'artifact_type',
        'name',
        'mime_type',
        'source_url',
        'download_url',
        'provider_file_id',
        'provider_container_id',
        'citation_title',
        'citation_url',
        'metadata',
        'expires_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AIProviderToolRun::class, 'tool_run_id');
    }

    public function agentRunStep(): BelongsTo
    {
        return $this->belongsTo(AIAgentRunStep::class, 'agent_run_step_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(AIMedia::class, 'media_id');
    }
}
