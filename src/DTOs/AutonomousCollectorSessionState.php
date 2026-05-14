<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class AutonomousCollectorSessionState
{
    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_CONFIRMING = 'confirming';
    public const STATUS_COMPLETED = 'completed';

    public function __construct(
        public ?string $configName = null,
        public string $status = self::STATUS_COLLECTING,
        public array $conversation = [],
        public array $collectedData = [],
        public array $toolResults = [],
        public ?array $pendingToolConfirmation = null,
        public int $turnCount = 0,
        public ?string $startedAt = null,
        public array $metadata = [],
    ) {
        $this->startedAt ??= now()->toIso8601String();
    }

    public static function fromArray(array $state): self
    {
        return new self(
            configName: isset($state['config_name']) ? (string) $state['config_name'] : null,
            status: (string) ($state['status'] ?? self::STATUS_COLLECTING),
            conversation: (array) ($state['conversation'] ?? []),
            collectedData: (array) ($state['collected_data'] ?? []),
            toolResults: (array) ($state['tool_results'] ?? []),
            pendingToolConfirmation: isset($state['pending_tool_confirmation']) && is_array($state['pending_tool_confirmation'])
                ? $state['pending_tool_confirmation']
                : null,
            turnCount: (int) ($state['turn_count'] ?? 0),
            startedAt: isset($state['started_at']) ? (string) $state['started_at'] : null,
            metadata: (array) ($state['metadata'] ?? []),
        );
    }

    public function toArray(): array
    {
        $state = [
            'config_name' => $this->configName,
            'status' => $this->status,
            'conversation' => $this->conversation,
            'collected_data' => $this->collectedData,
            'tool_results' => $this->toolResults,
            'turn_count' => $this->turnCount,
            'started_at' => $this->startedAt,
            'metadata' => $this->metadata,
        ];

        if ($this->pendingToolConfirmation !== null) {
            $state['pending_tool_confirmation'] = $this->pendingToolConfirmation;
        }

        return $state;
    }

    public function appendConversation(string $role, string $content): void
    {
        $this->conversation[] = [
            'role' => $role,
            'content' => $content,
        ];
    }

    public function resetLoopCounters(): void
    {
        $this->metadata['tool_loop_count'] = 0;
        $this->metadata['validation_retry_count'] = 0;
    }

    public function incrementToolLoop(): int
    {
        $this->metadata['tool_loop_count'] = ((int) ($this->metadata['tool_loop_count'] ?? 0)) + 1;

        return $this->metadata['tool_loop_count'];
    }

    public function incrementValidationRetry(): int
    {
        $this->metadata['validation_retry_count'] = ((int) ($this->metadata['validation_retry_count'] ?? 0)) + 1;

        return $this->metadata['validation_retry_count'];
    }
}
