<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Log;

/**
 * Answers questions mid-workflow without breaking the workflow
 * Uses AIEngineService and RAG directly (no ChatService dependency)
 */
class AnswerQuestionHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AIEngineService $ai,
        protected IntelligentRAGService $rag
    ) {}

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Answering question mid-workflow', [
            'workflow' => $context->currentWorkflow,
            'question' => $message,
        ]);
        
        // Use RAG to search for answer
        $ragResults = $this->rag->search(
            query: $message,
            collections: $options['ragCollections'] ?? [],
            limit: 3
        );
        
        // Build context from RAG results
        $ragContext = '';
        if (!empty($ragResults)) {
            $ragContext = "Context from knowledge base:\n";
            foreach ($ragResults as $result) {
                $ragContext .= "- {$result['content']}\n";
            }
            $ragContext .= "\n";
        }
        
        // Generate answer using AI
        $prompt = $ragContext . "User question: {$message}\n\nProvide a helpful, concise answer:";
        
        $aiResponse = $this->ai->generate(new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from($options['engine'] ?? 'openai'),
            model: EntityEnum::from($options['model'] ?? 'gpt-4o-mini'),
            maxTokens: 300,
            temperature: 0.7
        ));
        
        // Add workflow reminder
        $workflowPrompt = $this->getWorkflowPrompt($context);
        $fullMessage = $aiResponse->content . "\n\n" . $workflowPrompt;
        
        // Keep workflow active
        $response = AgentResponse::conversational(
            message: $fullMessage,
            context: $context,
            metadata: [
                'workflow_active' => true,
                'workflow_paused_for_question' => true,
                'used_rag' => !empty($ragResults),
            ]
        );
        
        $context->addAssistantMessage($fullMessage);
        
        return $response;
    }
    
    public function canHandle(string $action): bool
    {
        return $action === 'answer_and_resume_workflow';
    }
    
    protected function getWorkflowPrompt(UnifiedActionContext $context): string
    {
        $workflowName = class_basename($context->currentWorkflow);
        $askingFor = $context->get('asking_for');
        
        if ($askingFor) {
            return "Continuing with {$workflowName}... {$askingFor}?";
        }
        
        return "Continuing with {$workflowName}...";
    }
}
