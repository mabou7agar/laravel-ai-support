<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AILearnSource extends Model
{
    protected $table = 'ai_learn_sources';

    protected $fillable = [
        'source_id',
        'source_type',
        'source',
        'adapter',
        'type',
        'title',
        'content',
        'summary',
        'metadata',
        'user_id',
        'tenant_id',
        'workspace_id',
        'session_id',
        'vector_store_id',
        'indexed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'indexed_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $source): void {
            if (!$source->source_id) {
                $source->source_id = 'learn_src_' . Str::uuid()->toString();
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(AILearnedItem::class, 'learn_source_id');
    }
}
