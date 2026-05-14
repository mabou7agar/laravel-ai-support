<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RAGExecutionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class RAGExecutionRouter
{
    public function __construct(
        protected RAGDecisionEngine $decisionEngine,
        protected ?RAGPipelineContract $pipeline = null
    ) {
    }

    public function execute(string $message, UnifiedActionContext $context, array $options): RAGExecutionResult
    {
        if ($this->shouldUsePipeline($options)) {
            return RAGExecutionResult::pipeline($this->executePipeline($message, $context, $options));
        }

        return RAGExecutionResult::decisionEngine($this->decisionEngine->process(
            $message,
            $context->sessionId,
            $context->userId,
            $context->conversationHistory ?? [],
            $options
        ));
    }

    protected function executePipeline(string $message, UnifiedActionContext $context, array $options): AgentResponse
    {
        if (!$this->pipeline instanceof RAGPipelineContract) {
            return AgentResponse::failure(
                message: 'RAG pipeline is not available.',
                context: $context
            );
        }

        return $this->pipeline->answer($message, array_merge($options, [
            'session_id' => $context->sessionId,
            'conversation_history' => $context->conversationHistory ?? [],
        ]), is_int($context->userId) || is_string($context->userId) ? $context->userId : null);
    }

    protected function shouldUsePipeline(array $options): bool
    {
        return empty($options['allow_rag_exit_to_orchestrator'])
            && empty($options['selected_entity'])
            && empty($options['selected_entity_context'])
            && ($options['preclassified_route_mode'] ?? null) !== 'structured_query';
    }
}
