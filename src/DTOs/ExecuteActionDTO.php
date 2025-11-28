<?php

namespace LaravelAIEngine\DTOs;

class ExecuteActionDTO
{
    public function __construct(
        public readonly string $actionId,
        public readonly string $actionType,
        public readonly string $sessionId,
        public readonly array $payload = [],
        public readonly ?string $userId = null
    ) {}

    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'action_type' => $this->actionType,
            'session_id' => $this->sessionId,
            'payload' => $this->payload,
            'user_id' => $this->userId,
        ];
    }
}
