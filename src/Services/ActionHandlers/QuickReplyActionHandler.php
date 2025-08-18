<?php

namespace LaravelAIEngine\Services\ActionHandlers;

use LaravelAIEngine\Contracts\ActionHandlerInterface;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\DTOs\ActionResponse;
use LaravelAIEngine\Enums\ActionTypeEnum;
use Illuminate\Support\Facades\Event;

class QuickReplyActionHandler implements ActionHandlerInterface
{
    /**
     * Handle quick reply action execution
     */
    public function handle(InteractiveAction $action, array $payload = []): ActionResponse
    {
        // Fire event for quick reply action
        Event::dispatch('ai.action.quick_reply.selected', [
            'action' => $action,
            'payload' => $payload
        ]);

        $message = $action->data['message'] ?? '';
        $autoSend = $action->data['auto_send'] ?? true;

        if (empty($message)) {
            return ActionResponse::error(
                $action->id,
                $action->type,
                'No message specified for quick reply'
            );
        }

        // Prepare response data
        $responseData = [
            'message' => $message,
            'auto_send' => $autoSend,
            'original_action' => $action->id
        ];

        // If auto_send is true, trigger message sending
        if ($autoSend) {
            Event::dispatch('ai.action.message.send', [
                'message' => $message,
                'action' => $action,
                'payload' => $payload
            ]);
        }

        return ActionResponse::success(
            $action->id,
            $action->type,
            $action->successMessage ?? 'Quick reply selected',
            $responseData
        );
    }

    /**
     * Validate quick reply action
     */
    public function validate(InteractiveAction $action, array $payload = []): array
    {
        $errors = [];

        if (empty($action->data['message'])) {
            $errors['message'] = 'Message is required for quick reply action';
        }

        return $errors;
    }

    /**
     * Check if this handler supports the given action type
     */
    public function supports(string $actionType): bool
    {
        return $actionType === ActionTypeEnum::QUICK_REPLY->value;
    }

    /**
     * Get handler priority
     */
    public function priority(): int
    {
        return 100;
    }
}
