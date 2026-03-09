<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;

class ActionMetric extends Model
{
    protected $table = 'ai_action_metrics';

    protected $fillable = [
        'action_id',
        'user_id',
        'success',
        'duration_ms',
    ];

    protected $casts = [
        'success' => 'boolean',
        'duration_ms' => 'integer',
    ];
}

