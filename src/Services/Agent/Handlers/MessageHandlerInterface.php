<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface MessageHandlerInterface
{
    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse;
}
