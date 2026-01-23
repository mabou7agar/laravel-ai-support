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
        
        // Try AI-based intelligent routing first
        $workflowClass = $this->detectWorkflowWithAI($message, $discoveredWorkflows);
        
        if ($workflowClass) {
            Log::channel('ai-engine')->info('Workflow detected via AI', [
                'workflow' => $workflowClass,
                'message' => $message,
            ]);
            return $workflowClass;
        }
        
        // Fallback to keyword matching if AI routing fails
        Log::channel('ai-engine')->debug('AI routing failed, trying keyword fallback');
        return $this->detectWorkflowWithKeywords($message, $discoveredWorkflows);
    }
    
    /**
     * Use AI to intelligently select the best workflow based on goals
     */
    protected function detectWorkflowWithAI(string $message, array $workflows): ?string
    {
        try {
            // Build prompt with workflow options
            $prompt = "User request: \"{$message}\"\n\n";
            $prompt .= "Available workflows:\n";
            
            foreach ($workflows as $workflowClass => $metadata) {
                $goal = $metadata['goal'] ?? 'Unknown';
                $priority = $metadata['priority'] ?? 0;
                $prompt .= "- {$workflowClass}: {$goal} (priority: {$priority})\n";
            }
            
            $prompt .= "\nWhich workflow best matches the user's request?\n";
            $prompt .= "Respond with ONLY the workflow class name, or 'NONE' if no match.\n";
            $prompt .= "Consider the goal description to understand what each workflow does.\n";
            
            $request = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                maxTokens: 100
            );
            
            $response = $this->ai->generateText($request);
            $selectedWorkflow = trim($response->getContent());
            
            // Validate the response
            if ($selectedWorkflow === 'NONE' || empty($selectedWorkflow)) {
                return null;
            }
            
            // Check if the selected workflow exists
            if (isset($workflows[$selectedWorkflow])) {
                return $selectedWorkflow;
            }
            
            // Try to find partial match (in case AI returned short name)
            foreach (array_keys($workflows) as $workflowClass) {
                if (str_contains($workflowClass, $selectedWorkflow)) {
                    return $workflowClass;
                }
            }
            
            return null;
            
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
