<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Log;

/**
 * Service for analyzing user message intent using AI
 */
class IntentAnalysisService
{
    public function __construct(
        protected AIEngineService $aiEngineService,
        protected ?PendingActionService $pendingActionService = null
    ) {
    }

    /**
     * Set the pending action service if not injected via constructor
     */
    public function setPendingActionService(PendingActionService $pendingActionService): void
    {
        $this->pendingActionService = $pendingActionService;
    }

    /**
     * Analyze user message intent using AI (language-agnostic)
     *
     * @return array{intent: string, confidence: float, extracted_data: array, context_enhancement: string}
     */
    public function analyzeMessageIntent(string $message, ?array $pendingAction = null, array $availableActions = []): array
    {
        // Quick check for single-word confirmations (optimization)
        $messageLower = strtolower(trim($message));
        $quickConfirms = ['yes', 'ok', 'okay', 'confirm', 'sure', 'yep', 'yeah', 'yup', 'proceed', 'go ahead', 'create', 'do it', 'make it'];

        if (in_array($messageLower, $quickConfirms)) {
            return [
                'intent' => 'confirm',
                'confidence' => 1.0,
                'extracted_data' => [],
                'context_enhancement' => 'User confirmed with simple affirmative response.',
                'auto_execute' => true,
            ];
        }

        // Quick check for rejection
        $quickRejects = ['no', 'cancel', 'stop', 'abort', 'nevermind', 'reject'];
        if (in_array($messageLower, $quickRejects)) {
            return [
                'intent' => 'reject',
                'confidence' => 1.0,
                'extracted_data' => [],
                'context_enhancement' => 'User rejected with simple negative response.'
            ];
        }

        // Quick check for greetings (optimization)
        $greetings = ['hi', 'hello', 'hey', 'greetings', 'good morning', 'good afternoon', 'good evening'];
        if (in_array($messageLower, $greetings)) {
            return [
                'intent' => 'greeting',
                'confidence' => 1.0,
                'extracted_data' => [],
                'context_enhancement' => 'User sent a standard greeting.',
                'auto_execute' => false,
            ];
        }

        // Use AI to analyze intent comprehensively
        try {
            $prompt = $this->buildAnalysisPrompt($message, $pendingAction, $availableActions);

            // Use gpt-4o-mini for better instruction following with complex prompts
            $intentModel = config('ai-engine.actions.intent_model', 'gpt-4o-mini');

            $aiRequest = new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from($intentModel),
                maxTokens: 400, // Reduced from 500
                temperature: 0
            );

            Log::channel('ai-engine')->debug('Sending intent analysis request', [
                'prompt_length' => strlen($prompt),
                'model' => $intentModel,
            ]);

            $response = $this->aiEngineService->generate($aiRequest);

            // Handle AI engine errors gracefully
            if (!$response->success) {
                $errorMessage = $this->getErrorMessage($response->error);

                Log::channel('ai-engine')->warning('Intent analysis failed due to AI error', [
                    'error' => $response->error,
                    'user_message' => $errorMessage,
                ]);

                return [
                    'intent' => 'question', // Fallback to question so RAG can handle it
                    'confidence' => 0.0,
                    'extracted_data' => [],
                    'context_enhancement' => 'Analysis failed: ' . $errorMessage,
                    'ai_error' => $errorMessage,
                ];
            }

            $content = $response->getContent();

            // Clean up code blocks if present
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $content = $matches[1];
            }

            $analysis = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::channel('ai-engine')->warning('Intent analysis returned invalid JSON', [
                    'content' => $response->getContent(),
                    'error' => json_last_error_msg(),
                ]);

