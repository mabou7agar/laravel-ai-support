<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\ConversationService;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Log;

/**
 * Handles natural conversation
 * No ChatService dependency - uses AIEngineService directly
 */
class ConversationalHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AIEngineService $ai,
        protected ConversationService $conversation
    ) {}

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Handling conversational message', [
            'session_id' => $context->sessionId,
        ]);
        // Load conversation history
        $history = $context->conversationHistory ?? [];
        // Build prompt with history
        $prompt = $this->buildConversationalPrompt($message, $history);
        // Generate response
        $aiResponse = $this->ai->generate(new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from($options['engine'] ?? 'openai'),
            model: EntityEnum::from($options['model'] ?? 'gpt-4o-mini'),
            maxTokens: 500,
            temperature: 0.8,
        ));
        $response = AgentResponse::conversational(
            message: $aiResponse->getContent(),
            context: $context
        );
        $context->addAssistantMessage($aiResponse->getContent());
        
        return $response;
    }
    
    public function canHandle(string $action): bool
    {
        return $action === 'handle_conversational';
    }
    
    protected function buildConversationalPrompt(string $message, array $history): string
    {
        $prompt = "You are a helpful AI assistant. Respond naturally and helpfully.\n\n";
        
        if (!empty($history)) {
            $prompt .= "Conversation history:\n";
            foreach (array_slice($history, -5) as $msg) {
                $role = $msg['role'] === 'user' ? 'User' : 'Assistant';
                $prompt .= "{$role}: {$msg['content']}\n";
            }
            $prompt .= "\n";
        }
        
        $prompt .= "User: {$message}\nAssistant:";
        
        return $prompt;
    }
}
