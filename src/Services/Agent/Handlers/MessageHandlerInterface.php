<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface MessageHandlerInterface
{
    /**
     * Handle the message and return response
     */
    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse;
    
    /**
     * Check if this handler can handle the given action
     */
    public function canHandle(string $action): bool;
}
