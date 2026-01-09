<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionManager;
use LaravelAIEngine\Services\DataCollector\DataCollectorService;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    public function __construct(
        protected ComplexityAnalyzer $complexityAnalyzer,
        protected ActionManager $actionManager,
        protected DataCollectorService $dataCollector,
        protected AgentMode $agentMode,
        protected ContextManager $contextManager
    ) {}

    public function process(
        string $message,
        string $sessionId,
        $userId,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Agent orchestrator processing message', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'message_length' => strlen($message),
        ]);

        $context = $this->contextManager->getOrCreate($sessionId, $userId);
        
        $context->addUserMessage($message);
        
        // Check if workflow is in progress
        if ($context->currentWorkflow && !empty($context->workflowState)) {
            Log::channel('ai-engine')->info('Continuing existing workflow', [
                'workflow' => $context->currentWorkflow,
                'step' => $context->currentStep,
                'workflow_state' => $context->workflowState,
            ]);
            
            // Continue workflow with user's message
            $response = $this->agentMode->continueWorkflow($message, $context, $options);
            
            // Save context after workflow execution
            $this->contextManager->save($context);
            
            return $response;
        }
        
        if ($context->dataCollectorState && $context->dataCollectorState['status'] !== 'completed') {
            Log::channel('ai-engine')->info('Continuing DataCollector session');
            
            return $this->executeGuidedFlow($message, $context, $options);
        }
        
        $analysis = $this->complexityAnalyzer->analyze($message, $context);
        
        $context->intentAnalysis = $analysis;
        
        Log::channel('ai-engine')->info('Complexity analysis completed', [
            'complexity' => $analysis['complexity'],
            'strategy' => $analysis['suggested_strategy'],
            'confidence' => $analysis['confidence'],
        ]);
        
        $strategy = $this->selectStrategy($analysis, $context);
        
        $context->currentStrategy = $strategy;
        
        $response = $this->executeStrategy($strategy, $message, $context, $options);
        
        // Save context after strategy execution
        $this->contextManager->save($context);
        
        return $response;
    }

    protected function selectStrategy(array $analysis, UnifiedActionContext $context): string
    {
        $suggested = $analysis['suggested_strategy'];
        
        $overrides = config('ai-agent.strategy_overrides', []);
        
        if (isset($context->pendingAction['model_class'])) {
            $modelClass = $context->pendingAction['model_class'];
            
            foreach ($overrides as $strategy => $models) {
                if (in_array($modelClass, $models)) {
                    Log::channel('ai-engine')->info('Strategy overridden by configuration', [
                        'suggested' => $suggested,
                        'override' => $strategy,
                        'model' => $modelClass,
                    ]);
                    return $strategy;
                }
            }
        }
        
        return $suggested;
    }

    protected function executeStrategy(
        string $strategy,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        Log::channel('ai-engine')->info('Executing strategy', [
            'strategy' => $strategy,
            'session_id' => $context->sessionId,
        ]);

        return match($strategy) {
            'quick_action' => $this->executeQuickAction($message, $context, $options),
            'guided_flow' => $this->executeGuidedFlow($message, $context, $options),
            'agent_mode' => $this->executeAgentMode($message, $context, $options),
            default => $this->executeConversational($message, $context, $options),
        };
    }

    protected function executeQuickAction(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $actions = $this->actionManager->generateActionsForContext(
            $message,
            $context->toArray(),
            $context->intentAnalysis
        );
        
        if (empty($actions)) {
            Log::channel('ai-engine')->info('No actions found, falling back to conversational');
            return $this->executeConversational($message, $context, $options);
        }
        
        $action = $actions[0];
        
        if (!empty($action->data['missing_fields'] ?? [])) {
            Log::channel('ai-engine')->info('Quick action incomplete, switching to guided flow', [
                'action_id' => $action->id,
                'missing_fields' => $action->data['missing_fields'],
            ]);
            
            $context->pendingAction = [
                'id' => $action->id,
                'label' => $action->label,
                'params' => $action->data['params'] ?? [],
                'missing_fields' => $action->data['missing_fields'],
                'model_class' => $action->data['model_class'] ?? null,
            ];
            $context->switchStrategy('guided_flow');
            
            return $this->executeGuidedFlow($message, $context, $options);
        }
        
        $result = $this->actionManager->executeAction($action, $context->userId);
        
        $response = AgentResponse::fromActionResult($result, $context);
        $response->strategy = 'quick_action';
        
        $context->addAssistantMessage($response->message);
        $context->persist();
        
        return $response;
    }

    protected function executeGuidedFlow(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        if (!$context->dataCollectorState) {
            $config = $this->createDataCollectorConfig($context);
            
            if (!$config) {
                Log::channel('ai-engine')->warning('Could not create DataCollector config');
                return $this->executeConversational($message, $context, $options);
            }
            
            $state = $this->dataCollector->startSession($context->sessionId, $config);
            $context->dataCollectorState = $state->toArray();
            $context->persist();
            
            return AgentResponse::fromDataCollectorState($state, $context);
        }
        
        $response = $this->dataCollector->processMessage(
            $context->sessionId,
            $message,
            $options['engine'] ?? 'openai',
            $options['model'] ?? 'gpt-4o'
        );
        
        $context->dataCollectorState = $response->state->toArray();
        
        if ($response->state->status === 'completed') {
            $context->clearDataCollectorState();
            $context->pendingAction = null;
        }
        
        $context->persist();
        
        $agentResponse = AgentResponse::fromDataCollectorResponse($response, $context);
        $agentResponse->strategy = 'guided_flow';
        
        $context->addAssistantMessage($agentResponse->message);
        
        return $agentResponse;
    }

    protected function executeAgentMode(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        if (!$context->currentWorkflow) {
            $workflowClass = $this->detectWorkflow($message, $context);
            
            if (!$workflowClass) {
                Log::channel('ai-engine')->warning('No workflow detected for agent mode');
                return $this->executeConversational($message, $context, $options);
            }
            
            return $this->agentMode->startWorkflow($workflowClass, $context, $message);
        }
        
        return $this->agentMode->execute($message, $context, $options);
    }

    protected function executeConversational(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $response = AgentResponse::conversational(
            message: "I understand you said: \"{$message}\". How can I help you?",
            context: $context
        );
        
        $context->addAssistantMessage($response->message);
        $context->persist();
        
        return $response;
    }

    protected function createDataCollectorConfig(UnifiedActionContext $context): ?\LaravelAIEngine\DTOs\DataCollectorConfig
    {
        if (!$context->pendingAction) {
            return null;
        }
        
        $modelClass = $context->pendingAction['model_class'] ?? null;
        
        if (!$modelClass || !class_exists($modelClass)) {
            return null;
        }
        
        try {
            $reflection = new \ReflectionClass($modelClass);
            
            if (!$reflection->hasMethod('initializeAI')) {
                return null;
            }
            
            $config = $modelClass::initializeAI();
            
            $initialData = $context->pendingAction['params'] ?? [];
            
            return new \LaravelAIEngine\DTOs\DataCollectorConfig(
                name: $config['name'] ?? strtolower(class_basename($modelClass)),
                title: $config['title'] ?? "Create " . class_basename($modelClass),
                fields: $config['fields'] ?? [],
                initialData: $initialData,
                onCompleteAction: $config['on_complete_action'] ?? null,
                confirmBeforeComplete: $config['confirm_before_complete'] ?? true
            );
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Failed to create DataCollector config', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function detectWorkflow(string $message, UnifiedActionContext $context): ?string
    {
        $workflows = config('ai-agent.workflows', []);
        
        foreach ($workflows as $workflowClass => $triggers) {
            foreach ($triggers as $trigger) {
                if (stripos($message, $trigger) !== false) {
                    Log::channel('ai-engine')->info('Workflow detected', [
                        'workflow' => $workflowClass,
                        'trigger' => $trigger,
                    ]);
                    return $workflowClass;
                }
            }
        }
        
        return null;
    }
}
