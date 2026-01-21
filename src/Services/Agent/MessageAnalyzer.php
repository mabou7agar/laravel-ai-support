<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\IntentAnalysisService;
use Illuminate\Support\Facades\Log;

/**
 * Intelligent message analyzer that determines routing
 * Replaces ComplexityAnalyzer with smarter, context-aware analysis
 */
class MessageAnalyzer
{
    public function __construct(
        protected IntentAnalysisService $intentAnalysis
    ) {}

    /**
     * Analyze message to determine routing (language-agnostic)
     */
    public function analyze(string $message, UnifiedActionContext $context): array
    {
        // PRIORITY 1: Check active workflow context
        if ($context->currentWorkflow) {
            return $this->analyzeInWorkflowContext($message, $context);
        }

        // PRIORITY 2: Check if simple question or personal question
        if ($this->isSimpleQuestion($message) || $this->isPersonalQuestion($message)) {
            return [
                'type' => 'simple_answer',
                'action' => 'answer_directly',
                'confidence' => 0.9,
                'reasoning' => 'Simple or personal question - use conversational handler with user context'
            ];
        }

        // PRIORITY 3: Check if RAG query
        if ($this->requiresKnowledgeBase($message)) {
            return [
                'type' => 'rag_query',
                'action' => 'search_knowledge',
                'confidence' => 0.85,
                'reasoning' => 'Question requires knowledge base search'
            ];
        }

        // PRIORITY 4: Check if workflow/action request
        return $this->analyzeForWorkflow($message, $context);
    }

    /**
     * Analyze message in workflow context (the core intelligence)
     */
    protected function analyzeInWorkflowContext(string $message, UnifiedActionContext $context): array
    {
        $messageLength = mb_strlen(trim($message));
        $askingFor = $context->get('asking_for');
        $awaitingConfirmation = $context->get('awaiting_confirmation');

        // Case 1: Awaiting confirmation
        if ($awaitingConfirmation) {
            // Very short messages = confirmation/rejection
            if ($messageLength <= 10) {
                return [
                    'type' => 'workflow_continuation',
                    'action' => 'continue_workflow',
                    'confidence' => 0.95,
                    'reasoning' => 'Short response to confirmation request'
                ];
            }

            // Longer messages = providing corrections
            return [
                'type' => 'workflow_continuation',
                'action' => 'continue_workflow',
                'confidence' => 0.9,
                'reasoning' => 'Providing corrections or changes'
            ];
        }

        // Case 2: We asked for specific field
        if ($askingFor) {
            // Check if message looks like an answer
            if ($this->looksLikeAnswer($message, $askingFor)) {
                return [
                    'type' => 'workflow_continuation',
                    'action' => 'continue_workflow',
                    'confidence' => 0.95,
                    'reasoning' => "Answering question about {$askingFor}"
                ];
            }
        }

        // Case 3: Check if user is asking a question (not answering)
        if ($this->looksLikeQuestion($message)) {
            return [
                'type' => 'normal_question',
                'action' => 'answer_and_resume_workflow',
                'confidence' => 0.85,
                'workflow_to_resume' => $context->currentWorkflow,
                'reasoning' => 'User asking question mid-workflow'
            ];
        }

        // Case 4: Check for sub-workflow request
        if ($this->isSubWorkflowRequest($message)) {
            return [
                'type' => 'sub_workflow',
                'action' => 'start_sub_workflow',
                'parent_workflow' => $context->currentWorkflow,
                'confidence' => 0.9,
                'reasoning' => 'User requesting to create dependency first'
            ];
        }

        // Case 5: Check for cancellation
        if ($this->isCancellation($message)) {
            return [
                'type' => 'cancel',
                'action' => 'cancel_workflow',
                'confidence' => 0.95,
                'reasoning' => 'User canceling workflow'
            ];
        }

        // Default: Assume workflow continuation
        return [
            'type' => 'workflow_continuation',
            'action' => 'continue_workflow',
            'confidence' => 0.7,
            'reasoning' => 'Default to workflow continuation'
        ];
    }

