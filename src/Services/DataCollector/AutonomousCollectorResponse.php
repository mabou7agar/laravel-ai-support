<?php

namespace LaravelAIEngine\Services\DataCollector;

/**
 * Response from AutonomousCollectorService
 */
class AutonomousCollectorResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly string $status,
        public readonly array $collectedData = [],
        public readonly bool $isComplete = false,
        public readonly bool $isCancelled = false,
        public readonly bool $requiresConfirmation = false,
        public readonly mixed $result = null,
        public readonly int $turnCount = 0,
        public readonly array $toolResults = [],
        public readonly ?string $error = null,
    ) {}

    public function isFinished(): bool
    {
        return $this->isComplete || $this->isCancelled;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'status' => $this->status,
            'collected_data' => $this->collectedData,
            'is_complete' => $this->isComplete,
            'is_cancelled' => $this->isCancelled,
            'requires_confirmation' => $this->requiresConfirmation,
            'result' => $this->result,
            'turn_count' => $this->turnCount,
            'tool_results' => $this->toolResults,
            'error' => $this->error,
        ];
    }
}
