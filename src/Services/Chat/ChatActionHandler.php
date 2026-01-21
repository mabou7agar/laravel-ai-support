<?php

namespace LaravelAIEngine\Services\Chat;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Actions\ActionManager;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\PendingActionService;
use LaravelAIEngine\Services\Node\RemoteActionService;
use LaravelAIEngine\Services\Actions\ActionExecutionPipeline;

class ChatActionHandler
{
    public function __construct(
        protected AIEngineService $aiEngineService,
        protected ActionManager $actionManager,
        protected ChatResponseFormatter $formatter,
        protected ?PendingActionService $pendingActionService = null,
        protected ?RemoteActionService $remoteActionService = null
    ) {
    }

    /**
     * Lazy load RemoteActionService
     */
    protected function getRemoteActionService(): ?RemoteActionService
    {
        if ($this->remoteActionService === null && app()->bound(RemoteActionService::class)) {
            $this->remoteActionService = app(RemoteActionService::class);
        }
        return $this->remoteActionService;
    }

    /**
     * Lazy load PendingActionService
     */
    protected function getPendingActionService(): ?PendingActionService
    {
        if ($this->pendingActionService === null && app()->bound(PendingActionService::class)) {
            $this->pendingActionService = app(PendingActionService::class);
        }
        return $this->pendingActionService;
    }

    public function handle(
        string $message,
        array $intentAnalysis,
        string $sessionId,
        string $engine,
        string $model,
        $userId = null
    ): ?AIResponse {
        // Dependencies
        $pendingActionService = $this->getPendingActionService();
        $pendingAction = $pendingActionService?->get($sessionId);

        $cachedActionData = $pendingAction ? [
            'id' => $pendingAction->id,
            'type' => $pendingAction->type->value,
            'label' => $pendingAction->label,
            'description' => $pendingAction->description,
            'data' => $pendingAction->data,
            'missing_fields' => $pendingAction->data['missing_fields'] ?? [],
            'is_incomplete' => !empty($pendingAction->data['missing_fields'] ?? []),
        ] : null;

        // Handle greeting intent immediately
        if (($intentAnalysis['intent'] ?? '') === 'greeting') {
            return null;
        }

        switch ($intentAnalysis['intent']) {
            case 'new_workflow':
            case 'new_request':
            case 'create':
                // Always clear pending actions for new workflow/request
                if ($cachedActionData) {
                    Log::channel('ai-engine')->info('New workflow/request detected, clearing pending action', [
                        'intent' => $intentAnalysis['intent'],
                        'old_action' => $cachedActionData['label'],
                        'session_id' => $sessionId,
                    ]);
                    $pendingActionService?->delete($sessionId);
                }
                // Return null to allow action generation to proceed in ChatService
                return null;

            case 'provide_data':
                // For provide_data, only clear if it's for a different action
                $suggestedActionId = $intentAnalysis['suggested_action_id'] ?? null;
                if ($cachedActionData && $suggestedActionId) {
                    // Extract base action ID from cached action
                    $cachedBaseActionId = preg_replace('/_[a-f0-9]{15}$/', '', $cachedActionData['id']);

                    // If AI suggested a different action, clear the old one
                    if ($cachedBaseActionId !== $suggestedActionId) {
                        Log::channel('ai-engine')->info('Different action detected, clearing old pending action', [
                            'old_action' => $cachedActionData['label'],
                            'old_action_id' => $cachedBaseActionId,
                            'new_action_id' => $suggestedActionId,
                        ]);
                        $pendingActionService?->delete($sessionId);
                        $cachedActionData = null;
                    }
                }

                if ($cachedActionData && !empty($intentAnalysis['extracted_data'])) {
                    return $this->handleProvideData($cachedActionData, $intentAnalysis, $sessionId, $engine, $model, $userId);
                }
                break;

            case 'confirm':
                if ($cachedActionData) {
                    return $this->handleConfirm($cachedActionData, $intentAnalysis, $sessionId, $engine, $model, $userId);
                }
                break;

            case 'reject':
                if ($cachedActionData) {
                    Log::channel('ai-engine')->info('Rejection detected, canceling pending action');
                    $pendingActionService?->delete($sessionId);

                    return new AIResponse(
                        content: "Action canceled. How else can I help you?",
                        engine: EngineEnum::from($engine),
                        model: EntityEnum::from($model),
                        metadata: ['action_canceled' => true, 'intent_analysis' => $intentAnalysis],
                        success: true
                    );
                }
                break;

            case 'modify':
                if ($cachedActionData && !empty($intentAnalysis['extracted_data'])) {
                    return $this->handleModify($cachedActionData, $intentAnalysis, $sessionId, $engine, $model);
                }
                break;
        }

        return null;
    }

