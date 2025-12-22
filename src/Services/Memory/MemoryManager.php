<?php

namespace LaravelAIEngine\Services\Memory;

use LaravelAIEngine\Services\Memory\Drivers\DatabaseMemoryDriver;
use LaravelAIEngine\Services\Memory\Drivers\RedisMemoryDriver;
use LaravelAIEngine\Services\Memory\Drivers\FileMemoryDriver;
use LaravelAIEngine\Services\Memory\Drivers\MongoMemoryDriver;
use LaravelAIEngine\Services\Memory\Contracts\MemoryDriverInterface;

/**
 * Memory Manager for handling different storage drivers
 */
class MemoryManager
{
    protected array $drivers = [];

    public function __construct()
    {
        $this->registerDefaultDrivers();
    }

    /**
     * Add message to conversation
     */
    public function addMessage(
        string $conversationId,
        string $role,
        string $content,
        array $metadata = [],
        ?string $driver = null
    ): void {
        $this->driver($driver)->addMessage($conversationId, $role, $content, $metadata);
    }

    /**
     * Get messages from conversation
     */
    public function getMessages(string $conversationId, ?string $driver = null): array
    {
        return $this->driver($driver)->getMessages($conversationId);
    }

    /**
     * Get conversation context
     */
    public function getContext(string $conversationId, ?string $driver = null): array
    {
        return $this->driver($driver)->getContext($conversationId);
    }

    /**
     * Create new conversation
     */
    public function createConversation(
        ?string $userId = null,
        ?string $title = null,
        ?string $systemPrompt = null,
        array $settings = [],
        ?string $driver = null
    ): string {
        return $this->driver($driver)->createConversation($userId, $title, $systemPrompt, $settings);
    }

    /**
     * Get conversation data
     */
    public function getConversation(string $conversationId, ?string $driver = null): ?array
    {
        return $this->driver($driver)->getConversation($conversationId);
    }

    /**
     * Clear conversation history
     */
    public function clearConversation(string $conversationId, ?string $driver = null): void
    {
        $this->driver($driver)->clearConversation($conversationId);
    }

    /**
     * Delete conversation
     */
    public function deleteConversation(string $conversationId, ?string $driver = null): bool
    {
        return $this->driver($driver)->deleteConversation($conversationId);
    }

    /**
     * Get conversation statistics
     */
    public function getStats(string $conversationId, ?string $driver = null): array
    {
        return $this->driver($driver)->getStats($conversationId);
    }

    /**
     * Get driver instance
     */
    public function driver(?string $name = null): MemoryDriverInterface
    {
        $name = $name ?? config('ai-engine.memory.default_driver', 'database');

        if (!isset($this->drivers[$name])) {
            throw new \InvalidArgumentException("Memory driver [{$name}] not found.");
        }

        return $this->drivers[$name];
    }

    /**
     * Register a memory driver
     */
    public function extend(string $name, MemoryDriverInterface $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    /**
     * Register default drivers
     */
    protected function registerDefaultDrivers(): void
    {
        $this->drivers['database'] = app(DatabaseMemoryDriver::class);
        $this->drivers['redis'] = app(RedisMemoryDriver::class);
        $this->drivers['file'] = app(FileMemoryDriver::class);
        
        // Only register MongoDB driver if both the library and driver extension are available
        if (class_exists(\MongoDB\Client::class) && extension_loaded('mongodb')) {
            try {
                $this->drivers['mongodb'] = app(MongoMemoryDriver::class);
            } catch (\Throwable $e) {
                // MongoDB driver not available, skip silently
            }
        }
    }
}