    /**
     * Check if message looks like an answer (not a question)
     */
    protected function looksLikeAnswer(string $message, ?string $fieldName): bool
    {
        $trimmed = trim($message);
        
        // Very short messages are likely answers
        if (mb_strlen($trimmed) <= 50) {
            return true;
        }

        // Check if it's NOT a question (no question marks, no question words at start)
        $questionPatterns = [
            '/^(what|when|where|who|why|how|which|can|could|would|should|is|are|do|does)/i',
            '/\?$/',
        ];

        foreach ($questionPatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return false; // It's a question, not an answer
            }
        }

        return true; // Looks like an answer
    }

    /**
     * Check if message is a question
     */
    protected function looksLikeQuestion(string $message): bool
    {
        $trimmed = trim($message);

        // Ends with question mark
        if (str_ends_with($trimmed, '?')) {
            return true;
        }

        // Starts with question words (language-agnostic patterns)
        $questionStarters = [
            '/^(what|when|where|who|why|how|which)/i',
            '/^(can|could|would|should|will|shall)/i',
            '/^(is|are|do|does|did|has|have)/i',
        ];

        foreach ($questionStarters as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if message is requesting a sub-workflow
     */
    protected function isSubWorkflowRequest(string $message): bool
    {
        $patterns = [
            '/create.*first/i',
            '/add.*first/i',
            '/make.*first/i',
            '/new.*first/i',
            '/create.*before/i',
            '/add.*before/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if message is cancellation
     */
    protected function isCancellation(string $message): bool
    {
        $trimmed = strtolower(trim($message));
        
        $cancelWords = ['cancel', 'stop', 'quit', 'exit', 'abort', 'nevermind', 'never mind'];
        
        foreach ($cancelWords as $word) {
            if ($trimmed === $word || str_starts_with($trimmed, $word . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if message is a simple question
     */
    protected function isSimpleQuestion(string $message): bool
    {
        // Questions that don't require workflow or complex processing
        $simplePatterns = [
            '/^(hi|hello|hey|greetings)/i',
            '/^(thanks|thank you)/i',
            '/^(bye|goodbye)/i',
        ];

        foreach ($simplePatterns as $pattern) {
            if (preg_match($pattern, trim($message))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if message is a personal question about the user
     */
    protected function isPersonalQuestion(string $message): bool
    {
        // Questions about the user should use user context, not RAG
        $personalIndicators = ['/\bmy\b/i', '/\bme\b/i', '/\bi\s+am\b/i'];
        foreach ($personalIndicators as $indicator) {
            if (preg_match($indicator, $message)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if message requires knowledge base
     */
    protected function requiresKnowledgeBase(string $message): bool
    {
        // Personal questions already handled above, no need to check again

        // Questions that likely need RAG
        $ragPatterns = [
            '/how (do|does|can|to)/i',
            '/what is/i',
            '/explain/i',
            '/tell me about/i',
            '/documentation/i',
        ];

        foreach ($ragPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Analyze for workflow/action request
     */
    protected function analyzeForWorkflow(string $message, UnifiedActionContext $context): array
    {
        $messageLower = strtolower(trim($message));

        // Check for action keywords
        $actionPatterns = [
            'create' => '/^(create|make|add|new)/i',
            'update' => '/^(update|edit|change|modify)/i',
            'delete' => '/^(delete|remove)/i',
            'read' => '/^(show|list|find|search|get)/i',
        ];

        foreach ($actionPatterns as $action => $pattern) {
            if (preg_match($pattern, $messageLower)) {
                return [
                    'type' => 'new_workflow',
                    'action' => 'start_workflow',
                    'operation' => $action,
                    'confidence' => 0.85,
                    'reasoning' => "User requesting {$action} operation"
                ];
            }
        }

        // Default: conversational
        return [
            'type' => 'conversational',
            'action' => 'handle_conversational',
            'confidence' => 0.6,
            'reasoning' => 'General conversation'
        ];
    }
}
