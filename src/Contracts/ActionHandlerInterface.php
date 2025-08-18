<?php

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\DTOs\ActionResponse;

interface ActionHandlerInterface
{
    /**
     * Handle the execution of an interactive action
     */
    public function handle(InteractiveAction $action, array $payload = []): ActionResponse;

    /**
     * Validate action data before execution
     */
    public function validate(InteractiveAction $action, array $payload = []): array;

    /**
     * Check if this handler supports the given action type
     */
    public function supports(string $actionType): bool;

    /**
     * Get the priority of this handler (higher = more priority)
     */
    public function priority(): int;
}
