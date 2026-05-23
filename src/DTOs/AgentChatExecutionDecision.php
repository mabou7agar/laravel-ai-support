<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class AgentChatExecutionDecision
{
    public function __construct(
        public readonly string $mode,
        public readonly string $reason
    ) {
    }

    public function shouldQueue(): bool
    {
        return $this->mode === 'async';
    }

    public function toArray(): array
    {
        return [
            'execution_mode' => $this->mode,
            'execution_mode_reason' => $this->reason,
        ];
    }
}
