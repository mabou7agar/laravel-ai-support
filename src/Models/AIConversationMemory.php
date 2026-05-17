<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AIConversationMemory extends Model
{
    protected $table = 'ai_conversation_memories';

    protected $fillable = [
        'memory_id',
        'namespace',
        'key',
        'value',
        'summary',
        'metadata',
        'user_id',
        'tenant_id',
        'workspace_id',
        'session_id',
        'confidence',
        'last_seen_at',
        'expires_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'confidence' => 'float',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $memory): void {
            if (!$memory->memory_id) {
                $memory->memory_id = 'mem_' . Str::uuid()->toString();
            }
        });
    }
}
