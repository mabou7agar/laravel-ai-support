<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Handlers\MessageHandlerInterface;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    /** @var MessageHandlerInterface[] */
    protected array $handlers = [];

    public function __construct(
        protected MessageAnalyzer $messageAnalyzer,
        protected ContextManager $contextManager
    ) {}

    /**
     * Register a message handler
     */
    public function registerHandler(MessageHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function process(
        string $message,
        string $sessionId,
        $userId,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Agent orchestrator processing message', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'message_length' => strlen($message),
        ]);

        $context = $this->contextManager->getOrCreate($sessionId, $userId);
        $context->addUserMessage($message);
        
        // STEP 1: Analyze message to determine routing
        $analysis = $this->messageAnalyzer->analyze($message, $context);
        
        Log::channel('ai-engine')->info('Message analyzed', [
            'type' => $analysis['type'],
            'action' => $analysis['action'],
            'confidence' => $analysis['confidence'],
            'reasoning' => $analysis['reasoning'],
        ]);
        
        // STEP 2: Find and execute handler
        foreach ($this->handlers as $handler) {
            if ($handler->canHandle($analysis['action'])) {
                $response = $handler->handle($message, $context, $options);
                $this->contextManager->save($context);
                return $response;
            }
        }
        
        // Fallback: No handler found
        Log::channel('ai-engine')->warning('No handler found for action', [
            'action' => $analysis['action'],
        ]);
        
        return AgentResponse::conversational(
            message: "I'm not sure how to handle that. Can you try rephrasing?",
            context: $context
        );
    }

}
