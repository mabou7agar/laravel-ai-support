<?php

namespace LaravelAIEngine\Services\DataCollector;

use LaravelAIEngine\DTOs\DataCollectorState;
use LaravelAIEngine\DTOs\AIResponse;

/**
 * Response object from DataCollectorService
 */
class DataCollectorResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?DataCollectorState $state = null,
        public readonly ?AIResponse $aiResponse = null,
        public readonly bool $isComplete = false,
        public readonly bool $isCancelled = false,
        public readonly bool $requiresConfirmation = false,
        public readonly bool $allowsEnhancement = false,
        public readonly ?string $currentField = null,
        public readonly array $collectedFields = [],
        public readonly array $remainingFields = [],
        public readonly array $validationErrors = [],
        public readonly ?string $summary = null,
        public readonly ?string $actionSummary = null, // Description of what will happen on completion
        public readonly mixed $result = null,
        public readonly ?array $generatedOutput = null, // AI-generated structured output based on schema
    ) {}

    /**
     * Check if the data collection is finished (complete or cancelled)
     */
    public function isFinished(): bool
    {
        return $this->isComplete || $this->isCancelled;
    }

    /**
     * Check if user input is needed
     */
    public function needsUserInput(): bool
    {
        return !$this->isFinished() && $this->success;
    }

    /**
     * Get progress percentage
     */
    public function getProgress(): float
    {
        $total = count($this->collectedFields) + count($this->remainingFields);
        
        if ($total === 0) {
            return 100.0;
        }

        return round((count($this->collectedFields) / $total) * 100, 1);
    }

    /**
     * Get collected data from state
     */
    public function getData(): array
    {
        return $this->state?->getData() ?? [];
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'is_complete' => $this->isComplete,
            'is_cancelled' => $this->isCancelled,
            'requires_confirmation' => $this->requiresConfirmation,
            'allows_enhancement' => $this->allowsEnhancement,
            'current_field' => $this->currentField,
            'collected_fields' => $this->collectedFields,
            'remaining_fields' => $this->remainingFields,
            'validation_errors' => $this->validationErrors,
            'summary' => $this->summary,
            'action_summary' => $this->actionSummary,
            'progress' => $this->getProgress(),
            'data' => $this->getData(),
            'result' => $this->result,
            'status' => $this->state?->status,
            'generated_output' => $this->generatedOutput,
        ];
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
