<?php

namespace LaravelAIEngine\DTOs;

class SendMessageDTO
{
    public function __construct(
        public readonly string $message,
        public readonly string $sessionId,
        public readonly string $engine = 'openai',
        public readonly string $model = 'gpt-4o',
        public readonly bool $memory = true,
        public readonly bool $actions = true,
        public readonly bool $streaming = false,
        public readonly ?string $userId = null
    ) {}

    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'session_id' => $this->sessionId,
            'engine' => $this->engine,
            'model' => $this->model,
            'memory' => $this->memory,
            'actions' => $this->actions,
            'streaming' => $this->streaming,
            'user_id' => $this->userId,
        ];
    }
}
