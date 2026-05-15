<?php

declare(strict_types=1);

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