                // Fallback attempt to salvage basic intent
                return [
                    'intent' => 'question',
                    'confidence' => 0.0,
                    'extracted_data' => [],
                    'context_enhancement' => 'Invalid JSON response from analysis',
                ];
            }

            return $analysis;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Intent analysis exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'intent' => 'question',
                'confidence' => 0.0,
                'extracted_data' => [],
                'context_enhancement' => 'Exception during analysis',
            ];
        }
    }

    /**
     * Build the detailed analysis prompt
     */
    protected function buildAnalysisPrompt(string $message, ?array $pendingAction = null, array $availableActions = []): string
    {
        $prompt = "Analyze intent & return JSON. Message: \"{$message}\"\n\n";

        // CRITICAL: Include available actions context
        if (!$pendingAction && !empty($availableActions)) {
            $relevantActions = $this->filterRelevantActions($message, $availableActions, 10); // Reduced limit
            $prompt .= "AVAILABLE ACTIONS:\n";
            foreach ($relevantActions as $actionId => $action) {
                $prompt .= "- {$actionId}: {$action['label']}\n";
            }
            $prompt .= "Match specific action ID or empty string.\n\n";
        } elseif (!$pendingAction) {
            $prompt .= "NO ACTIONS. 'Create/Add X' -> 'new_request'.\n\n";
        }

        if ($pendingAction) {
            $this->appendPendingActionContext($prompt, $pendingAction);
        } else {
            $prompt .= "STATUS: No pending action.\n\n";
        }

        $prompt .= "CATEGORIES:\n";
        $prompt .= "1. 'confirm': Yes/OK/Go ahead. (auto_execute: true if explicit)\n";
        $prompt .= "2. 'reject': No/Cancel/Stop.\n";
        $prompt .= "3. 'modify': Change [field] to [value]. target='path.to.field'.\n";
        $prompt .= "4. 'provide_data': Providing values. Map input to field (e.g. 'John' -> customer_id).\n";
        $prompt .= "5. 'question': General Qs.\n";
        $prompt .= "6. 'retrieval': Find/View/Get data. (NOT create).\n";
        $prompt .= "7. 'new_workflow': Create/Add entity via workflow (e.g., 'create invoice', 'add customer', 'make product').\n";
        $prompt .= "8. 'new_request': Generic creation request (fallback if not workflow).\n";
        $prompt .= "9. 'greeting': Hi/Hello.\n";
        $prompt .= "10. 'complex_task': Multi-step workflows, 'onboarding', 'troubleshooting', or vague goals requiring guidance.\n\n";
        $prompt .= "CRITICAL: Use 'new_workflow' for explicit entity creation (create invoice, add customer, new product).\n";
        $prompt .= "Use 'new_request' only for non-workflow creation requests.\n\n";

        // Document Type Analysis
        $this->appendDocumentAnalysisContext($prompt, $message);

        $prompt .= "JSON OUTPUT ONLY:\n";
        $prompt .= "{\n";
        $prompt .= "  \"intent\": \"category\",\n";
        $prompt .= "  \"confidence\": 0.95,\n";
        $prompt .= "  \"extracted_data\": {\"field\": \"val\"},\n";
        $prompt .= "  \"modification_target\": \"field\",\n";
        $prompt .= "  \"field_mapping\": {\"input\": \"field\"},\n";
        $prompt .= "  \"validation_suggestions\": [],\n";
        $prompt .= "  \"auto_execute\": bool,\n";
        $prompt .= "  \"context_enhancement\": \"reason\",\n";
        $prompt .= "  \"suggested_action_id\": \"id\"\n";
        $prompt .= "}\n";

        return $prompt;
    }

    protected function appendPendingActionContext(string &$prompt, array $pendingAction): void
    {
        $prompt .= "Context: PENDING ACTION in progress.\n";
        $prompt .= "Action: {$pendingAction['label']}\n";
        $prompt .= "Current Data: " . json_encode($pendingAction['data']['params'] ?? []) . "\n";

        $missingFields = $pendingAction['missing_fields'] ?? [];
        if (!empty($missingFields)) {
            $prompt .= "MISSING REQUIRED FIELDS: " . implode(', ', $missingFields) . "\n";
            $prompt .= "CRITICAL: If user provides a value that fits one of these fields, classify as 'provide_data'.\n";
            $prompt .= "CRITICAL: Do NOT create a new action if the user is just answering the question.\n\n";
        } else {
            $prompt .= "Status: Action is COMPLETE (waiting for confirmation).\n";
            $prompt .= "- If user adds details -> 'provide_data' or 'modify'.\n";
            $prompt .= "- If user says 'create invoice' (different entity) -> 'new_request'.\n\n";
        }
    }

    protected function appendDocumentAnalysisContext(string &$prompt, string $message): void
    {
        $docTypeConfig = config('ai-engine.project_context.document_type_detection');
        $isLongContent = strlen($message) > ($docTypeConfig['min_length'] ?? 500);

        if ($isLongContent && ($docTypeConfig['enabled'] ?? true)) {
            $prompt .= "DOCUMENT ANALYSIS (content > 500 chars):\n";
            $rules = $docTypeConfig['rules'] ?? [];
            foreach ($rules as $rule) {
                $prompt .= "- If matches: {$rule['description']} -> suggest collection '{$rule['suggested_collection']}'\n";
            }
            $prompt .= "Add \"suggested_collection\": \"ClassName\" to JSON if matched.\n\n";
        }
    }

    /**
     * Filter actions by relevance to message
     */
    protected function filterRelevantActions(string $message, array $actions, int $limit = 10): array
    {
        $messageLower = strtolower($message);
        $scored = [];

        // Extract primary entity from message (entity immediately after create/add/make/new)
        $primaryEntity = null;
        if (preg_match('/\\b(create|add|make|new)\\s+(\\w+)/i', $message, $matches)) {
            $primaryEntity = strtolower($matches[2]);
        }

        foreach ($actions as $actionId => $action) {
            $score = 0;

            // HIGHEST PRIORITY: Exact match of primary entity in action ID
            if ($primaryEntity && str_contains($actionId, $primaryEntity)) {
                $score += 100; // Very high score for exact entity match
            }

            // Score based on trigger keywords
            foreach ($action['triggers'] ?? [] as $trigger) {
                if (stripos($messageLower, strtolower($trigger)) !== false) {
                    $score += 10;
                }
            }

            // Score based on label
            if (stripos($messageLower, strtolower($action['label'])) !== false) {
                $score += 5;
            }

            // Score based on action ID keywords
            $actionWords = preg_split('/[_\\s]+/', $actionId);
            foreach ($actionWords as $word) {
                if (strlen($word) > 3 && stripos($messageLower, strtolower($word)) !== false) {
                    $score += 3;
                }
            }

            $scored[$actionId] = ['action' => $action, 'score' => $score];
        }

        // Sort by score descending
        uasort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Take top N actions
        $filtered = array_slice($scored, 0, $limit, true);

        return array_map(fn($item) => $item['action'], $filtered);
    }

    /**
     * Get user-friendly error message based on AI error
     */
    protected function getErrorMessage(?string $error): string
    {
        if (!$error) {
            return config('ai-engine.error_handling.fallback_message', 'AI service is temporarily unavailable.');
        }

        $errorMessages = config('ai-engine.error_handling.error_messages', []);

        if (str_contains(strtolower($error), 'quota') || str_contains(strtolower($error), 'exceeded')) {
            return $errorMessages['quota_exceeded'] ?? 'AI service quota exceeded.';
        }
        // ... (simplified for this extraction, relies on config mainly)

        return $error;
    }
    
    /**
     * Check if message is a confirmation using AI
     * Reusable across the codebase
     */
    public function isConfirmation(string $message, ?string $context = null): bool
    {
        $messageLower = strtolower(trim($message));
        
        // Quick check for obvious confirmations (optimization - no AI call needed)
        $quickConfirms = ['yes', 'ok', 'okay', 'sure', 'yep', 'yeah', 'yup', 'proceed', 'go ahead', 'do it', 'confirm'];
        if (in_array($messageLower, $quickConfirms)) {
            return true;
        }
        
        // Quick check for obvious rejections
        $quickRejects = ['no', 'cancel', 'stop', 'abort', 'nevermind', 'nope'];
        if (in_array($messageLower, $quickRejects)) {
            return false;
        }
        
        // Use AI for ambiguous messages
        try {
            $prompt = "Is this message a CONFIRMATION or AGREEMENT?\n";
            $prompt .= "Message: \"{$message}\"\n";
            if ($context) {
                $prompt .= "Context: {$context}\n";
            }
            $prompt .= "\nRespond with ONLY 'yes' or 'no'.";
            
            $response = $this->aiEngineService->generate(new AIRequest(
                prompt: $prompt,
                maxTokens: 3,
                temperature: 0
            ));
            
            return strtolower(trim($response->getContent())) === 'yes';
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('AI confirmation check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Check if message is a rejection/cancellation using AI
     */
    public function isRejection(string $message, ?string $context = null): bool
    {
        $messageLower = strtolower(trim($message));
        
        // Quick check for obvious rejections
        $quickRejects = ['no', 'cancel', 'stop', 'abort', 'nevermind', 'nope', 'reject'];
        if (in_array($messageLower, $quickRejects)) {
            return true;
        }
        
        // Quick check for obvious confirmations
        $quickConfirms = ['yes', 'ok', 'okay', 'sure', 'yep', 'yeah', 'yup', 'proceed'];
        if (in_array($messageLower, $quickConfirms)) {
            return false;
        }
        
        // Use AI for ambiguous messages
        try {
            $prompt = "Is this message a REJECTION, CANCELLATION, or NEGATIVE response?\n";
            $prompt .= "Message: \"{$message}\"\n";
            if ($context) {
                $prompt .= "Context: {$context}\n";
            }
            $prompt .= "\nRespond with ONLY 'yes' or 'no'.";
            
            $response = $this->aiEngineService->generate(new AIRequest(
                prompt: $prompt,
                maxTokens: 3,
                temperature: 0
            ));
            
            return strtolower(trim($response->getContent())) === 'yes';
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('AI rejection check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Detect message type using AI (confirmation, rejection, modification, data, question)
     */
    public function detectMessageType(string $message, ?string $context = null): string
    {
        $messageLower = strtolower(trim($message));
        
        // Quick checks for common patterns
        $quickConfirms = ['yes', 'ok', 'okay', 'sure', 'yep', 'yeah', 'yup', 'proceed', 'go ahead', 'confirm'];
        if (in_array($messageLower, $quickConfirms)) {
            return 'confirmation';
        }
        
        $quickRejects = ['no', 'cancel', 'stop', 'abort', 'nevermind', 'nope'];
        if (in_array($messageLower, $quickRejects)) {
            return 'rejection';
        }
        
        // Use AI for complex messages
        try {
            $prompt = "Classify this message into ONE category:\n";
            $prompt .= "Message: \"{$message}\"\n";
            if ($context) {
                $prompt .= "Context: {$context}\n";
            }
            $prompt .= "\nCategories:\n";
            $prompt .= "- confirmation: User agrees/confirms/approves\n";
            $prompt .= "- rejection: User disagrees/cancels/rejects\n";
            $prompt .= "- modification: User wants to change/update something\n";
            $prompt .= "- data: User is providing data/information\n";
            $prompt .= "- question: User is asking a question\n";
            $prompt .= "- other: None of the above\n";
            $prompt .= "\nRespond with ONLY the category name:";
            
            $response = $this->aiEngineService->generate(new AIRequest(
                prompt: $prompt,
                maxTokens: 10,
                temperature: 0
            ));
            
            $type = strtolower(trim($response->getContent()));
            $validTypes = ['confirmation', 'rejection', 'modification', 'data', 'question', 'other'];
            
            return in_array($type, $validTypes) ? $type : 'other';
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('AI message type detection failed', ['error' => $e->getMessage()]);
            return 'other';
        }
    }
}
