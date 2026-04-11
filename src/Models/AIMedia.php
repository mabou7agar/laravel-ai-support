<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AIMedia extends Model
{
    protected $table = 'ai_media';

    protected $fillable = [
        'uuid',
        'model_type',
        'model_id',
        'user_id',
        'request_id',
        'provider_request_id',
        'engine',
        'ai_model',
        'content_type',
        'collection_name',
        'name',
        'file_name',
        'mime_type',
        'disk',
        'conversions_disk',
        'size',
        'path',
        'url',
        'source_url',
        'width',
        'height',
        'duration',
        'manipulations',
        'custom_properties',
        'generated_conversions',
        'responsive_images',
        'order_column',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'float',
        'manipulations' => 'array',
        'custom_properties' => 'array',
        'generated_conversions' => 'array',
        'responsive_images' => 'array',
        'order_column' => 'integer',
    ];

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
