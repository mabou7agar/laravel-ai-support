<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Models\Conversation;
use LaravelAIEngine\Models\Message;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use Illuminate\Support\Collection;

class ConversationManager
{
    protected ?IntelligentRAGService $ragService = null;
    protected bool $ragServiceResolved = false;

    public function __construct()
    {
        // RAG service is lazy-loaded to avoid circular dependency
        // ConversationManager -> IntelligentRAGService -> ConversationService -> ConversationManager
    }

    /**
     * Get RAG service (lazy loaded to avoid circular dependency)
     */
    protected function getRagService(): ?IntelligentRAGService
    {
        if (!$this->ragServiceResolved) {
            $this->ragServiceResolved = true;
            if (class_exists(IntelligentRAGService::class)) {
                $this->ragService = app(IntelligentRAGService::class);
            }
        }
        return $this->ragService;
    }
    public function createConversation(
        ?string $userId = null,
        ?string $title = null,
        ?string $systemPrompt = null,
        array $settings = []
    ): Conversation {
        return Conversation::create([
            'user_id' => $userId,
            'title' => $title,
            'system_prompt' => $systemPrompt,
            'settings' => array_merge([
                'max_messages' => 50,
                'temperature' => 0.7,
                'auto_title' => true,
            ], $settings),
            'last_activity_at' => now(),
        ]);
    }

    public function getConversation(string $conversationId): ?Conversation
    {
        return Conversation::where('conversation_id', $conversationId)
            ->active()
            ->first();
    }

    public function getUserConversations(string $userId, int $limit = 20): Collection
    {
        return Conversation::forUser($userId)
            ->active()
            ->orderBy('last_activity_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function addUserMessage(
        string $conversationId,
        string $content,
        array $metadata = []
    ): Message {
        $conversation = $this->getConversation($conversationId);

        if (!$conversation) {
            throw new \InvalidArgumentException("Conversation not found: {$conversationId}");
        }

        return $conversation->addMessage('user', $content, $metadata);
    }

    public function addAssistantMessage(
        string $conversationId,
        string $content,
        AIResponse $response
    ): Message {
        $conversation = $this->getConversation($conversationId);

        if (!$conversation) {
            throw new \InvalidArgumentException("Conversation not found: {$conversationId}");
        }

        $message = $conversation->addMessage('assistant', $content, [
            'engine' => $response->engine->value,
            'model' => $response->model->value,
            'metadata' => $response->metadata,
            'finish_reason' => $response->finishReason,
        ]);

        // Update usage statistics with credits
        $usage = $response->getUsage();
        $creditsUsed = $response->getCreditsUsed(); // Get credits from response

        $message->updateUsageStats(
            $usage['total_tokens'] ?? null,
            $creditsUsed
        );

        // Auto-generate title if this is the first assistant message
        if ($conversation->settings['auto_title'] ?? true) {
            $this->maybeGenerateTitle($conversation);
        }

        return $message;
    }

    public function getConversationContext(
        string $conversationId,
        int $maxMessages = null
    ): array {
        $conversation = $this->getConversation($conversationId);

        if (!$conversation) {
            return [];
        }

        $context = [];

        // Add system prompt if present
        if ($conversation->system_prompt) {
            $context[] = [
                'role' => 'system',
                'content' => $conversation->system_prompt,
            ];
        }

        // Add conversation messages
        $messages = $conversation->getContextMessages($maxMessages);
        $context = array_merge($context, $messages);

        return $context;
    }

    public function enhanceRequestWithContext(
        AIRequest $request,
        string $conversationId
    ): AIRequest {
        $context = $this->getConversationContext($conversationId);

        // Add user message to context
        $context[] = [
            'role' => 'user',
            'content' => $request->prompt,
        ];

        // Create new request with conversation context
        return new AIRequest(
            prompt: $this->formatContextForEngine($context, $request->engine),
            engine: $request->engine,
            model: $request->model,
            parameters: array_merge($request->parameters, [
                'conversation_id' => $conversationId,
                'messages' => $context,
            ]),
            userId: $request->userId,
            metadata: array_merge($request->metadata, [
                'conversation_id' => $conversationId,
                'has_context' => count($context) > 1,
            ])
        );
    }

    public function deleteConversation(string $conversationId): bool
    {
        $conversation = $this->getConversation($conversationId);

        if (!$conversation) {
            return false;
        }

        // Soft delete by marking as inactive
        return $conversation->update(['is_active' => false]);
    }

    public function clearConversationHistory(string $conversationId): bool
    {
        $conversation = $this->getConversation($conversationId);

        if (!$conversation) {
            return false;
        }

        $conversation->messages()->delete();
        $conversation->updateActivity();

        return true;
    }

    public function updateConversationSettings(
        string $conversationId,
        array $settings
    ): bool {
        $conversation = $this->getConversation($conversationId);

        if (!$conversation) {
            return false;
        }

        $currentSettings = $conversation->settings ?? [];
        $newSettings = array_merge($currentSettings, $settings);

        return $conversation->update(['settings' => $newSettings]);
    }

    protected function formatContextForEngine(array $context, $engine): string
    {
        // For engines that support message arrays (like OpenAI), return as-is
        // For engines that need a single prompt, format the conversation

        if (in_array($engine->value, ['openai', 'anthropic', 'gemini'])) {
            // These engines support message arrays
            return json_encode($context);
        }

        // For other engines, format as a single prompt
        $formatted = '';
        foreach ($context as $message) {
            $role = ucfirst($message['role']);
            $formatted .= "{$role}: {$message['content']}\n\n";
        }

        return trim($formatted);
    }

    protected function maybeGenerateTitle(Conversation $conversation): void
    {
        // Only generate title if not already set and we have at least 2 messages
        if ($conversation->title || $conversation->messages()->count() < 2) {
            return;
        }

        // Get first user message for title generation
        $firstMessage = $conversation->messages()
            ->where('role', 'user')
            ->first();

        if ($firstMessage) {
            // Generate a simple title from the first message
            $title = $this->generateTitleFromContent($firstMessage->content);
            $conversation->update(['title' => $title]);
        }
    }

    protected function generateTitleFromContent(string $content): string
    {
        // Simple title generation - take first 50 characters
        $title = trim(substr($content, 0, 50));

        if (strlen($content) > 50) {
            $title .= '...';
        }

        return $title ?: 'New Conversation';
    }

    /**
     * Chat with RAG (Retrieval Augmented Generation)
     */
    public function chatWithRAG(
        string $conversationId,
        string $query,
        string $modelClass,
        array $options = []
    ): array {
        $ragService = $this->getRagService();
        if (!$ragService) {
            throw new \RuntimeException('RAG service is not available');
        }

        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \InvalidArgumentException("Conversation not found: {$conversationId}");
        }

        // Add user message
        $this->addUserMessage($conversationId, $query);

        // Get conversation history
        $conversationHistory = $this->getConversationContext($conversationId);

        // Get RAG response using IntelligentRAGService
        // Use intelligent: false to always search (like old VectorRAGBridge behavior)
        $response = $ragService->processMessage(
            $query,
            $conversationId,
            [$modelClass],
            $conversationHistory,
            array_merge($options, ['intelligent' => false]),
            $conversation->user_id
        );

        // Add assistant message with sources
        $metadata = $response->getMetadata();
        $conversation->addMessage('assistant', $response->getContent(), [
            'sources' => $metadata['sources'] ?? [],
            'context_count' => count($metadata['sources'] ?? []),
            'rag_enabled' => true,
        ]);

        $conversation->touch('last_activity_at');

        return [
            'response' => $response->content,
            'sources' => $metadata['sources'] ?? [],
            'context_count' => count($metadata['sources'] ?? []),
            'query' => $query,
        ];
    }

