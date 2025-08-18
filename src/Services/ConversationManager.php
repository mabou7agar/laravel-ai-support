<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Models\Conversation;
use LaravelAIEngine\Models\Message;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use Illuminate\Support\Collection;

class ConversationManager
{
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

        // Update usage statistics
        $message->updateUsageStats([
            'tokens_used' => $response->tokensUsed,
            'credits_used' => $response->creditsUsed,
            'latency_ms' => $response->latency,
            'engine' => $response->engine->value,
            'model' => $response->model->value,
        ]);

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
