<?php

namespace LaravelAIEngine\Services\Memory\Drivers;

use LaravelAIEngine\Services\Memory\Contracts\MemoryDriverInterface;
use LaravelAIEngine\Services\ConversationManager;

/**
 * Database memory driver using existing Eloquent models
 */
class DatabaseMemoryDriver implements MemoryDriverInterface
{
    public function __construct(
        protected ConversationManager $conversationManager
    ) {}

    /**
     * Add message to conversation
     */
    public function addMessage(
        string $conversationId,
        string $role,
        string $content,
        array $metadata = []
    ): void {
        if ($role === 'user') {
            $this->conversationManager->addUserMessage($conversationId, $content, $metadata);
        } else {
            // For assistant/system messages, we need to create a mock AIResponse
            $this->conversationManager->addAssistantMessage(
                $conversationId,
                $content,
                \LaravelAIEngine\DTOs\AIResponse::success(
                    $content,
                    \LaravelAIEngine\Enums\EngineEnum::OPENAI,
                    \LaravelAIEngine\Enums\EntityEnum::GPT_4O,
                    $metadata
                )
            );
        }
    }

    /**
     * Get messages from conversation
     */
    public function getMessages(string $conversationId): array
    {
        return $this->conversationManager->getConversationContext($conversationId);
    }

    /**
     * Get conversation context with system prompt
     */
    public function getContext(string $conversationId): array
    {
        return $this->conversationManager->getConversationContext($conversationId);
    }

    /**
     * Create new conversation
     */
    public function createConversation(
        ?string $userId = null,
        ?string $title = null,
        ?string $systemPrompt = null,
        array $settings = []
    ): string {
        $conversation = $this->conversationManager->createConversation(
            userId: $userId,
            title: $title,
            systemPrompt: $systemPrompt,
            settings: $settings
        );

        return $conversation->conversation_id;
    }

    /**
     * Clear conversation history
     */
    public function clearConversation(string $conversationId): void
    {
        $this->conversationManager->clearConversationHistory($conversationId);
    }

    /**
     * Delete conversation
     */
    public function deleteConversation(string $conversationId): bool
    {
        return $this->conversationManager->deleteConversation($conversationId);
    }

    /**
     * Get conversation statistics
     */
    public function getStats(string $conversationId): array
    {
        return $this->conversationManager->getConversationStats($conversationId);
    }

    /**
     * Check if conversation exists
     */
    public function exists(string $conversationId): bool
    {
        return $this->conversationManager->getConversation($conversationId) !== null;
    }
}
