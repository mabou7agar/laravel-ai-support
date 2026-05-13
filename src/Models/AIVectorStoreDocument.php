<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIVectorStoreDocument extends Model
{
    protected $table = 'ai_vector_store_documents';

    protected $fillable = [
        'vector_store_id',
        'document_id',
        'source',
        'disk',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function vectorStore(): BelongsTo
    {
        return $this->belongsTo(AIVectorStore::class, 'vector_store_id');
    }
}
