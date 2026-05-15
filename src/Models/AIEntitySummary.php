<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AIEntitySummary extends Model
{
    protected $table = 'ai_entity_summaries';

    protected $fillable = [
        'summaryable_type',
        'summaryable_id',
        'locale',
        'summary',
        'source_hash',
        'policy_version',
        'generated_at',
        'expires_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function summaryable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'summaryable_type', 'summaryable_id');
    }
}

