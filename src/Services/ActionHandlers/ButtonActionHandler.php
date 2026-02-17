<?php

namespace LaravelAIEngine\Services\ActionHandlers;

use LaravelAIEngine\Contracts\ActionHandlerInterface;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\DTOs\ActionResponse;
use LaravelAIEngine\Enums\ActionTypeEnum;
use Illuminate\Support\Facades\Event;

class ButtonActionHandler implements ActionHandlerInterface
{
    /**
     * Handle button action execution
     */
    public function handle(InteractiveAction $action, array $payload = []): ActionResponse
    {
        // Fire event for button action
        Event::dispatch('ai.action.button.clicked', [
            'action' => $action,
            'payload' => $payload
        ]);

        // Get action data
        $actionData = $action->data['action'] ?? null;
        
        if (!$actionData) {
            return ActionResponse::error(
                $action->id,
                $action->type,
                'No action specified for button'
            );
        }

        // Handle different button action types
        switch ($actionData['type'] ?? 'callback') {
            case 'url':
                return $this->handleUrlAction($action, $actionData, $payload);
            
            case 'callback':
                return $this->handleCallbackAction($action, $actionData, $payload);
            
            case 'submit':
                return $this->handleSubmitAction($action, $actionData, $payload);
            
            case 'api':
                return $this->handleApiAction($action, $actionData, $payload);
            
            default:
                return ActionResponse::error(
                    $action->id,
                    $action->type,
                    'Unknown button action type: ' . ($actionData['type'] ?? 'none')
                );
        }
    }

    /**
     * Handle URL navigation action
     */
    protected function handleUrlAction(InteractiveAction $action, array $actionData, array $payload): ActionResponse
    {
        $url = $actionData['url'] ?? null;
        
        if (!$url) {
            return ActionResponse::error(
                $action->id,
                $action->type,
                'URL not specified for button action'
            );
        }

        return ActionResponse::redirect(
            $action->id,
            $action->type,
            $url,
            $action->successMessage ?? 'Redirecting...'
        );
    }

    /**
     * Handle callback function action
     */
    protected function handleCallbackAction(InteractiveAction $action, array $actionData, array $payload): ActionResponse
    {
        $callback = $actionData['callback'] ?? null;
        
        if (!$callback) {
            return ActionResponse::error(
                $action->id,
                $action->type,
                'Callback not specified for button action'
            );
        }

        try {
            // Execute callback if it's callable
            if (is_callable($callback)) {
                $result = call_user_func($callback, $action, $payload);
                
                if ($result instanceof ActionResponse) {
                    return $result;
                }
                
                return ActionResponse::success(
                    $action->id,
                    $action->type,
                    $action->successMessage ?? 'Action completed successfully',
                    is_array($result) ? $result : ['result' => $result]
                );
            }

            // Fire event for callback handling
            $result = Event::dispatch('ai.action.callback', [
                'callback' => $callback,
                'action' => $action,
                'payload' => $payload
            ]);

            return ActionResponse::success(
                $action->id,
                $action->type,
                $action->successMessage ?? 'Callback executed successfully',
                ['result' => $result]
            );

        } catch (\Exception $e) {
            return ActionResponse::error(
                $action->id,
                $action->type,
                'Callback execution failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Handle form submit action
     */
    protected function handleSubmitAction(InteractiveAction $action, array $actionData, array $payload): ActionResponse
    {
        $formId = $actionData['form_id'] ?? null;
        
        if (!$formId) {
            return ActionResponse::error(
                $action->id,
                $action->type,
                'Form ID not specified for submit action'
            );
        }

        // Fire event for form submission
        Event::dispatch('ai.action.form.submit', [
            'form_id' => $formId,
            'action' => $action,
            'payload' => $payload
        ]);

        return ActionResponse::success(
            $action->id,
            $action->type,
            $action->successMessage ?? 'Form submitted successfully',
            ['form_id' => $formId, 'submitted_data' => $payload]
        );
    }

    /**
     * Handle API call action
     */
    protected function handleApiAction(InteractiveAction $action, array $actionData, array $payload): ActionResponse
    {
        $endpoint = $actionData['endpoint'] ?? null;
        $method = $actionData['method'] ?? 'POST';
        
        if (!$endpoint) {
            return ActionResponse::error(
                $action->id,
                $action->type,
                'API endpoint not specified for button action'
            );
        }

        try {
            // Fire event for API call
            $result = Event::dispatch('ai.action.api.call', [
                'endpoint' => $endpoint,
                'method' => $method,
                'action' => $action,
                'payload' => $payload
            ]);

            return ActionResponse::success(
                $action->id,
                $action->type,
                $action->successMessage ?? 'API call completed successfully',
                ['endpoint' => $endpoint, 'result' => $result]
            );

        } catch (\Exception $e) {
            return ActionResponse::error(
                $action->id,
                $action->type,
                'API call failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Validate button action
     */
    public function validate(InteractiveAction $action, array $payload = []): array
    {
        $errors = [];

        // Check if action data exists
        if (empty($action->data['action'])) {
            $errors['action'] = 'Button action data is required';
            return $errors;
        }

        $actionData = $action->data['action'];
        $actionType = $actionData['type'] ?? 'callback';

        // Validate based on action type
        switch ($actionType) {
            case 'url':
                if (empty($actionData['url'])) {
                    $errors['url'] = 'URL is required for URL action';
                } elseif (!filter_var($actionData['url'], FILTER_VALIDATE_URL)) {
                    $errors['url'] = 'Invalid URL format';
                }
                break;

            case 'callback':
                if (empty($actionData['callback'])) {
                    $errors['callback'] = 'Callback is required for callback action';
                }
                break;

            case 'submit':
                if (empty($actionData['form_id'])) {
                    $errors['form_id'] = 'Form ID is required for submit action';
                }
                break;

            case 'api':
                if (empty($actionData['endpoint'])) {
                    $errors['endpoint'] = 'API endpoint is required for API action';
                }
                break;
        }

        return $errors;
    }

    /**
     * Check if this handler supports the given action type
     */
    public function supports(string $actionType): bool
    {
        return $actionType === ActionTypeEnum::BUTTON;
    }

    /**
     * Get handler priority
     */
    public function priority(): int
    {
        return 100;
    }
}
