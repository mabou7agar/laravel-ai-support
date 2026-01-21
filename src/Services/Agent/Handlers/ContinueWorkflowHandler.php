<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentMode;
use Illuminate\Support\Facades\Log;

class ContinueWorkflowHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AgentMode $agentMode
    ) {}

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Continuing workflow', [
            'workflow' => $context->currentWorkflow,
            'step' => $context->currentStep,
        ]);
        
        return $this->agentMode->continueWorkflow($message, $context, $options);
    }
    
    public function canHandle(string $action): bool
    {
        return $action === 'continue_workflow';
    }
}
