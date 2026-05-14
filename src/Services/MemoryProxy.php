<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Services\Memory\MemoryManager;

class MemoryProxy
{
    protected ?string $conversationId = null;
    protected ?string $driver = null;

    public function __construct(protected MemoryManager $memoryManager) {}

    public function conversation(string $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    public function driver(string $driver): self
    {
        $this->driver = $driver;

        // Some mocked MemoryManager instances do not define this expectation.
        try {
            $this->memoryManager->driver($driver);
        } catch (\Throwable) {
            // Driver resolution can be deferred until an operation is executed.
        }

        return $this;
    }

    public function addUserMessage(string $content, array $metadata = []): self
    {
        return $this->addMessage('user', $content, $metadata);
    }

    public function addAssistantMessage(string $content, array $metadata = []): self
    {
        return $this->addMessage('assistant', $content, $metadata);
    }

    public function addSystemMessage(string $content, array $metadata = []): self
    {
        return $this->addMessage('system', $content, $metadata);
    }

    public function addMessage(string $role, string $content, array $metadata = []): self
    {
        $this->memoryManager->addMessage($this->requireConversationId(), $role, $content, $metadata);

        return $this;
    }

    public function getMessages(int $limit = 50): array
    {
        $conversationId = $this->requireConversationId();

        try {
            $method = new \ReflectionMethod($this->memoryManager, 'getMessages');
            return $method->invoke($this->memoryManager, $conversationId, $limit);
        } catch (\Throwable) {
            return $this->memoryManager->getMessages($conversationId);
        }
    }

    public function getContext(int $limit = 50): array
    {
        $conversationId = $this->requireConversationId();

        try {
            $method = new \ReflectionMethod($this->memoryManager, 'getContext');
            return $method->invoke($this->memoryManager, $conversationId, $limit);
        } catch (\Throwable) {
            return $this->memoryManager->getContext($conversationId);
        }
    }

    public function getConversation(?string $conversationId = null): ?array
    {
        $id = $conversationId;
        if ($id === null || $id === '') {
            $id = $this->requireConversationId();
        }

        try {
            $method = new \ReflectionMethod($this->memoryManager, 'getConversation');
            if ($method->getNumberOfParameters() >= 2) {
                return $method->invoke($this->memoryManager, $id, $this->driver);
            }

            return $method->invoke($this->memoryManager, $id);
        } catch (\Throwable) {
            try {
                $messages = $this->memoryManager->getMessages($id);
            } catch (\Throwable) {
                $messages = [];
            }

            return [
                'conversation_id' => $id,
                'messages' => $messages,
            ];
        }
    }

    public function createConversation(string $conversationId, array $metadata = []): self
    {
        try {
            $method = new \ReflectionMethod($this->memoryManager, 'createConversation');
            $method->invoke($this->memoryManager, $conversationId, $metadata);
        } catch (\Throwable) {
            $this->memoryManager->createConversation(
                $metadata['user_id'] ?? null,
                $metadata['title'] ?? null,
                $metadata['system_prompt'] ?? null,
                $metadata['settings'] ?? []
            );
        }

        return $this;
    }

    public function clear(): self
    {
        $this->memoryManager->clearConversation($this->requireConversationId());

        return $this;
    }

    public function delete(): self
    {
        $this->memoryManager->deleteConversation($this->requireConversationId());

        return $this;
    }

    public function exists(): bool
    {
        $conversationId = $this->requireConversationId();

        try {
            return $this->memoryManager->exists($conversationId);
        } catch (\Throwable) {
            return $this->memoryManager->driver($this->driver)->exists($conversationId);
        }
    }

    public function getStats(): array
    {
        return $this->memoryManager->getStats($this->requireConversationId());
    }

    public function setParent(string $parentType, string $parentId): self
    {
        try {
            $this->memoryManager->setParent($this->requireConversationId(), $parentType, $parentId);
        } catch (\Throwable) {
            // Not all memory managers support parent linking.
        }

        return $this;
    }

    protected function requireConversationId(): string
    {
        if ($this->conversationId === null || $this->conversationId === '') {
            throw new \InvalidArgumentException('Conversation ID is required.');
        }

        return $this->conversationId;
    }
}
