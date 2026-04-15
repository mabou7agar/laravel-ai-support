<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;

class AIReferencePack extends Model
{
    protected $table = 'ai_reference_packs';

    protected $fillable = [
        'alias',
        'name',
        'entity_type',
        'frontal_image_url',
        'frontal_provider_image_url',
        'voice_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
