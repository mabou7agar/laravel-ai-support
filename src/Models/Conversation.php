<?php

declare(strict_types=1);

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Conversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'title',
        'system_prompt',
        'metadata',
        'settings',
        'is_active',
        'last_activity_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'last_activity_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($conversation) {
            if (empty($conversation->conversation_id)) {
                $conversation->conversation_id = 'conv_' . Str::random(16);
            }
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id', 'conversation_id')
            ->orderBy('sent_at');
    }

    public function recentMessages(): HasMany
    {
        return $this->messages()->latest('sent_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id', 'conversation_id')
            ->latestOfMany('sent_at');
    }

    public function latestAssistantMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id', 'conversation_id')
            ->where('role', 'assistant')
            ->latestOfMany('sent_at');
    }

    public function firstUserMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id', 'conversation_id')
            ->where('role', 'user')
            ->oldestOfMany('sent_at');
    }

    public function getMaxMessagesAttribute(): int
    {
        return $this->settings['max_messages'] ?? 50;
    }

    public function getTemperatureAttribute(): float
    {
        return $this->settings['temperature'] ?? 0.7;
    }

    public function updateActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }

    public function addMessage(string $role, string $content, array $metadata = []): Message
    {
        $message = $this->messages()->create([
            'message_id' => 'msg_' . Str::random(16),
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
            'sent_at' => now(),
        ]);

        $this->updateActivity();
        $this->trimMessages();

        return $message;
    }

    public function getContextMessages(int $limit = null): array
    {
        $limit = $limit ?? $this->max_messages;
        
        // Get total message count
        $totalMessages = $this->messages()->count();
        
        // Calculate offset to get the last N messages in chronological order
        $offset = max(0, $totalMessages - $limit);
        
        // Get messages in chronological order (oldest to newest)
        $messages = $this->messages()
            ->orderBy('sent_at', 'asc')
            ->skip($offset)
            ->limit($limit)
            ->get();

        return $messages->map(function ($message) {
            return [
                'role' => $message->role,
                'content' => $message->content,
            ];
        })->toArray();
    }

    public function trimMessages(): void
    {
        $messageCount = $this->messages()->count();
        $maxMessages = $this->max_messages;

        if ($messageCount > $maxMessages) {
            $messagesToDelete = $messageCount - $maxMessages;
            
            $this->messages()
                ->oldest('sent_at')
                ->limit($messagesToDelete)
                ->delete();
        }
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function getContext(): array
    {
        $context = [];

        // Add system prompt if exists
        if ($this->system_prompt) {
            $context[] = [
                'role' => 'system',
                'content' => $this->system_prompt,
            ];
        }

        // Add conversation messages in chronological order
        $messages = $this->messages()
            ->orderBy('sent_at')
            ->get()
            ->map(function ($message) {
                return [
                    'role' => $message->role,
                    'content' => $message->content,
                ];
            })
            ->toArray();
        
        return array_merge($context, $messages);
    }

    public function updateLastActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }

    public function autoGenerateTitle(): void
    {
        if (!$this->getSetting('auto_title', false) || $this->title) {
            return;
        }

        $firstUserMessage = $this->messages()
            ->where('role', 'user')
            ->orderBy('sent_at')
            ->first();

        if ($firstUserMessage) {
            $title = substr($firstUserMessage->content, 0, 50);
            if (strlen($firstUserMessage->content) > 50) {
                $title .= '...';
            }
            $this->update(['title' => $title]);
        }
    }

    public function getSetting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    public function buildSummary(int $maxLength = 220): string
    {
        $metadata = (array) ($this->metadata ?? []);

        foreach (['summary', 'conversation_summary', 'chat_summary'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $this->truncatePreview($value, $maxLength);
            }
        }

        $assistantPreview = $this->latestAssistantMessage?->content;
        if (is_string($assistantPreview) && trim($assistantPreview) !== '') {
            return $this->truncatePreview($assistantPreview, $maxLength);
        }

        $userPreview = $this->firstUserMessage?->content;
        if (is_string($userPreview) && trim($userPreview) !== '') {
            return $this->truncatePreview($userPreview, $maxLength);
        }

        $latestPreview = $this->latestMessage?->content;
        if (is_string($latestPreview) && trim($latestPreview) !== '') {
            return $this->truncatePreview($latestPreview, $maxLength);
        }

        return '';
    }

    protected function truncatePreview(string $content, int $maxLength): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim(strip_tags($content))) ?? '';

        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized) <= $maxLength) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, max(0, $maxLength - 3))) . '...';
    }

}
