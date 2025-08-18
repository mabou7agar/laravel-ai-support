<?php

namespace LaravelAIEngine\Services\Memory\Drivers;

use LaravelAIEngine\Services\Memory\Contracts\MemoryDriverInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * File memory driver for simple file-based conversation storage
 */
class FileMemoryDriver implements MemoryDriverInterface
{
    protected string $storagePath;

    public function __construct()
    {
        $this->storagePath = config('ai-engine.memory.file.path', storage_path('ai-engine/conversations'));
        
        // Ensure storage directory exists
        if (!File::exists($this->storagePath)) {
            File::makeDirectory($this->storagePath, 0755, true);
        }
    }

    /**
     * Add message to conversation
     */
    public function addMessage(
        string $conversationId,
        string $role,
        string $content,
        array $metadata = []
    ): void {
        $conversation = $this->loadConversation($conversationId);
        
        $message = [
            'id' => 'msg_' . Str::random(16),
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
            'sent_at' => now()->toISOString(),
        ];

        $conversation['messages'][] = $message;
        $conversation['last_activity_at'] = now()->toISOString();

        // Trim messages if needed
        $this->trimMessages($conversation);
        
        $this->saveConversation($conversationId, $conversation);
    }

    /**
     * Get messages from conversation
     */
    public function getMessages(string $conversationId): array
    {
        $conversation = $this->loadConversation($conversationId);
        
        return array_map(function ($message) {
            return [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }, $conversation['messages'] ?? []);
    }

    /**
     * Get conversation context with system prompt
     */
    public function getContext(string $conversationId): array
    {
        $conversation = $this->loadConversation($conversationId);
        $context = [];

        // Add system prompt if exists
        if (!empty($conversation['system_prompt'])) {
            $context[] = [
                'role' => 'system',
                'content' => $conversation['system_prompt'],
            ];
        }

        // Add conversation messages
        $messages = $this->getMessages($conversationId);
        
        return array_merge($context, $messages);
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
        $conversationId = 'conv_' . Str::random(16);
        
        $conversation = [
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'title' => $title,
            'system_prompt' => $systemPrompt,
            'settings' => $settings,
            'is_active' => true,
            'messages' => [],
            'created_at' => now()->toISOString(),
            'last_activity_at' => now()->toISOString(),
        ];

        $this->saveConversation($conversationId, $conversation);

        return $conversationId;
    }

    /**
     * Clear conversation history
     */
    public function clearConversation(string $conversationId): void
    {
        $conversation = $this->loadConversation($conversationId);
        $conversation['messages'] = [];
        $conversation['last_activity_at'] = now()->toISOString();
        
        $this->saveConversation($conversationId, $conversation);
    }

    /**
     * Delete conversation
     */
    public function deleteConversation(string $conversationId): bool
    {
        $filePath = $this->getConversationPath($conversationId);
        
        if (File::exists($filePath)) {
            return File::delete($filePath);
        }

        return false;
    }

    /**
     * Get conversation statistics
     */
    public function getStats(string $conversationId): array
    {
        $conversation = $this->loadConversation($conversationId);
        $messages = $conversation['messages'] ?? [];
        
        $userMessages = count(array_filter($messages, fn($m) => $m['role'] === 'user'));
        $assistantMessages = count(array_filter($messages, fn($m) => $m['role'] === 'assistant'));

        return [
            'total_messages' => count($messages),
            'user_messages' => $userMessages,
            'assistant_messages' => $assistantMessages,
            'created_at' => $conversation['created_at'] ?? null,
            'last_activity' => $conversation['last_activity_at'] ?? null,
        ];
    }

    /**
     * Check if conversation exists
     */
    public function exists(string $conversationId): bool
    {
        return File::exists($this->getConversationPath($conversationId));
    }

    /**
     * Load conversation from file
     */
    protected function loadConversation(string $conversationId): array
    {
        $filePath = $this->getConversationPath($conversationId);
        
        if (!File::exists($filePath)) {
            return [
                'conversation_id' => $conversationId,
                'messages' => [],
                'created_at' => now()->toISOString(),
                'last_activity_at' => now()->toISOString(),
            ];
        }

        $content = File::get($filePath);
        return json_decode($content, true) ?: [];
    }

    /**
     * Save conversation to file
     */
    protected function saveConversation(string $conversationId, array $conversation): void
    {
        $filePath = $this->getConversationPath($conversationId);
        $content = json_encode($conversation, JSON_PRETTY_PRINT);
        
        File::put($filePath, $content);
    }

    /**
     * Get file path for conversation
     */
    protected function getConversationPath(string $conversationId): string
    {
        return $this->storagePath . '/' . $conversationId . '.json';
    }

    /**
     * Trim messages to max limit
     */
    protected function trimMessages(array &$conversation): void
    {
        $maxMessages = $conversation['settings']['max_messages'] ?? config('ai-engine.memory.file.max_messages', 100);
        
        if (count($conversation['messages']) > $maxMessages) {
            $conversation['messages'] = array_slice($conversation['messages'], -$maxMessages);
        }
    }
}
