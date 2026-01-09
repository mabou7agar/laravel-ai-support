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

    public function __construct()
    {
        $this->maxSteps = config('ai-agent.agent_mode.max_steps', 10);
        $this->maxRetries = config('ai-agent.agent_mode.max_retries', 3);
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
        ]);

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
            return AgentResponse::failure(
                message: 'No valid step to execute',
                context: $context
            );
        }

        // Execute step
        $result = $this->executeStep($currentStep, $context, $message);

        // Update context
        $context->currentStep = $currentStep->getName();
        
        Log::channel('ai-engine')->debug('Persisting context after step execution', [
            'session_id' => $context->sessionId,
            'conversation_history_count' => count($context->conversationHistory),
            'workflow_state_keys' => array_keys($context->workflowState),
        ]);
        
        $context->persist();

        // Handle result
        return $this->handleStepResult($result, $currentStep, $workflow, $context);
    }

    protected function getWorkflow(string $workflowClass): ?AgentWorkflow
    {
        if (!$workflowClass || !class_exists($workflowClass)) {
            return null;
        }

        try {
            return app($workflowClass);
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
        return $workflow->getStep($context->currentStep);
    }

    protected function executeStep(
        WorkflowStep $step,
        UnifiedActionContext $context,
        string $message
    ): ActionResult {
        Log::channel('ai-engine')->info('Executing workflow step', [
            'step' => $step->getName(),
            'requires_input' => $step->doesRequireUserInput(),
        ]);

        try {
            // Add user message to context if provided
            if (!empty($message)) {
                $context->addUserMessage($message);
            }

            // Execute the step
            $result = $step->run($context);

            Log::channel('ai-engine')->info('Step execution completed', [
                'step' => $step->getName(),
                'success' => $result->success,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Step execution failed', [
                'step' => $step->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
        // Check if result needs user input
        $needsUserInput = $result->getMetadata('needs_user_input', false);
        
        // If needs user input, return immediately without moving to next step
        if ($needsUserInput) {
            Log::channel('ai-engine')->info('Workflow needs user input', [
                'step' => $step->getName(),
                'message' => $result->message,
                'data' => $result->data,
            ]);
            
            return AgentResponse::needsUserInput(
                message: $result->message,
                data: $result->data,
                context: $context
            );
        }
        
        // Determine next step based on result
        $nextStepName = $result->success
            ? $step->getOnSuccess() 
            : $step->getOnFailure();

        // Check if workflow is complete
        if (!$nextStepName || $nextStepName === 'complete') {
            $context->currentWorkflow = null;
            $context->currentStep = null;
            $context->persist();

            return AgentResponse::success(
                message: $result->message ?? 'Workflow completed successfully',
                data: $result->data,
                context: $context
            );
        }

        // Check if workflow encountered error
        if ($nextStepName === 'error' || $nextStepName === 'cancel') {
            $context->currentWorkflow = null;
            $context->currentStep = null;
            $context->persist();

            return AgentResponse::failure(
                message: $result->message ?? 'Workflow failed',
                data: $result->data,
                context: $context
            );
        }

        // Get next step
        $nextStep = $workflow->getStep($nextStepName);
        
        if (!$nextStep) {
            Log::channel('ai-engine')->error('Next step not found', [
                'next_step' => $nextStepName,
                'workflow' => get_class($workflow),
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
        $context->currentWorkflow = $workflowClass;
        $context->currentStep = null;
        $context->workflowState = [];
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
}