    protected function handleProvideData(array $cachedActionData, array $intentAnalysis, string $sessionId, string $engine, string $model, $userId): ?AIResponse
    {
        Log::channel('ai-engine')->info('Additional data provided, updating pending action', [
            'data' => $intentAnalysis['extracted_data'],
            'is_incomplete' => $cachedActionData['is_incomplete'] ?? false,
        ]);

        // Validation suggestions
        if (!empty($intentAnalysis['validation_suggestions'])) {
            $warnings = [];
            foreach ($intentAnalysis['validation_suggestions'] as $suggestion) {
                $warnings[] = "⚠️ {$suggestion['field']}: {$suggestion['issue']} - {$suggestion['suggestion']}";
            }
            return new AIResponse(
                content: "I noticed some potential issues with the data:\n\n" . implode("\n", $warnings) . "\n\nWould you like to correct these, or proceed anyway?",
                engine: EngineEnum::from($engine),
                model: EntityEnum::from($model),
                metadata: ['validation_warnings' => $intentAnalysis['validation_suggestions']],
                success: true
            );
        }

        $stillMissing = [];
        $existingParams = $cachedActionData['data']['params'] ?? [];
        $newData = $intentAnalysis['extracted_data'];

        // Field mapping
        if (!empty($intentAnalysis['field_mapping'])) {
            $mappedData = [];
            foreach ($newData as $key => $value) {
                $targetField = $intentAnalysis['field_mapping'][$key] ?? $key;
                $mappedData[$targetField] = $value;
            }
            $newData = $mappedData;
        }

        $mergedParams = array_merge($existingParams, $newData);

        // Normalize data
        $modelClass = $cachedActionData['data']['model_class'] ?? null;
        if ($modelClass && method_exists($modelClass, 'normalizeAIData')) {
            try {
                $reflection = new \ReflectionMethod($modelClass, 'normalizeAIData');
                $reflection->setAccessible(true);
                $mergedParams = $reflection->invoke(null, $mergedParams);
            } catch (\Exception $e) {
            }
        }

        $cachedActionData['data']['params'] = $mergedParams;

        // Check missing fields
        $missingFields = $cachedActionData['missing_fields'] ?? [];
        if (!empty($intentAnalysis['satisfies_fields'])) {
            $stillMissing = array_diff($missingFields, $intentAnalysis['satisfies_fields']);
        } else {
            // Fallback manual check
            foreach ($missingFields as $field) {
                if (!isset($mergedParams[$field]) || empty($mergedParams[$field])) {
                    $stillMissing[] = $field;
                }
            }
        }

        if (empty($stillMissing)) {
            $cachedActionData['is_incomplete'] = false;
            $cachedActionData['data']['ready_to_execute'] = true;
            unset($cachedActionData['missing_fields']);
        } else {
            $cachedActionData['missing_fields'] = $stillMissing;
        }

        // Update DB
        $this->getPendingActionService()?->updateParams($sessionId, $mergedParams);

        // If complete, present for confirmation
        if (empty($stillMissing)) {
            $params = $cachedActionData['data']['params'] ?? [];
            $modelName = class_basename($modelClass);

            $context = [
                'field_labels' => $intentAnalysis['field_labels'] ?? [],
                'important_fields' => $intentAnalysis['important_fields'] ?? [],
                'priority_fields' => $intentAnalysis['priority_fields'] ?? [],
                'field_types' => $intentAnalysis['field_types'] ?? [],
            ];
            $summary = $this->formatter->formatModelSummary($params, $modelName, $context);

            $description = "**Confirm {$modelName} Creation**\n\n{$summary}\n**Please review the information above.**\nReply 'yes' to create, or tell me what you'd like to change.";

            return new AIResponse(
                content: $description,
                engine: EngineEnum::from($engine),
                model: EntityEnum::from($model),
                metadata: [
                    'action_completed' => true,
                    'pending_action' => $cachedActionData,
                    'intent_analysis' => $intentAnalysis
                ],
                success: true
            );
        }

        return null;
    }

