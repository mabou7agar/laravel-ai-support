<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\IntentAnalysisService;
use LaravelAIEngine\Services\AIEngineService;
use Illuminate\Support\Facades\Log;

/**
 * Intelligent message analyzer that determines routing
 * Replaces ComplexityAnalyzer with smarter, context-aware analysis
 */
class MessageAnalyzer
{
    public function __construct(
        protected IntentAnalysisService $intentAnalysis,
        protected AIEngineService $aiEngine
    ) {}

    /**
     * Analyze message to determine routing using pure AI intelligence
     */
    public function analyze(string $message, UnifiedActionContext $context): array
    {
        // PRIORITY 1: Check active workflow context
        if ($context->currentWorkflow) {
            return $this->analyzeInWorkflowContext($message, $context);
        }

        // PRIORITY 2: Use AI to intelligently route the message
        return $this->analyzeForWorkflow($message, $context);
    }

    /**
     * Analyze message in workflow context using AI intelligence
     */
    protected function analyzeInWorkflowContext(string $message, UnifiedActionContext $context): array
    {
        $awaitingConfirmation = $context->get('awaiting_confirmation');
        $askingFor = $context->get('asking_for');

        // If awaiting confirmation or asking for field, assume workflow continuation
        // The workflow handler will use AI to interpret the response
        if ($awaitingConfirmation || $askingFor) {
            return [
                'type' => 'workflow_continuation',
                'action' => 'continue_workflow',
                'confidence' => 0.95,
                'reasoning' => 'Continuing active workflow - AI will interpret response'
            ];
        }

        // Default: workflow continuation
        return [
            'type' => 'workflow_continuation',
            'action' => 'continue_workflow',
            'confidence' => 0.9,
            'reasoning' => 'Active workflow - continuing'
        ];
    }


    /**
     * Analyze for workflow/action request using AI intelligence
     */
    protected function analyzeForWorkflow(string $message, UnifiedActionContext $context): array
    {
        // Use AI to intelligently detect intent (handles typos, variations, and natural language)
        $aiIntent = $this->detectIntentWithAI($message);
        if ($aiIntent) {
            return $aiIntent;
        }

        // Default: conversational
        return [
            'type' => 'conversational',
            'action' => 'handle_conversational',
            'confidence' => 0.6,
            'reasoning' => 'General conversation'
        ];
    }

    /**
     * Use AI to intelligently detect workflow intent
     * Handles typos, variations, and natural language understanding
     */
    protected function detectIntentWithAI(string $message): ?array
    {
        try {
            $prompt = "User message: \"{$message}\"\n\n";
            $prompt .= "What does the user want to DO? Respond with ONE word:\n\n";
            $prompt .= "create - if they want to CREATE/MAKE/ADD/BUILD/GENERATE/SETUP/REGISTER something\n";
            $prompt .= "update - if they want to UPDATE/EDIT/MODIFY/CHANGE existing data\n";
            $prompt .= "delete - if they want to DELETE/REMOVE something\n";
            $prompt .= "read - if they want to VIEW/SHOW/LIST/FIND/SEARCH/GET information\n";
            $prompt .= "none - if they're just CHATTING/GREETING/ASKING ABOUT CAPABILITIES\n\n";
            $prompt .= "Ignore typos. Focus on intent.\n";
            $prompt .= "Answer (create/update/delete/read/none):";

            $request = new AIRequest(
                prompt: $prompt,
                maxTokens: 5,
                temperature: 0
            );

            $response = $this->aiEngine->generate($request);
            $intent = strtolower(trim($response->getContent()));

            // Check if it's a CRUD operation
            if (in_array($intent, ['create', 'update', 'delete', 'read'])) {
                Log::channel('ai-engine')->info('AI detected workflow intent', [
                    'message' => $message,
                    'detected_intent' => $intent,
                ]);

                return [
                    'type' => 'new_workflow',
                    'action' => 'start_workflow',
                    'operation' => $intent,
                    'crud_operation' => $intent, // Pass operation to workflow
                    'confidence' => 0.85,
                    'reasoning' => "AI detected {$intent} operation"
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('AI intent detection failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
