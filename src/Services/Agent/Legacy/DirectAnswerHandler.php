<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

/**
 * Handles simple greetings and direct answers
 */
class DirectAnswerHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AIEngineService $ai
    ) {}

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        // Build system prompt with user context
        $systemPrompt = "You are a helpful AI assistant. Provide clear, accurate, and helpful responses.";

        // Add user context if available
        if ($context->userId) {
            $userContext = $this->getUserContext($context->userId);
            if ($userContext) {
                $systemPrompt .= "\n\n" . $userContext;
            }
        }

        // Use AI directly to avoid circular dependency with ChatService
        $request = new AIRequest(
            prompt:       $message,
            engine:       EngineEnum::from($options['engine'] ?? 'openai'),
            model:        EntityEnum::from($options['model'] ?? 'gpt-4o-mini'),
            systemPrompt: $systemPrompt,
            messages:     $context->conversationHistory ?? [],
            maxTokens:    500,
            temperature:  0.7
        );

        $aiResponse = $this->ai->generateText($request);

        return AgentResponse::conversational(
            message: $aiResponse->getContent(),
            context: $context
        );
    }

    /**
     * Get user context for personalized responses
     */
    protected function getUserContext($userId): ?string
    {
        try {
            $userModel = config('ai-engine.user_model', \App\Models\User::class);
            if (!class_exists($userModel)) {
                return null;
            }

            $user = \Illuminate\Support\Facades\Cache::remember(
                "ai_user_context_{$userId}",
                300,
                fn() => $userModel::find($userId)
            );

            if (!$user) {
                return null;
            }

            $context = "USER CONTEXT:\n";
            $context .= "- User ID: {$user->id}\n";

            if (isset($user->name)) {
                $context .= "- User's name: {$user->name}\n";
            }

            if (isset($user->email)) {
                $context .= "- Email: {$user->email}\n";
            }

            $context .= "\nIMPORTANT: Always address the user by their name when appropriate and use this context to answer personal questions.";

            return $context;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function canHandle(string $action): bool
    {
        return $action === 'answer_directly';
    }
}
