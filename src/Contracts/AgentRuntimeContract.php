<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;

interface AgentRuntimeContract
{
    public function name(): string;

    public function capabilities(): AgentRuntimeCapabilities;

    public function process(
        string $message,
        string $sessionId,
        mixed $userId,
        array $options = []
    ): AgentResponse;
}
