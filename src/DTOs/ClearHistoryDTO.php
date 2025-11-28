<?php

namespace LaravelAIEngine\DTOs;

class ClearHistoryDTO
{
    public function __construct(
        public readonly string $sessionId,
        public readonly ?string $userId = null
    ) {}

    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'user_id' => $this->userId,
        ];
    }
}
