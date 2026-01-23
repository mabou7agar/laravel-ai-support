<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentMode;
use LaravelAIEngine\Services\Agent\WorkflowDiscoveryService;
use Illuminate\Support\Facades\Log;

/**
 * Starts a new workflow
 */
class StartWorkflowHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AgentMode $agentMode,
        protected WorkflowDiscoveryService $workflowDiscovery
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
        // Merge config-based workflows with auto-discovered workflows
        $configWorkflows = config('ai-agent.workflows', []);
        $discoveredWorkflows = $this->workflowDiscovery->discoverWorkflows(useCache: true);
        
        // Config takes precedence over discovered
        $workflows = array_merge($discoveredWorkflows, $configWorkflows);
        
        Log::channel('ai-engine')->debug('StartWorkflowHandler: Detecting workflow', [
            'message' => $message,
            'config_workflows' => count($configWorkflows),
            'discovered_workflows' => count($discoveredWorkflows),
            'total_workflows' => count($workflows),
            'registered_workflows' => array_keys($workflows),
        ]);
        
        foreach ($workflows as $workflowClass => $triggers) {
            Log::channel('ai-engine')->debug('Checking workflow triggers', [
                'workflow' => $workflowClass,
                'triggers' => $triggers,
            ]);
            
            foreach ($triggers as $trigger) {
                if (stripos($message, $trigger) !== false) {
                    Log::channel('ai-engine')->info('Workflow detected!', [
                        'workflow' => $workflowClass,
                        'matched_trigger' => $trigger,
                        'message' => $message,
                        'source' => isset($configWorkflows[$workflowClass]) ? 'config' : 'auto-discovered',
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
