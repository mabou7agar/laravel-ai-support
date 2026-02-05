<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentMode;
use Illuminate\Support\Facades\Log;

/**
 * Starts sub-workflow and pauses parent workflow
 */
class SubWorkflowHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AgentMode $agentMode
    ) {}

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Starting sub-workflow', [
            'parent_workflow' => $context->currentWorkflow,
            'message' => $message,
        ]);
        
        // Save parent workflow state
        $context->set('parent_workflow', [
            'class' => $context->currentWorkflow,
            'step' => $context->currentStep,
            'state' => $context->workflowState,
            'data' => $context->get('collected_data'),
        ]);
        
        // Detect which sub-workflow to start
        $subWorkflowClass = $this->detectWorkflow($message);
        
        if (!$subWorkflowClass) {
            Log::channel('ai-engine')->warning('Could not detect sub-workflow');
            // Fallback to continuing current workflow
            return $this->agentMode->continueWorkflow($message, $context, $options);
        }
        
        // Start sub-workflow
        return $this->agentMode->startWorkflow($subWorkflowClass, $context, $message);
    }
    
    public function canHandle(string $action): bool
    {
        return $action === 'start_sub_workflow';
    }
    
    protected function detectWorkflow(string $message): ?string
    {
        $workflows = config('ai-agent.workflows', []);
        
        foreach ($workflows as $workflowClass => $triggers) {
            foreach ($triggers as $trigger) {
                if (stripos($message, $trigger) !== false) {
                    return $workflowClass;
                }
            }
        }
        
        return null;
    }
}
