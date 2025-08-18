<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'ai_messages';

    protected $fillable = [
        'message_id',
        'conversation_id',
        'role',
        'content',
        'metadata',
        'engine',
        'model',
        'tokens_used',
        'credits_used',
        'latency_ms',
        'sent_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'tokens_used' => 'integer',
        'credits_used' => 'decimal:4',
        'latency_ms' => 'decimal:2',
        'sent_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'conversation_id');
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function isSystem(): bool
    {
        return $this->role === 'system';
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function scopeInConversation($query, string $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeForConversation($query, string $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('sent_at', 'desc');
    }

    public function updateUsageStats(?int $tokensUsed = null, ?float $creditsUsed = null): void
    {
        $this->update([
            'tokens_used' => $tokensUsed,
            'credits_used' => $creditsUsed,
        ]);
    }

    public function toContextArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($message) {
            if (!$message->message_id) {
                $message->message_id = 'msg_' . uniqid() . '_' . time();
            }
            if (!$message->sent_at) {
                $message->sent_at = now();
            }
        });

        static::created(function ($message) {
            // Update conversation last activity
            if ($message->conversation) {
                $message->conversation->updateLastActivity();
            }
        });
    }
}
