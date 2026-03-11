<?php

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Models\Conversation;

class ConversationSessionPreviewDTO
{
    public function __construct(
        public readonly string $conversationId,
        public readonly ?string $title,
        public readonly string $summary,
        public readonly int $messageCount,
        public readonly ?array $lastMessage,
        public readonly ?string $lastActivityAt,
        public readonly ?string $createdAt,
        public readonly array $settings,
    ) {}

    public static function fromConversation(Conversation $conversation): self
    {
        $lastMessage = $conversation->latestMessage;

        return new self(
            conversationId: $conversation->conversation_id,
            title: $conversation->title,
            summary: $conversation->buildSummary(),
            messageCount: (int) ($conversation->messages_count ?? 0),
            lastMessage: $lastMessage ? [
                'role' => $lastMessage->role,
                'content' => self::truncateContent($lastMessage->content, 100),
                'sent_at' => $lastMessage->sent_at?->toISOString(),
            ] : null,
            lastActivityAt: $conversation->last_activity_at?->toISOString(),
            createdAt: $conversation->created_at?->toISOString(),
            settings: is_array($conversation->settings) ? $conversation->settings : [],
        );
    }

    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'title' => $this->title,
            'summary' => $this->summary,
            'message_count' => $this->messageCount,
            'last_message' => $this->lastMessage,
            'last_activity_at' => $this->lastActivityAt,
            'created_at' => $this->createdAt,
            'settings' => $this->settings,
        ];
    }

    protected static function truncateContent(?string $content, int $maxLength): string
    {
        $value = trim((string) $content);

        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $maxLength - 3))) . '...';
    }
}