    /**
     * Stream chat with RAG
     * Note: Streaming is handled by the AI engine, this method processes and stores the result
     */
    public function streamChatWithRAG(
        string $conversationId,
        string $query,
        string $modelClass,
        callable $callback,
        array $options = []
    ): array {
        $ragService = $this->getRagService();
        if (!$ragService) {
            throw new \RuntimeException('RAG service is not available');
        }

        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \InvalidArgumentException("Conversation not found: {$conversationId}");
        }

        // Add user message
        $this->addUserMessage($conversationId, $query);

        // Get conversation history
        $conversationHistory = $this->getConversationContext($conversationId);

        // Get RAG response (streaming handled at AI engine level)
        $response = $ragService->processMessage(
            $query,
            $conversationId,
            [$modelClass],
            $conversationHistory,
            array_merge($options, ['intelligent' => false, 'stream_callback' => $callback]),
            $conversation->user_id
        );

        // Call callback with final response
        $callback($response->getContent(), true);

        // Add assistant message with sources
        $metadata = $response->getMetadata();
        $conversation->addMessage('assistant', $response->getContent(), [
            'sources' => $metadata['sources'] ?? [],
            'context_count' => count($metadata['sources'] ?? []),
            'rag_enabled' => true,
        ]);

        $conversation->touch('last_activity_at');

        return [
            'response' => $response->getContent(),
            'sources' => $metadata['sources'] ?? [],
            'context_count' => count($metadata['sources'] ?? []),
            'query' => $query,
        ];
    }

    /**
     * Enable RAG for conversation
     */
    public function enableRAG(string $conversationId, string $modelClass, array $options = []): void
    {
        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \InvalidArgumentException("Conversation not found: {$conversationId}");
        }

        $settings = $conversation->settings ?? [];
        $settings['rag_enabled'] = true;
        $settings['rag_model_class'] = $modelClass;
        $settings['rag_options'] = $options;

        $conversation->update(['settings' => $settings]);
    }

    /**
     * Disable RAG for conversation
     */
    public function disableRAG(string $conversationId): void
    {
        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \InvalidArgumentException("Conversation not found: {$conversationId}");
        }

        $settings = $conversation->settings ?? [];
        $settings['rag_enabled'] = false;

        $conversation->update(['settings' => $settings]);
    }

    public function getConversationStats(string $conversationId): array
    {
        $conversation = $this->getConversation($conversationId);

        if (!$conversation) {
            return [];
        }

        $messages = $conversation->messages();

        return [
            'total_messages' => $messages->count(),
            'user_messages' => $messages->where('role', 'user')->count(),
            'assistant_messages' => $messages->where('role', 'assistant')->count(),
            'total_tokens' => $messages->sum('tokens_used'),
            'total_credits' => $messages->sum('credits_used'),
            'created_at' => $conversation->created_at,
            'last_activity' => $conversation->last_activity_at,
        ];
    }
}
