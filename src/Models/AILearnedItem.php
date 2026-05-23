<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AILearnedItem extends Model
{
    protected $table = 'ai_learned_items';

    protected $fillable = [
        'item_id',
        'learn_source_id',
        'kind',
        'title',
        'content',
        'metadata',
        'confidence',
        'position',
        'user_id',
        'tenant_id',
        'workspace_id',
        'session_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'confidence' => 'float',
        'position' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $item): void {
            if (!$item->item_id) {
                $item->item_id = 'learn_item_' . Str::uuid()->toString();
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(AILearnSource::class, 'learn_source_id');
    }
}
