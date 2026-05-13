<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIVectorStore extends Model
{
    protected $table = 'ai_vector_stores';

    protected $fillable = [
        'store_id',
        'name',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(AIVectorStoreDocument::class, 'vector_store_id');
    }
}
