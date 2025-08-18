<?php

namespace LaravelAIEngine\Services\Memory\Contracts;

/**
 * Memory driver interface for different storage backends
 */
interface MemoryDriverInterface
{
    /**
     * Add message to conversation
     */
    public function addMessage(
        string $conversationId,
        string $role,
        string $content,
        array $metadata = []
    ): void;

    /**
     * Get messages from conversation
     */
    public function getMessages(string $conversationId): array;

    /**
     * Get conversation context with system prompt
     */
    public function getContext(string $conversationId): array;

    /**
     * Create new conversation
     */
    public function createConversation(
        ?string $userId = null,
        ?string $title = null,
        ?string $systemPrompt = null,
        array $settings = []
    ): string;

    /**
     * Clear conversation history
     */
    public function clearConversation(string $conversationId): void;

    /**
     * Delete conversation
     */
    public function deleteConversation(string $conversationId): bool;

    /**
     * Get conversation statistics
     */
    public function getStats(string $conversationId): array;

    /**
     * Check if conversation exists
     */
    public function exists(string $conversationId): bool;
}
