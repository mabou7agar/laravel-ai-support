<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Contracts\ActionHandlerInterface;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\DTOs\ActionResponse;
use LaravelAIEngine\Enums\ActionTypeEnum;
use LaravelAIEngine\Exceptions\ActionHandlerNotFoundException;
use LaravelAIEngine\Exceptions\ActionValidationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ActionManager
{
    protected Collection $handlers;

    public function __construct()
    {
        $this->handlers = collect();
    }

    /**
     * Register an action handler
     */
    public function registerHandler(ActionHandlerInterface $handler): void
    {
        $this->handlers->push($handler);
        
        // Sort handlers by priority (highest first)
        $this->handlers = $this->handlers->sortByDesc(fn($handler) => $handler->priority());
    }

    /**
     * Execute an interactive action
     */
    public function executeAction(InteractiveAction $action, array $payload = []): ActionResponse
    {
        try {
            // Find appropriate handler
            $handler = $this->findHandler($action->type->value);
            
            if (!$handler) {
                throw new ActionHandlerNotFoundException(
                    "No handler found for action type: {$action->type->value}"
                );
            }

            // Validate action and payload
            $errors = $handler->validate($action, $payload);
            if (!empty($errors)) {
                throw new ActionValidationException(
                    "Action validation failed",
                    $errors
                );
            }

            // Log action execution
            Log::info('Executing interactive action', [
                'action_id' => $action->id,
                'action_type' => $action->type->value,
                'handler' => get_class($handler)
            ]);

            // Execute the action
            $response = $handler->handle($action, $payload);

            // Log response
            Log::info('Action execution completed', [
                'action_id' => $action->id,
                'success' => $response->success,
                'message' => $response->message
            ]);

            return $response;

        } catch (ActionValidationException $e) {
            Log::warning('Action validation failed', [
                'action_id' => $action->id,
                'errors' => $e->getErrors()
            ]);

            return ActionResponse::error(
                $action->id,
                $action->type,
                $e->getMessage(),
                $e->getErrors()
            );

        } catch (\Exception $e) {
            Log::error('Action execution failed', [
                'action_id' => $action->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return ActionResponse::error(
                $action->id,
                $action->type,
                'Action execution failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Validate an action without executing it
     */
    public function validateAction(InteractiveAction $action, array $payload = []): array
    {
        $handler = $this->findHandler($action->type->value);
        
        if (!$handler) {
            return ['handler' => 'No handler found for action type: ' . $action->type->value];
        }

        return $handler->validate($action, $payload);
    }

    /**
     * Find handler for action type
     */
    protected function findHandler(string $actionType): ?ActionHandlerInterface
    {
        return $this->handlers->first(fn($handler) => $handler->supports($actionType));
    }

    /**
     * Get all registered handlers
     */
    public function getHandlers(): Collection
    {
        return $this->handlers;
    }

    /**
     * Get supported action types
     */
    public function getSupportedActionTypes(): array
    {
        return $this->handlers
            ->flatMap(function ($handler) {
                return collect(ActionTypeEnum::cases())
                    ->filter(fn($type) => $handler->supports($type->value))
                    ->pluck('value');
            })
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Create action from array data
     */
    public function createAction(array $data): InteractiveAction
    {
        return InteractiveAction::fromArray($data);
    }

    /**
     * Create multiple actions from array data
     */
    public function createActions(array $actionsData): array
    {
        return array_map([$this, 'createAction'], $actionsData);
    }

    /**
     * Batch execute multiple actions
     */
    public function executeActions(array $actions, array $payload = []): array
    {
        $responses = [];

        foreach ($actions as $action) {
            if (is_array($action)) {
                $action = $this->createAction($action);
            }

            $responses[] = $this->executeAction($action, $payload);
        }

        return $responses;
    }

    /**
     * Get action statistics
     */
    public function getActionStats(): array
    {
        return [
            'total_handlers' => $this->handlers->count(),
            'supported_types' => count($this->getSupportedActionTypes()),
            'handlers_by_priority' => $this->handlers
                ->groupBy(fn($handler) => $handler->priority())
                ->map(fn($group) => $group->count())
                ->toArray()
        ];
    }
}
