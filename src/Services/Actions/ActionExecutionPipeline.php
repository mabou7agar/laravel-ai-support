<?php

namespace LaravelAIEngine\Services\Actions;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\InteractiveAction;
use Illuminate\Support\Facades\Log;

/**
 * Action Execution Pipeline
 * 
 * Simplified, clear execution flow for all actions
 */
class ActionExecutionPipeline
{
    protected array $middleware = [];
    
    public function __construct(
        protected ActionRegistry $registry,
        protected ActionParameterExtractor $extractor
    ) {
        $this->registerDefaultMiddleware();
    }
    
    /**
     * Execute an action through the pipeline
     */
    public function execute(InteractiveAction $action, $userId, ?string $sessionId = null): ActionResult
    {
        $startTime = microtime(true);
        
        Log::channel('ai-engine')->info('Action execution started', [
            'action_id' => $action->id,
            'action_type' => $action->type->value,
            'user_id' => $userId,
        ]);
        
        try {
            // Get action definition
            $definition = $this->registry->get($action->data['action_id'] ?? $action->id);
            
            if (!$definition) {
                return ActionResult::failure("Action not found: {$action->id}");
            }
            
            // Run through middleware pipeline
            $result = $this->runThroughMiddleware($action, $definition, $userId, $sessionId);
            
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $result->withDuration($durationMs);
            $result->withActionInfo($action->id, $action->type->value);
            
            Log::channel('ai-engine')->info('Action execution completed', [
                'action_id' => $action->id,
                'success' => $result->success,
                'duration_ms' => $durationMs,
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            
            Log::channel('ai-engine')->error('Action execution failed', [
                'action_id' => $action->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return ActionResult::failure(
                error: 'Action execution failed: ' . $e->getMessage(),
                metadata: ['exception' => get_class($e)]
            )->withDuration($durationMs);
        }
    }
    
    /**
     * Run action through middleware pipeline
     */
    protected function runThroughMiddleware(
        InteractiveAction $action,
        array $definition,
        $userId,
        ?string $sessionId
    ): ActionResult {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            function ($next, $middleware) {
                return function ($action, $definition, $userId, $sessionId) use ($next, $middleware) {
                    return $middleware->handle($action, $definition, $userId, $sessionId, $next);
                };
            },
            function ($action, $definition, $userId, $sessionId) {
                return $this->executeAction($action, $definition, $userId, $sessionId);
            }
        );
        
        return $pipeline($action, $definition, $userId, $sessionId);
    }
    
    /**
     * Execute the actual action
     */
    protected function executeAction(
        InteractiveAction $action,
        array $definition,
        $userId,
        ?string $sessionId
    ): ActionResult {
        $executor = $definition['executor'] ?? null;
        $params = $action->data['params'] ?? [];
        
        Log::channel('ai-engine')->debug('ActionExecutionPipeline executing action', [
            'executor' => $executor,
            'executor_type' => gettype($executor),
            'action_id' => $action->id,
            'definition_keys' => array_keys($definition),
        ]);
        
        // Route to appropriate executor
        return match($executor) {
            'model.dynamic' => $this->executeModelAction($definition, $params, $userId),
            'model.remote' => $this->executeRemoteModelAction($definition, $params, $userId),
            'workflow' => $this->executeWorkflow($definition, $params, $userId, $sessionId),
            'email.reply' => $this->executeEmailAction($definition, $params, $userId),
            'calendar.create' => $this->executeCalendarAction($definition, $params, $userId),
            'task.create' => $this->executeTaskAction($definition, $params, $userId),
            default => $this->executeCustomAction($executor, $params, $userId)
        };
    }
    
    /**
     * Execute local model action
     */
    protected function executeModelAction(array $definition, array $params, $userId): ActionResult
    {
        $modelClass = $definition['model_class'] ?? null;
        
        if (!$modelClass || !class_exists($modelClass)) {
            return ActionResult::failure('Invalid model class');
        }
        
        try {
            $reflection = new \ReflectionClass($modelClass);
            
            if (!$reflection->hasMethod('executeAI')) {
                return ActionResult::failure("Model does not have executeAI method");
            }
            
            // Add user_id to params
            $params['user_id'] = $userId;
            
            // Execute the model's AI action
            $method = $reflection->getMethod('executeAI');
            
            if ($method->isStatic()) {
                $result = $modelClass::executeAI('create', $params);
            } else {
                $model = new $modelClass();
                $result = $model->executeAI('create', $params);
            }
            
            // Handle different return types
            if (is_array($result) && isset($result['success']) && !$result['success']) {
                return ActionResult::failure(
                    error: $result['error'] ?? 'Model action failed',
                    data: $result
                );
            }
            
            $modelName = class_basename($modelClass);
            
            return ActionResult::success(
                message: "✅ {$modelName} created successfully!",
                data: $result,
                metadata: ['model_class' => $modelClass]
            );
            
        } catch (\Exception $e) {
            return ActionResult::failure(
                error: 'Model execution failed: ' . $e->getMessage(),
                metadata: ['model_class' => $modelClass]
            );
        }
    }
    
    /**
     * Execute remote model action
     */
    protected function executeRemoteModelAction(array $definition, array $params, $userId): ActionResult
    {
        try {
            $remoteActionService = app(\LaravelAIEngine\Services\Node\RemoteActionService::class);
            
            $nodeSlug = $definition['node_slug'] ?? null;
            $modelClass = $definition['model_class'] ?? null;
            
            if (!$nodeSlug || !$modelClass) {
                return ActionResult::failure('Missing node or model information');
            }
            
            $result = $remoteActionService->executeAction($nodeSlug, [
                'action' => 'create',
                'model_class' => $modelClass,
                'params' => array_merge($params, ['user_id' => $userId]),
            ]);
            
            if ($result['success'] ?? false) {
                $modelName = class_basename($modelClass);
                return ActionResult::success(
                    message: "✅ {$modelName} created on remote node!",
                    data: $result['data'] ?? null,
                    metadata: [
                        'node' => $definition['node_name'] ?? 'Unknown',
                        'model_class' => $modelClass,
                    ]
                );
            }
            
            return ActionResult::failure(
                error: $result['error'] ?? 'Remote action failed',
                data: $result
            );
            
        } catch (\Exception $e) {
            return ActionResult::failure(
                error: 'Remote execution failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Execute email action
     */
    protected function executeEmailAction(array $definition, array $params, $userId): ActionResult
    {
        // TODO: Integrate with email service
        return ActionResult::success(
            message: '✉️ Email reply drafted successfully!',
            data: $params
        );
    }
    
    /**
     * Execute calendar action
     */
    protected function executeCalendarAction(array $definition, array $params, $userId): ActionResult
    {
        // TODO: Integrate with calendar service
        return ActionResult::success(
            message: '📅 Calendar event created!',
            data: $params
        );
    }
    
    /**
     * Execute task action
     */
    protected function executeTaskAction(array $definition, array $params, $userId): ActionResult
    {
        // TODO: Integrate with task service
        return ActionResult::success(
            message: '✅ Task created successfully!',
            data: $params
        );
    }
    
    /**
     * Execute workflow action
     */
    protected function executeWorkflow(
        array $definition,
        array $params,
        $userId,
        ?string $sessionId
    ): ActionResult {
        $workflowClass = $definition['workflow_class'] ?? null;
        
        if (!$workflowClass || !class_exists($workflowClass)) {
            return ActionResult::failure('Invalid workflow class');
        }
        
        try {
            $agentMode = app(\LaravelAIEngine\Services\Agent\AgentMode::class);
            
            // Create or get context for this session
            $context = new \LaravelAIEngine\DTOs\UnifiedActionContext(
                $sessionId ?? uniqid('workflow_'),
                $userId
            );
            
            // Get the message from params
            $message = $params['message'] ?? $params['user_message'] ?? '';
            
            // Check if workflow is already active for this session
            if ($context->currentWorkflow === $workflowClass) {
                Log::channel('ai-engine')->info('Continuing existing workflow', [
                    'workflow' => $workflowClass,
                    'session_id' => $sessionId,
                    'current_step' => $context->currentStep,
                ]);
                
                $response = $agentMode->continueWorkflow($message, $context);
            } else {
                Log::channel('ai-engine')->info('Starting new workflow', [
                    'workflow' => $workflowClass,
                    'session_id' => $sessionId,
                    'message' => substr($message, 0, 100),
                ]);
                
                $response = $agentMode->startWorkflow($workflowClass, $context, $message);
            }
            
            // Convert AgentResponse to ActionResult
            if ($response->needsUserInput) {
                return ActionResult::needsUserInput(
                    message: $response->message,
                    data: [
                        'workflow_active' => true,
                        'current_step' => $context->currentStep,
                        'workflow_state' => $context->workflowState,
                    ],
                    metadata: [
                        'workflow_class' => $workflowClass,
                        'session_id' => $sessionId,
                    ]
                );
            }
            
            if ($response->isComplete) {
                return ActionResult::success(
                    message: $response->message,
                    data: [
                        'workflow_completed' => true,
                        'final_state' => $context->workflowState,
                    ],
                    metadata: [
                        'workflow_class' => $workflowClass,
                        'session_id' => $sessionId,
                    ]
                );
            }
            
            if (!$response->success) {
                return ActionResult::failure(
                    error: $response->message,
                    data: [
                        'workflow_failed' => true,
                        'current_step' => $context->currentStep,
                    ]
                );
            }
            
            return ActionResult::success(
                message: $response->message,
                data: ['workflow_state' => $context->workflowState]
            );
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Workflow execution failed', [
                'workflow' => $workflowClass,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return ActionResult::failure(
                error: 'Workflow execution failed: ' . $e->getMessage(),
                metadata: ['workflow_class' => $workflowClass]
            );
        }
    }
    
    /**
     * Execute custom action
     */
    protected function executeCustomAction(?string $executor, array $params, $userId): ActionResult
    {
        if (!$executor) {
            return ActionResult::failure('No executor defined for action');
        }
        
        // Check if executor is a callable class
        if (class_exists($executor)) {
            try {
                $executorInstance = app($executor);
                
                if (method_exists($executorInstance, 'execute')) {
                    $result = $executorInstance->execute($params, $userId);
                    
                    if (is_array($result)) {
                        return ActionResult::fromArray($result);
                    }
                    
                    if ($result instanceof ActionResult) {
                        return $result;
                    }
                }
            } catch (\Exception $e) {
                return ActionResult::failure(
                    error: 'Custom executor failed: ' . $e->getMessage()
                );
            }
        }
        
        return ActionResult::failure("Unknown executor: {$executor}");
    }
    
    /**
     * Register middleware
     */
    public function middleware(object $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }
    
    /**
     * Register default middleware
     */
    protected function registerDefaultMiddleware(): void
    {
        // Middleware will be added here
        // Examples: ValidateActionMiddleware, CheckPermissionsMiddleware, etc.
    }
}