    protected function handleModify(array $cachedActionData, array $intentAnalysis, string $sessionId, string $engine, string $model): ?AIResponse
    {
        $oldParams = $cachedActionData['data']['params'] ?? [];
        $modifications = $intentAnalysis['extracted_data'];
        $targetPath = $intentAnalysis['modification_target'] ?? null;

        $newParams = $oldParams;
        if ($targetPath && !empty($modifications)) {
            $dotPath = preg_replace('/\[(\d+)\]/', '.$1', $targetPath);
            foreach ($modifications as $key => $value) {
                $fullPath = str_ends_with($dotPath, $key) ? $dotPath : $dotPath . '.' . $key;
                Arr::set($newParams, $fullPath, $value);
            }
        } else {
            $newParams = array_replace_recursive($oldParams, $modifications);
        }

        $cachedActionData['data']['params'] = $newParams;
        $this->getPendingActionService()?->updateParams($sessionId, $newParams);

        $updatedSummary = $this->formatter->formatModelSummary(
            $newParams,
            $cachedActionData['label'],
            ['priority_fields' => array_keys($modifications)]
        );

        return new AIResponse(
            content: "Updated! Here's the revised information:\n\n" . $updatedSummary,
            engine: EngineEnum::from($engine),
            model: EntityEnum::from($model),
            metadata: ['modification_applied' => true, 'updated_params' => $newParams],
            success: true
        );
    }

    protected function handleConfirm(array $cachedActionData, array $intentAnalysis, string $sessionId, string $engine, string $model, $userId): ?AIResponse
    {
        $autoExecute = $intentAnalysis['auto_execute'] ?? false;
        $confidenceThreshold = config('ai-engine.actions.thresholds.auto_execute_confidence', 0.95);
        $shouldAutoExecute = $autoExecute && ($intentAnalysis['confidence'] ?? 0) > $confidenceThreshold;

        // Execute value
        $action = new InteractiveAction(
            id: $cachedActionData['id'],
            type: ActionTypeEnum::from($cachedActionData['type']),
            label: $cachedActionData['label'],
            description: $cachedActionData['description'] ?? '',
            data: $cachedActionData['data']
        );

        $executionResult = $this->executeSmartAction($action, $userId);
        $this->getPendingActionService()?->markExecuted($sessionId);

        if ($executionResult['success']) {
            $message = $executionResult['message'];

            if (!empty($intentAnalysis['next_actions'])) {
                $message .= "\n\n**What would you like to do next?**\n";
                foreach ($intentAnalysis['next_actions'] as $nextAction) {
                    $priority = $nextAction['priority'] ?? 'medium';
                    $emoji = $priority === 'high' ? '⭐' : ($priority === 'medium' ? '💡' : '📌');
                    $message .= "{$emoji} {$nextAction['label']}\n";
                }
            }

            return new AIResponse(
                content: $message,
                engine: EngineEnum::from($engine),
                model: EntityEnum::from($model),
                metadata: [
                    'action_executed' => true,
                    'auto_executed' => $shouldAutoExecute,
                    'next_actions' => $intentAnalysis['next_actions'] ?? [],
                    'intent_analysis' => $intentAnalysis
                ],
                success: true
            );
        } else {
            return new AIResponse(
                content: "❌ Failed to execute action: " . ($executionResult['error'] ?? 'Unknown error'),
                engine: EngineEnum::from($engine),
                model: EntityEnum::from($model),
                error: $executionResult['error'] ?? 'Execution failed',
                success: false
            );
        }
    }

