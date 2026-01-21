<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\WorkflowStep;
use Illuminate\Support\Facades\Log;

class AgentMode
{
    protected int $maxSteps;
    protected int $maxRetries;
    protected $crudHandler;

    public function __construct()
    {
        $this->maxSteps = config('ai-agent.agent_mode.max_steps', 10);
        $this->maxRetries = config('ai-agent.agent_mode.max_retries', 3);
        $this->crudHandler = app(\LaravelAIEngine\Services\IntelligentCRUDHandler::class);
    }

    public function execute(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Agent mode execution started', [
            'session_id' => $context->sessionId,
            'workflow' => $context->currentWorkflow,
            'current_step' => $context->currentStep,
            'message' => substr($message, 0, 50),
        ]);

        // Phase 1: Check if we have an active workflow
        $hasActiveWorkflow = !empty($context->currentWorkflow);
        
        // Phase 2: Handle CRUD operations (only if NO active workflow)
        if (!$hasActiveWorkflow) {
            $crudResponse = $this->handleCRUDOperations($message, $context);
            if ($crudResponse) {
                return $crudResponse;
            }
        }

        // Phase 3: Validate workflow exists
        if (!$context->currentWorkflow) {
            Log::channel('ai-engine')->error('❌ No workflow set in context');
            return AgentResponse::failure(
                message: 'Workflow not initialized',
                context: $context
            );
        }
        
        // Phase 4: Execute workflow
        return $this->executeWorkflowStep($message, $context);
    }
    
    /**
     * Phase 2: Handle CRUD operations when no active workflow
     */
    protected function handleCRUDOperations(string $message, UnifiedActionContext $context): ?AgentResponse
    {
        // Check for existing CRUD operation
        $existingCrudOp = $context->get('crud_operation');
        
        if ($existingCrudOp) {
            return $this->continueCRUDOperation($existingCrudOp, $message, $context);
        }
        
        // Detect new CRUD operation
        $crudOperation = $this->crudHandler->detectOperation($message, $context->conversationHistory ?? []);
        
        if ($crudOperation && in_array($crudOperation['operation'], ['update', 'delete'])) {
            return $this->startCRUDOperation($crudOperation, $message, $context);
        }
        
        return null; // No CRUD operation detected
    }
    
    /**
     * Continue an existing CRUD operation
     */
    protected function continueCRUDOperation(string $operation, string $message, UnifiedActionContext $context): AgentResponse
    {
        Log::channel('ai-engine')->info('Continuing CRUD operation', [
            'operation' => $operation,
            'entity' => $context->get('crud_entity'),
        ]);
        
        $result = match($operation) {
            'update' => $this->crudHandler->handleUpdate(
                $context->get('crud_entity'),
                $context->get('crud_identifier'),
                $context,
                $message
            ),
            'delete' => $this->crudHandler->handleDelete(
                $context->get('crud_entity'),
                $context->get('crud_identifier'),
                $context
            ),
            default => ActionResult::failure(error: 'Unknown CRUD operation')
        };
        
        return AgentResponse::fromActionResult($result, $context);
    }
    
    /**
     * Start a new CRUD operation
     */
    protected function startCRUDOperation(array $crudOperation, string $message, UnifiedActionContext $context): AgentResponse
    {
        Log::channel('ai-engine')->info('New CRUD operation detected', $crudOperation);
        
        $result = match($crudOperation['operation']) {
            'update' => $this->crudHandler->handleUpdate(
                $crudOperation['entity'],
                $crudOperation['identifier'],
                $context,
                $message
            ),
            'delete' => $this->crudHandler->handleDelete(
                $crudOperation['entity'],
                $crudOperation['identifier'],
                $context
            ),
            default => ActionResult::failure(error: 'Unknown CRUD operation')
        };
        
        return AgentResponse::fromActionResult($result, $context);
    }
    
    /**
     * Phase 4: Execute workflow step
     */
    protected function executeWorkflowStep(string $message, UnifiedActionContext $context): AgentResponse
    {

        // Check for infinite loop protection
        if (!$this->checkInfiniteLoopProtection($context)) {
            return AgentResponse::failure(
                message: 'Workflow appears to be stuck. Please try again or contact support.',
                context: $context
            );
        }

        // Get workflow instance
        $workflow = $this->getWorkflow($context->currentWorkflow);
        
        if (!$workflow) {
            return AgentResponse::failure(
                message: 'Workflow not found',
                context: $context
            );
        }

        // Determine current step
        $currentStep = $this->getCurrentStep($workflow, $context);
        
        if (!$currentStep) {
            // Check if we're in a subflow that just completed
            $activeSubflow = $context->get('active_subflow');
            if ($activeSubflow) {
                Log::channel('ai-engine')->info('Step not found but subflow active - subflow completed', [
                    'workflow' => get_class($workflow),
                    'requested_step' => $context->currentStep,
                    'active_subflow' => $activeSubflow,
                ]);
                
                // Pop parent workflow context from stack
                $workflowStack = $context->get('workflow_stack', []);
                
                if (!empty($workflowStack)) {
                    $parentContext = array_pop($workflowStack);
                    $context->set('workflow_stack', $workflowStack);
                    
                    // Restore parent workflow context
                    $context->currentStep = $parentContext['step'];
                    $context->currentWorkflow = $parentContext['workflow'];
                    $context->set('current_workflow', $parentContext['workflow']);
                    $context->set('active_subflow', $parentContext['active_subflow']);
                    
                    $context->persist();
                    
                    Log::channel('ai-engine')->info('Popped workflow from stack (step not found)', [
                        'restored_step' => $parentContext['step'],
                        'restored_workflow' => $parentContext['workflow'],
                        'restored_active_subflow' => $parentContext['active_subflow'],
                        'stack_depth' => count($workflowStack),
                    ]);
                    
                    // Try again with parent step
                    return $this->execute('', $context);
                }
            }
            
            Log::channel('ai-engine')->error('❌ No valid step found', [
                'workflow' => get_class($workflow),
                'requested_step' => $context->currentStep,
                'available_steps' => count($workflow->getSteps()),
            ]);
            
            return AgentResponse::failure(
                message: 'No valid step to execute',
                context: $context
            );
        }

        Log::channel('ai-engine')->debug('✓ Step found, executing', [
            'step' => $currentStep->getName(),
            'workflow' => get_class($workflow),
        ]);

        // Execute step
        $result = $this->executeStep($currentStep, $context, $message);
        
        Log::channel('ai-engine')->debug('Persisting context after step execution', [
            'session_id' => $context->sessionId,
            'conversation_history_count' => count($context->conversationHistory),
            'workflow_state_keys' => array_keys($context->workflowState),
            'current_step' => $context->currentStep,
        ]);
        
        $context->persist();

        // Handle result - this will set the next step if needed
        return $this->handleStepResult($result, $currentStep, $workflow, $context);
    }

    protected function getWorkflow(string $workflowClass): ?AgentWorkflow
    {
        if (!$workflowClass || !class_exists($workflowClass)) {
            return null;
        }

        try {
            // Use service container to resolve workflow with all dependencies
            // This allows workflows to have additional constructor parameters beyond $ai and $tools
            return app()->make($workflowClass);
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Failed to instantiate workflow', [
                'workflow' => $workflowClass,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function getCurrentStep(AgentWorkflow $workflow, UnifiedActionContext $context): ?WorkflowStep
    {
        // If no current step, start with first step
        if (!$context->currentStep) {
            return $workflow->getFirstStep();
        }

        // Get the step from workflow
        $step = $workflow->getStep($context->currentStep);
        
        // If step not found, check if we're in a subworkflow with prefixed steps
        if (!$step) {
            $activeSubflow = $context->get('active_subflow');
            
            if ($activeSubflow && !empty($activeSubflow['step_prefix'])) {
                // Current step might be a prefixed subworkflow step
                // Need to get the subworkflow instance with the prefix
                $subflowClass = $activeSubflow['workflow_class'];
                $stepPrefix = $activeSubflow['step_prefix'];
                
                Log::channel('ai-engine')->info('Step not found in parent, checking subworkflow', [
                    'parent_workflow' => get_class($workflow),
                    'subflow_class' => $subflowClass,
                    'step_prefix' => $stepPrefix,
                    'current_step' => $context->currentStep,
                ]);
                
                // Instantiate subworkflow with prefix
                try {
                    $ai = app(\LaravelAIEngine\Services\AIEngineService::class);
                    $tools = null; // ToolRegistry is optional
                    $subworkflow = new $subflowClass($ai, $tools, $stepPrefix);
                    
                    // Try to get the step from subworkflow
                    $step = $subworkflow->getStep($context->currentStep);
                    
                    if ($step) {
                        Log::channel('ai-engine')->info('Found step in subworkflow', [
                            'step' => $context->currentStep,
                        ]);
                        return $step;
                    }
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->error('Failed to instantiate subworkflow', [
                        'subflow' => $subflowClass,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // Fallback: try starting from first step
            if ($context->isInSubworkflow()) {
                Log::channel('ai-engine')->info('Step not found, starting from first step', [
                    'workflow' => get_class($workflow),
                    'requested_step' => $context->currentStep,
                ]);
                
                $context->currentStep = null;
                return $workflow->getFirstStep();
            }
        }
        
        return $step;
    }

    protected function executeStep(
        WorkflowStep $step,
        UnifiedActionContext $context,
        string $message
    ): ActionResult {
        Log::channel('ai-engine')->info('🔵 EXECUTING STEP', [
            'step' => $step->getName(),
            'description' => $step->getDescription(),
            'requires_input' => $step->doesRequireUserInput(),
            'message' => $message,
        ]);

        try {
            // Store the current step before execution to detect subflow transitions
            $stepBeforeExecution = $context->currentStep;
            
            // Add user message to context if provided
            if (!empty($message)) {
                $context->addUserMessage($message);
            }

            // Execute the step
            $result = $step->run($context);
            
            // Check if currentStep changed during execution (indicates subflow started)
            $stepAfterExecution = $context->currentStep;
            if ($stepBeforeExecution !== $stepAfterExecution) {
                Log::channel('ai-engine')->info('🔀 Step transition detected during execution', [
                    'executed_step' => $step->getName(),
                    'step_before' => $stepBeforeExecution,
                    'step_after' => $stepAfterExecution,
                    'subflow_started' => true,
                ]);
                
                // Mark that a subflow was started during this step
                $result->metadata['subflow_started'] = true;
                $result->metadata['new_step'] = $stepAfterExecution;
            }

            Log::channel('ai-engine')->info('✅ STEP COMPLETED', [
                'step' => $step->getName(),
                'success' => $result->success,
                'needs_user_input' => $result->getMetadata('needs_user_input', false),
                'has_metadata' => !empty($result->metadata),
                'metadata_keys' => array_keys($result->metadata),
                'message_preview' => substr($result->message ?? '', 0, 100),
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('❌ Step execution failed with exception', [
                'step' => $step->getName(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);

            return ActionResult::failure(
                error: "Step execution failed: {$e->getMessage()}",
                metadata: [
                    'step' => $step->getName(),
                    'exception' => get_class($e),
                ]
            );
        }
    }

    protected function handleStepResult(
        ActionResult $result,
        WorkflowStep $step,
        AgentWorkflow $workflow,
        UnifiedActionContext $context
    ): AgentResponse {
        // Check for user cancellation request
        if (method_exists($workflow, 'checkForCancellation') && $workflow->checkForCancellation($context)) {
            $cancelResult = $workflow->handleCancellation($context);
            $context->persist();
            
            return AgentResponse::failure(
                message: "Workflow cancelled. How can I help you?",
                data: $cancelResult->data,
                context: $context
            );
        }
        
        // Check if result needs user input
        $needsUserInput = $result->getMetadata('needs_user_input', false);
        
        // If needs user input, return immediately without moving to next step
        // Keep the current step so it executes again when user responds
        if ($needsUserInput) {
            Log::channel('ai-engine')->info('Workflow needs user input, staying on current step', [
                'step' => $step->getName(),
                'message' => substr($result->message ?? '', 0, 100),
                'current_step' => $context->currentStep,
            ]);
            
            // Don't change the current step - stay on it for next execution
            return AgentResponse::needsUserInput(
                message: $result->message,
                data: $result->data,
                context: $context
            );
        }
        
        // Check if a subworkflow was started during step execution
        if ($result->getMetadata('subflow_started', false)) {
            // A subflow was started - currentStep was changed during execution
            // Continue execution with the new step (subflow's first step)
            $newStep = $result->getMetadata('new_step');
            
            Log::channel('ai-engine')->info('🔀 Subflow started during step execution, continuing with new step', [
                'parent_step' => $step->getName(),
                'new_step' => $newStep,
                'current_step' => $context->currentStep,
            ]);
            
            // Continue execution with the new step
            return $this->execute('', $context);
        }
        
        // Check if a subworkflow was pushed onto the stack during step execution
        if ($context->isInSubworkflow()) {
            // A subworkflow was started - the context now points to the subworkflow
            // We need to execute the first step of the subworkflow
            Log::channel('ai-engine')->info('Subworkflow detected, switching context', [
                'parent_workflow' => $context->getParentWorkflow()['workflow'] ?? 'unknown',
                'subworkflow' => $context->currentWorkflow,
            ]);
            
            // The subworkflow has been started, continue execution with new workflow
            return $this->execute('', $context);
        }
        
        // Determine next step based on result
        $nextStepName = $result->success
            ? $step->getOnSuccess() 
            : $step->getOnFailure();

        // Check if we just completed a subworkflow
        $activeSubflow = $context->get('active_subflow');
        if ($activeSubflow && ($nextStepName === 'complete' || !$nextStepName)) {
            $stepPrefix = $activeSubflow['step_prefix'] ?? '';
            $currentStepName = $step->getName();
            
            // If current step has the prefix, we just completed the subworkflow
            if ($stepPrefix && str_starts_with($currentStepName, $stepPrefix)) {
                Log::channel('ai-engine')->info('🎯 Subworkflow completed, returning to parent', [
                    'subflow_step' => $currentStepName,
                    'step_prefix' => $stepPrefix,
                    'parent_field' => $activeSubflow['field_name'],
                ]);
                
                // Store the created entity ID from result data
                $fieldName = $activeSubflow['field_name'];
                
                // Extract entity ID - try field-specific key first, then generic fallbacks
                $entityId = $result->data[$fieldName . '_id'] ?? $result->data['entity_id'] ?? null;
                
                // If not found, search all data keys for any ID field
                if (!$entityId && !empty($result->data)) {
                    foreach ($result->data as $key => $value) {
                        if (str_ends_with($key, '_id') && is_numeric($value)) {
                            $entityId = $value;
                            break;
                        }
                    }
                }
                
                if ($entityId) {
                    $context->set($fieldName . '_id', $entityId);
                    
                    // IMPORTANT: Also update collected_data so the parent workflow can access it
                    $collectedData = $context->get('collected_data', []);
                    $collectedData[$fieldName . '_id'] = $entityId;
                    $context->set('collected_data', $collectedData);
                    
                    Log::channel('ai-engine')->info('Stored entity ID from subworkflow', [
                        'field' => $fieldName,
                        'entity_id' => $entityId,
                        'updated_collected_data' => true,
                    ]);
                }
                
                // Pop parent workflow context from stack
                $workflowStack = $context->get('workflow_stack', []);
                
                if (!empty($workflowStack)) {
                    $parentContext = array_pop($workflowStack);
                    $context->set('workflow_stack', $workflowStack);
                    
                    // Restore parent workflow context
                    $context->currentStep = $parentContext['step'];
                    $context->currentWorkflow = $parentContext['workflow'];
                    $context->set('current_workflow', $parentContext['workflow']);
                    $context->set('active_subflow', $parentContext['active_subflow']);
                    
                    // CRITICAL: Merge collected_data - restore parent data + keep entity ID from subflow
                    $currentCollectedData = $context->get('collected_data', []);
                    $parentCollectedData = $parentContext['collected_data'] ?? [];
                    
                    // Merge: parent data first, then add entity ID if it exists
                    $mergedData = $parentCollectedData;
                    if ($entityId) {
                        $mergedData[$fieldName . '_id'] = $entityId;
                    }
                    $context->set('collected_data', $mergedData);
                    
                    Log::channel('ai-engine')->info('Popped workflow from stack', [
                        'completed_subflow_prefix' => $stepPrefix,
                        'restored_step' => $parentContext['step'],
                        'restored_workflow' => $parentContext['workflow'],
                        'restored_active_subflow' => $parentContext['active_subflow'],
                        'restored_collected_data' => $parentCollectedData,
                        'stack_depth' => count($workflowStack),
                        'field_name' => $fieldName,
                    ]);
                } else {
                    // Fallback: no stack, clear subflow state
                    $context->forget('active_subflow');
                    
                    Log::channel('ai-engine')->warning('No workflow stack found, clearing subflow state', [
                        'field_name' => $fieldName,
                    ]);
                }
                
                $context->persist();
                
                // Continue execution to resolve the entity in parent workflow
                return $this->execute('', $context);
            }
        }

        // Check if workflow is complete
        if (!$nextStepName || $nextStepName === 'complete') {
            // Call cleanup method on workflow before clearing state
            if (method_exists($workflow, 'cleanupAfterCompletion')) {
                $workflow->cleanupAfterCompletion($context);
            } else {
                // Fallback cleanup if method doesn't exist
                $context->workflowState = [];
                $context->currentWorkflow = null;
                $context->currentStep = null;
            }
            
            $context->persist();

            return AgentResponse::success(
                message: $result->message ?? 'Workflow completed successfully',
                data: $result->data,
                context: $context
            );
        }

        // Check if workflow encountered error
        if ($nextStepName === 'error' || $nextStepName === 'cancel') {
            // Call cleanup method on workflow before clearing state
            if (method_exists($workflow, 'cleanupAfterCompletion')) {
                $workflow->cleanupAfterCompletion($context);
            } else {
                // Fallback cleanup if method doesn't exist
                $context->workflowState = [];
                $context->currentWorkflow = null;
                $context->currentStep = null;
            }
            
            $context->persist();

            return AgentResponse::failure(
                message: $result->message ?? 'Workflow failed',
                data: $result->data,
                context: $context
            );
        }

        // Get next step - check if it's in a subworkflow first
        $nextStep = $workflow->getStep($nextStepName);
        
        // If not found in parent workflow, check if we're in a subworkflow
        if (!$nextStep) {
            $activeSubflow = $context->get('active_subflow');
            
            if ($activeSubflow && !empty($activeSubflow['step_prefix'])) {
                $subflowClass = $activeSubflow['workflow_class'];
                $stepPrefix = $activeSubflow['step_prefix'];
                
                Log::channel('ai-engine')->info('Next step not found in parent, checking subworkflow', [
                    'next_step' => $nextStepName,
                    'subflow_class' => $subflowClass,
                    'step_prefix' => $stepPrefix,
                ]);
                
                // Instantiate subworkflow with prefix
                try {
                    $ai = app(\LaravelAIEngine\Services\AIEngineService::class);
                    $tools = null;
                    $subworkflow = new $subflowClass($ai, $tools, $stepPrefix);
                    
                    $nextStep = $subworkflow->getStep($nextStepName);
                    
                    if ($nextStep) {
                        Log::channel('ai-engine')->info('Found next step in subworkflow', [
                            'next_step' => $nextStepName,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->error('Failed to instantiate subworkflow for next step', [
                        'subflow' => $subflowClass,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        if (!$nextStep) {
            Log::channel('ai-engine')->error('Next step not found in any workflow', [
                'next_step' => $nextStepName,
                'workflow' => get_class($workflow),
                'has_active_subflow' => !empty($activeSubflow),
            ]);

            return AgentResponse::failure(
                message: 'Workflow configuration error: next step not found',
                context: $context
            );
        }

        // Update context for next step
        $context->currentStep = $nextStepName;
        $context->persist();

        // If current result needs user input, return that response
        if ($needsUserInput) {
            return AgentResponse::needsUserInput(
                message: $result->message ?? 'Please provide additional information',
                data: $result->data,
                actions: $result->metadata['actions'] ?? null,
                context: $context,
                nextStep: $nextStepName
            );
        }

        // Check if next step requires user input
        if ($nextStep->doesRequireUserInput()) {
            return AgentResponse::needsUserInput(
                message: $result->message ?? 'Please provide additional information',
                data: $result->data,
                actions: $result->metadata['actions'] ?? null,
                context: $context,
                nextStep: $nextStepName
            );
        }

        // If next step doesn't require input, execute it immediately
        return $this->execute('', $context);
    }

    public function startWorkflow(
        string $workflowClass,
        UnifiedActionContext $context,
        string $initialMessage = ''
    ): AgentResponse {
        // Check if we're re-starting the same workflow - if so, clear all workflow data
        $previousWorkflow = $context->currentWorkflow;
        $isRestart = ($previousWorkflow === $workflowClass);
        
        if ($isRestart) {
            Log::channel('ai-engine')->info('Re-starting same workflow - clearing previous data', [
                'workflow' => $workflowClass,
                'session_id' => $context->sessionId,
            ]);
        }
        
        $context->currentWorkflow = $workflowClass;
        $context->workflowState = [];
        
        // Clear workflow-specific data to start fresh
        $context->forget('collected_data');
        $context->forget('validated_products');
        $context->forget('missing_products');
        $context->forget('customer_id');
        $context->forget('products');
        $context->forget('confirmation_message_shown');
        $context->forget('user_confirmed_action');
        $context->forget('awaiting_confirmation');
        $context->forget('asked_for_customer');
        $context->forget('asked_for_products');
        
        // Get workflow instance to determine first step
        $workflow = $this->getWorkflow($workflowClass);
        if ($workflow) {
            $firstStep = $workflow->getFirstStep();
            $context->currentStep = $firstStep ? $firstStep->getName() : null;
            
            Log::channel('ai-engine')->info('Workflow initialized', [
                'workflow' => $workflowClass,
                'first_step' => $context->currentStep,
            ]);
        } else {
            $context->currentStep = null;
        }
        
        $context->persist();

        return $this->execute($initialMessage, $context);
    }
    
    public function continueWorkflow(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Continuing workflow', [
            'session_id' => $context->sessionId,
            'workflow' => $context->currentWorkflow,
            'current_step' => $context->currentStep,
            'message' => $message,
        ]);
        
        // Execute the workflow with the user's message
        return $this->execute($message, $context, $options);
    }
    
    /**
     * Check for infinite loop protection
     */
    protected function checkInfiniteLoopProtection(UnifiedActionContext $context): bool
    {
        $currentStepKey = $context->currentWorkflow . '::' . ($context->currentStep ?? 'start');
        $stepExecutionCount = $context->get('step_execution_count', []);
        $executionCount = $stepExecutionCount[$currentStepKey] ?? 0;
        $maxStepExecutions = config('ai-engine.workflow.max_step_executions', 20);
        
        if ($executionCount >= $maxStepExecutions) {
            Log::channel('ai-engine')->error('⚠️ Step execution limit exceeded - possible infinite loop', [
                'workflow' => $context->currentWorkflow,
                'step' => $context->currentStep,
                'executions' => $executionCount,
                'max' => $maxStepExecutions,
            ]);
            return false;
        }
        
        Log::channel('ai-engine')->debug('Step execution count', [
            'step' => $context->currentStep,
            'count' => $executionCount + 1,
            'max' => $maxStepExecutions,
        ]);
        
        // Increment step execution counter
        $stepExecutionCount[$currentStepKey] = $executionCount + 1;
        $context->set('step_execution_count', $stepExecutionCount);
        
        return true;
    }
}
