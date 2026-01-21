<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Handles simple greetings and direct answers
 */
class DirectAnswerHandler implements MessageHandlerInterface
{
    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        // Simple pattern matching for common greetings
        $responses = [
            '/^(hi|hello|hey)/i' => 'Hello! How can I help you today?',
            '/^(thanks|thank you)/i' => 'You\'re welcome! Is there anything else I can help with?',
            '/^(bye|goodbye)/i' => 'Goodbye! Feel free to come back anytime.',
        ];
        
        foreach ($responses as $pattern => $response) {
            if (preg_match($pattern, trim($message))) {
                return AgentResponse::conversational(
                    message: $response,
                    context: $context
                );
            }
        }
        
        // Fallback
        return AgentResponse::conversational(
            message: 'How can I help you?',
            context: $context
        );
    }
    
    public function canHandle(string $action): bool
    {
        return $action === 'answer_directly';
    }
}
