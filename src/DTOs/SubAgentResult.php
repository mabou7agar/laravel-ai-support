<?php

namespace LaravelAIEngine\DTOs;

class SubAgentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $taskId,
        public readonly string $agentId,
        public readonly ?string $message = null,
        public readonly mixed $data = null,
        public readonly ?string $error = null,
        public readonly array $metadata = [],
        public readonly bool $needsUserInput = false
    ) {
    }

    public static function success(
        string $taskId,
        string $agentId,
        ?string $message = null,
        mixed $data = null,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            taskId: $taskId,
            agentId: $agentId,
            message: $message,
            data: $data,
            metadata: $metadata
        );
    }

    public static function failure(
        string $taskId,
        string $agentId,
        string $error,
        mixed $data = null,
        array $metadata = []
    ): self {
        return new self(
            success: false,
            taskId: $taskId,
            agentId: $agentId,
            data: $data,
            error: $error,
            metadata: $metadata
        );
    }

    public static function needsUserInput(
        string $taskId,
        string $agentId,
        string $message,
        mixed $data = null,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            taskId: $taskId,
            agentId: $agentId,
            message: $message,
            data: $data,
            metadata: $metadata,
            needsUserInput: true
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'success' => $this->success,
            'task_id' => $this->taskId,
            'agent_id' => $this->agentId,
            'message' => $this->message,
            'data' => $this->data,
            'error' => $this->error,
            'metadata' => $this->metadata,
            'needs_user_input' => $this->needsUserInput,
        ], static fn ($value) => $value !== null && $value !== []);
    }
}
