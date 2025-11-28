<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Facades\Engine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationService
{
    public function __construct(
        protected ConversationManager $conversationManager
    ) {}

    /**
     * Get existing conversation or create new one
     */
    public function getOrCreateConversation(
        string $sessionId,
        ?string $userId = null,
        string $engine = 'openai',
        string $model = 'gpt-4o-mini'
    ): string {
        // Check if conversation exists by title (session ID)
        $existing = DB::table('ai_conversations')
            ->where('title', $sessionId)
            ->first();

        if ($existing) {
            return $existing->conversation_id;
        }

        // Create new conversation
        $conversation = $this->conversationManager->createConversation(
            userId: $userId,
            title: $sessionId,
            systemPrompt: 'You are a helpful AI assistant.',
            settings: [
                'engine' => $engine,
                'model' => $model,
            ]
        );

        return $conversation->conversation_id;
    }

    /**
     * Load conversation history
     */
    public function loadConversationHistory(string $conversationId, int $limit = 20): array
    {
        try {
            $conversation = Engine::memory()->getConversation($conversationId);
            
            if ($conversation && isset($conversation['messages']) && !empty($conversation['messages'])) {
                // Return last N messages
                return array_slice($conversation['messages'], -$limit);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to load conversation history: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Save messages to conversation
     */
    public function saveMessages(
        string $conversationId,
        string $userMessage,
        AIResponse $aiResponse
    ): void {
        $this->conversationManager->addUserMessage($conversationId, $userMessage);
        $this->conversationManager->addAssistantMessage(
            $conversationId,
            $aiResponse->getContent(),
            $aiResponse
        );
    }

    /**
     * Get conversation history for display
     */
    public function getConversationHistory(string $sessionId, int $limit = 50): array
    {
        // Get conversation by title
        $conversation = DB::table('ai_conversations')
            ->where('title', $sessionId)
            ->first();

        if (!$conversation) {
            return [];
        }

        try {
            $conversationData = Engine::memory()->getConversation($conversation->conversation_id);
            
            if ($conversationData && isset($conversationData['messages'])) {
                return array_slice($conversationData['messages'], -$limit);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get conversation history: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Clear conversation history
     */
    public function clearConversation(string $sessionId): bool
    {
        try {
            $conversation = DB::table('ai_conversations')
                ->where('title', $sessionId)
                ->first();

            if ($conversation) {
                $this->conversationManager->clearConversation($conversation->conversation_id);
                return true;
            }
        } catch (\Exception $e) {
            Log::error('Failed to clear conversation: ' . $e->getMessage());
        }

        return false;
    }
}
