<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use Illuminate\Support\Facades\Log;

/**
 * Cancels active workflow and clears state
 */
class CancelWorkflowHandler implements MessageHandlerInterface
{
    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Canceling workflow', [
            'workflow' => $context->currentWorkflow,
        ]);
        
        $workflowName = class_basename($context->currentWorkflow);
        
        // Clear workflow state
        $context->currentWorkflow = null;
        $context->currentStep = null;
        $context->workflowState = [];
        $context->forget('collected_data');
        $context->forget('parent_workflow');
        $context->forget('awaiting_confirmation');
        
        return AgentResponse::conversational(
            message: "Workflow '{$workflowName}' has been canceled. How can I help you?",
            context: $context
        );
    }
    
    public function canHandle(string $action): bool
    {
        return $action === 'cancel_workflow';
    }
}
