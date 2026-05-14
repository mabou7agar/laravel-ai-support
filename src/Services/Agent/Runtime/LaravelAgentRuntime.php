<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;

class LaravelAgentRuntime implements AgentRuntimeContract
{
    public function __construct(
        protected LaravelAgentProcessor $processor
    ) {
    }

    public function name(): string
    {
        return 'laravel';
    }

    public function capabilities(): AgentRuntimeCapabilities
    {
        return AgentRuntimeCapabilities::laravel();
    }

    public function process(
        string $message,
        string $sessionId,
        mixed $userId,
        array $options = []
    ): AgentResponse {
        $response = $this->processor->process($message, $sessionId, $userId, $options);

        $response->metadata = array_merge($response->metadata ?? [], [
            'agent_runtime' => $this->name(),
            'agent_runtime_capabilities' => $this->capabilities()->toArray(),
        ]);

        return $response;
    }
}
