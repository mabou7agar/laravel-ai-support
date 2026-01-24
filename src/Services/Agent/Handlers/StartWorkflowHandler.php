<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentMode;
use LaravelAIEngine\Services\Agent\WorkflowDiscoveryService;
use LaravelAIEngine\Services\AIEngineService;
use Illuminate\Support\Facades\Log;

/**
 * Starts a new workflow
 */
class StartWorkflowHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AgentMode $agentMode,
        protected WorkflowDiscoveryService $workflowDiscovery,
        protected AIEngineService $ai
    ) {}

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        $workflowClass = $this->detectWorkflow($message);
        
        if (!$workflowClass) {
            Log::channel('ai-engine')->warning('No workflow detected');
            return AgentResponse::conversational(
                message: "I couldn't understand what you want to do. Can you please clarify?",
                context: $context
            );
        }
        
        Log::channel('ai-engine')->info('Starting new workflow', [
            'workflow' => $workflowClass,
        ]);
        
        return $this->agentMode->startWorkflow($workflowClass, $context, $message);
    }
    
    public function canHandle(string $action): bool
    {
        return $action === 'start_workflow';
    }
    
    protected function detectWorkflow(string $message): ?string
    {
        // Get discovered workflows with their goals
        $discoveredWorkflows = $this->workflowDiscovery->discoverWorkflows(useCache: true);
        
        if (empty($discoveredWorkflows)) {
            Log::channel('ai-engine')->warning('No workflows discovered');
            return null;
        }
        
        // Try keyword matching FIRST (fast, free)
        $workflowClass = $this->detectWorkflowWithKeywords($message, $discoveredWorkflows);
        
        if ($workflowClass) {
            Log::channel('ai-engine')->info('Workflow detected via keyword', [
                'workflow' => $workflowClass,
                'message' => $message,
            ]);
            return $workflowClass;
        }
        
        // Fallback to AI-based intelligent routing only if keywords don't match
        Log::channel('ai-engine')->debug('Keyword matching failed, trying AI routing');
        $workflowClass = $this->detectWorkflowWithAI($message, $discoveredWorkflows);
        
        if ($workflowClass) {
            Log::channel('ai-engine')->info('Workflow detected via AI', [
                'workflow' => $workflowClass,
                'message' => $message,
            ]);
            return $workflowClass;
        }
        
        return null;
    }
    
    /**
     * Use AI to intelligently select the best workflow based on goals
     */
    protected function detectWorkflowWithAI(string $message, array $workflows): ?string
    {
        try {
            // Build prompt with numbered workflow options for easier selection
            $prompt = "User request: \"{$message}\"\n\n";
            $prompt .= "Available workflows:\n";
            
            $workflowList = [];
            $index = 1;
            foreach ($workflows as $workflowClass => $metadata) {
                $goal = $metadata['goal'] ?? 'Unknown';
                $workflowList[$index] = $workflowClass;
                $prompt .= "{$index}. {$goal}\n";
                $index++;
            }
            
            $prompt .= "\nWhich workflow number (1-" . count($workflowList) . ") best matches the user's request?\n";
            $prompt .= "Respond with ONLY the number, or '0' if no match.\n";
            $prompt .= "Consider what the user wants to accomplish.\n";
            
            $request = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                maxTokens: 10
            );
            
            $response = $this->ai->generateText($request);
            $selectedNumber = (int) trim($response->getContent());
            
            Log::channel('ai-engine')->debug('AI workflow selection', [
                'message' => $message,
                'ai_response' => $response->getContent(),
                'selected_number' => $selectedNumber,
            ]);
            
            // Validate the response
            if ($selectedNumber === 0 || !isset($workflowList[$selectedNumber])) {
                return null;
            }
            
            return $workflowList[$selectedNumber];
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI workflow detection failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Fallback keyword-based detection
     */
    protected function detectWorkflowWithKeywords(string $message, array $workflows): ?string
    {
        foreach ($workflows as $workflowClass => $metadata) {
            $triggers = $metadata['triggers'] ?? [];
            
            foreach ($triggers as $trigger) {
                if (stripos($message, $trigger) !== false) {
                    Log::channel('ai-engine')->info('Workflow detected via keyword', [
                        'workflow' => $workflowClass,
                        'matched_trigger' => $trigger,
                        'message' => $message,
                    ]);
                    return $workflowClass;
                }
            }
        }
        
        Log::channel('ai-engine')->warning('No workflow detected', [
            'message' => $message,
            'checked_workflows' => count($workflows),
        ]);
        
        return null;
    }
}