    public function executeSmartAction(InteractiveAction $action, $userId): array
    {
        $executor = $action->data['executor'] ?? null;
        $params = $action->data['params'] ?? [];
        $modelClass = $action->data['model_class'] ?? null;

        Log::channel('ai-engine')->info('Executing smart action inline', [
            'action' => $action->label,
            'executor' => $executor,
        ]);

        switch ($executor) {
            case 'model.dynamic':
                return $this->executeDynamicModelAction($modelClass, $params, $userId);
            case 'model.remote':
                return $this->executeRemoteModelAction($action, $params, $userId);
            case 'workflow':
                if ($this->actionManager) {
                    $pipeline = app(ActionExecutionPipeline::class);
                    $sessionId = $action->data['session_id'] ?? uniqid('workflow_');
                    $result = $pipeline->execute($action, $userId, $sessionId);
                    return [
                        'success' => $result->success,
                        'message' => $result->message,
                        'data' => $result->data,
                        'error' => $result->error,
                    ];
                }
                return ['success' => false, 'error' => 'ActionManager not available'];
            case 'email.reply':
                return $this->executeEmailReply($params, $userId);
            default:
                return ['success' => false, 'error' => "Unknown executor: {$executor}"];
        }
    }

    /**
     * Backward compatibility
     */
    protected function executeSmartActionInline(InteractiveAction $action, $userId): array
    {
        return $this->executeSmartAction($action, $userId);
    }

    protected function executeDynamicModelAction(?string $modelClass, array $params, $userId): array
    {
        if (!$modelClass)
            return ['success' => false, 'error' => 'Invalid model class'];
        $params['user_id'] = $userId;

        if (class_exists($modelClass)) {
            try {
                $reflection = new \ReflectionClass($modelClass);
                if (!$reflection->hasMethod('executeAI'))
                    return ['success' => false, 'error' => 'No executeAI method'];

                $method = $reflection->getMethod('executeAI');
                if ($method->isStatic()) {
                    $result = $modelClass::executeAI('create', $params);
                } else {
                    $model = new $modelClass();
                    $result = $model->executeAI('create', $params);
                }

                $modelName = class_basename($modelClass);
                $summary = "✅ **{$modelName} Created Successfully!**\nDetails:\n" . json_encode($result['data'] ?? $result);

                return [
                    'success' => true,
                    'message' => $summary,
                    'data' => $result,
                    'model' => $modelClass
                ];
            } catch (\Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }
        return ['success' => false, 'error' => 'Class not found'];
    }

    protected function executeRemoteModelAction(InteractiveAction $action, array $params, $userId): array
    {
        try {
            $service = $this->getRemoteActionService();
            if (!$service)
                return ['success' => false, 'error' => 'Remote service unavailable'];

            $nodeSlug = $action->data['node_slug'] ?? null;
            $modelClass = $action->data['model_class'] ?? null;
            if (!$nodeSlug || !$modelClass)
                return ['success' => false, 'error' => 'Missing node info'];

            $result = $service->executeOn($nodeSlug, 'model.create', [
                'executor' => 'model.create',
                'model_class' => $modelClass,
                'params' => array_merge($params, ['user_id' => $userId]),
            ]);

            $actionResult = $result['data']['result'] ?? [];
            if (($actionResult['success'] ?? false) || ($result['status_code'] ?? 0) === 200) {
                return ['success' => true, 'message' => $actionResult['message'] ?? 'Remote action success', 'data' => $actionResult['data'] ?? []];
            }
            return ['success' => false, 'error' => $actionResult['error'] ?? 'Remote failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function executeEmailReply(array $params, $userId): array
    {
        return ['success' => false, 'error' => 'Email reply not fully implemented in handler yet'];
    }
}
