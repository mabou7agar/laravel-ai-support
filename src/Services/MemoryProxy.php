<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Services\Memory\MemoryManager;

/**
 * Memory proxy for fluent API
 */
class MemoryProxy
{
    protected ?string $conversationId = null;
    protected ?string $parentId = null;
    protected string $parentType = 'conversation';

    public function __construct(
        protected MemoryManager $memoryManager,
        protected ?string $driver = null
    ) {}

    /**
     * Set parent context (like Bupple's setParent)
     */
    public function setParent(string $type, string $id): self
    {
        $this->parentType = $type;
        $this->parentId = $id;
        
        if ($type === 'conversation') {
            $this->conversationId = $id;
        }
        
        return $this;
    }

    /**
     * Set conversation ID directly
     */
    public function conversation(string $conversationId): self
    {
        $this->conversationId = $conversationId;
        return $this;
    }

    /**
     * Add user message
     */
    public function addUserMessage(string $content, array $metadata = []): self
    {
        $this->memoryManager->addMessage(
            $this->getConversationId(),
            'user',
            $content,
            $metadata,
            $this->driver
        );
        
        return $this;
    }

    /**
     * Add assistant message
     */
    public function addAssistantMessage(string $content, array $metadata = []): self
    {
        $this->memoryManager->addMessage(
            $this->getConversationId(),
            'assistant',
            $content,
            $metadata,
            $this->driver
        );
        
        return $this;
    }

    /**
     * Add system message
     */
    public function addSystemMessage(string $content, array $metadata = []): self
    {
        $this->memoryManager->addMessage(
            $this->getConversationId(),
            'system',
            $content,
            $metadata,
            $this->driver
        );
        
        return $this;
    }

    /**
     * Add message with role
     */
    public function addMessage(string $role, string $content, array $metadata = []): self
    {
        $this->memoryManager->addMessage(
            $this->getConversationId(),
            $role,
            $content,
            $metadata,
            $this->driver
        );
        
        return $this;
    }

    /**
     * Get all messages
     */
    public function getMessages(): array
    {
        return $this->memoryManager->getMessages(
            $this->getConversationId(),
            $this->driver
        );
    }

    /**
     * Get conversation context
     */
    public function getContext(): array
    {
        return $this->memoryManager->getContext(
            $this->getConversationId(),
            $this->driver
        );
    }

    /**
     * Clear conversation history
     */
    public function clear(): self
    {
        $this->memoryManager->clearConversation(
            $this->getConversationId(),
            $this->driver
        );
        
        return $this;
    }

    /**
     * Delete conversation
     */
    public function delete(): bool
    {
        return $this->memoryManager->deleteConversation(
            $this->getConversationId(),
            $this->driver
        );
    }

    /**
     * Get conversation statistics
     */
    public function getStats(): array
    {
        return $this->memoryManager->getStats(
            $this->getConversationId(),
            $this->driver
        );
    }

    /**
     * Create new conversation
     */
    public function create(array $options = []): string
    {
        $conversationId = $this->memoryManager->createConversation(
            $options['user_id'] ?? null,
            $options['title'] ?? null,
            $options['system_prompt'] ?? null,
            $options['settings'] ?? [],
            $this->driver
        );
        
        $this->conversationId = $conversationId;
        return $conversationId;
    }

    /**
     * Get conversation ID with auto-creation
     */
    protected function getConversationId(): string
    {
        if (!$this->conversationId) {
            $this->conversationId = $this->create();
        }
        
        return $this->conversationId;
    }
}
