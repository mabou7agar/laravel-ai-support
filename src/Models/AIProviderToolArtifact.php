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
        'tool_run_id',
        'media_id',
        'provider',
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
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AIProviderToolRun::class, 'tool_run_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(AIMedia::class, 'media_id');
    }
}
