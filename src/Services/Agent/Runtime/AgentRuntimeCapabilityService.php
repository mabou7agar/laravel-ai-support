<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

class AgentRuntimeCapabilityService
{
    public function __construct(
        protected AgentRuntimeManager $runtimeManager
    ) {
    }

    public function current(): array
    {
        return [
            'runtime' => $this->runtimeManager->name(),
            'capabilities' => $this->runtimeManager->capabilities()->toArray(),
        ];
    }

    public function all(): array
    {
        return $this->runtimeManager->availableCapabilities();
    }

    public function report(): array
    {
        return [
            'current' => $this->current(),
            'available' => $this->all(),
        ];
    }
}
