<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class ExecuteDynamicActionDTO
{
    public function __construct(
        public readonly string $actionId,
        public readonly array $parameters = [],
        public readonly ?string $userId = null
    ) {}

    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'parameters' => $this->parameters,
            'user_id' => $this->userId,
        ];
    }
}
