<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LaravelAIEngine\Traits\Vectorizable;

class Transcription extends Model
{
    use Vectorizable;
    
    protected $table = 'ai_transcriptions';

    protected $fillable = [
        'transcribable_type',
        'transcribable_id',
        'content',
        'language',
        'engine',
        'model',
        'duration_seconds',
        'confidence',
        'segments',
        'metadata',
        'status',
        'error',
    ];

    protected $casts = [
        'segments' => 'array',
        'metadata' => 'array',
        'confidence' => 'decimal:4',
        'duration_seconds' => 'integer',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Get the parent transcribable model (audio/video file).
     */
    public function transcribable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope for completed transcriptions.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope for pending transcriptions.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for failed transcriptions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute(): ?string
    {
        if (!$this->duration_seconds) {
            return null;
        }

        $hours = floor($this->duration_seconds / 3600);
        $minutes = floor(($this->duration_seconds % 3600) / 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Check if transcription is searchable (for RAG).
     */
    public function isSearchable(): bool
    {
        return $this->status === self::STATUS_COMPLETED && !empty($this->content);
    }

    /**
     * Determine if this transcription should be indexed.
     * Only completed transcriptions with content should be indexed.
     */
    public function shouldBeIndexed(): bool
    {
        return $this->status === self::STATUS_COMPLETED && !empty($this->content);
    }

    /**
     * Get content for vector embedding.
     */
    public function getEmbeddingContent(): string
    {
        return $this->content ?? '';
    }

    /**
     * Get metadata for vector storage.
     */
    public function getVectorMetadata(): array
    {
        return [
            'transcribable_type' => $this->transcribable_type,
            'transcribable_id' => $this->transcribable_id,
            'language' => $this->language,
            'engine' => $this->engine,
            'duration_seconds' => $this->duration_seconds,
            'confidence' => $this->confidence,
        ];
    }
}
