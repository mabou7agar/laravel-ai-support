<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

/**
 * Represents the current state of a data collection session
 */
class DataCollectorState
{
    public const STATUS_COLLECTING = 'collecting';
    public const STATUS_CONFIRMING = 'confirming';
    public const STATUS_ENHANCING = 'enhancing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public readonly string $sessionId,
        public readonly string $configName,
        public string $status = self::STATUS_COLLECTING,
        public array $collectedData = [],
        public ?string $currentField = null,
        public array $validationErrors = [],
        public array $conversationHistory = [],
        public ?string $lastAIResponse = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public mixed $result = null,
    ) {
        $this->startedAt = $this->startedAt ?? new \DateTimeImmutable();
    }

    /**
     * Set a field value
     */
    public function setFieldValue(string $field, mixed $value): self
    {
        $this->collectedData[$field] = $value;
        return $this;
    }

    /**
     * Get a field value
     */
    public function getFieldValue(string $field): mixed
    {
        return $this->collectedData[$field] ?? null;
    }

    /**
     * Check if a field has been collected
     */
    public function hasField(string $field): bool
    {
        return isset($this->collectedData[$field]) && 
               $this->collectedData[$field] !== null && 
               $this->collectedData[$field] !== '';
    }

    /**
     * Get all collected data
     */
    public function getData(): array
    {
        return $this->collectedData;
    }

    /**
     * Set current field being collected
     */
    public function setCurrentField(?string $field): self
    {
        $this->currentField = $field;
        return $this;
    }

    /**
     * Set status
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;
        
        if ($status === self::STATUS_COMPLETED || $status === self::STATUS_CANCELLED) {
            $this->completedAt = new \DateTimeImmutable();
        }
        
        return $this;
    }

    /**
     * Add validation errors
     */
    public function setValidationErrors(array $errors): self
    {
        $this->validationErrors = $errors;
        return $this;
    }

    /**
     * Clear validation errors
     */
    public function clearValidationErrors(): self
    {
        $this->validationErrors = [];
        return $this;
    }

    /**
     * Check if there are validation errors
     */
    public function hasErrors(): bool
    {
        return !empty($this->validationErrors);
    }

    /**
     * Add a message to conversation history
     */
    public function addMessage(string $role, string $content): self
    {
        $this->conversationHistory[] = [
            'role' => $role,
            'content' => $content,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
        return $this;
    }

    /**
     * Set the last AI response
     */
    public function setLastAIResponse(string $response): self
    {
        $this->lastAIResponse = $response;
        return $this;
    }

    /**
     * Set the completion result
     */
    public function setResult(mixed $result): self
    {
        $this->result = $result;
        return $this;
    }

    /**
     * Check if collection is complete
     */
    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if collection is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if collection is in progress
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, [
            self::STATUS_COLLECTING,
            self::STATUS_CONFIRMING,
            self::STATUS_ENHANCING,
        ]);
    }

    /**
     * Get duration in seconds
     */
    public function getDuration(): ?int
    {
        if (!$this->startedAt) {
            return null;
        }

        $endTime = $this->completedAt ?? new \DateTimeImmutable();
        return $endTime->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * Serialize to array for storage
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'config_name' => $this->configName,
            'status' => $this->status,
            'collected_data' => $this->collectedData,
            'current_field' => $this->currentField,
            'validation_errors' => $this->validationErrors,
            'conversation_history' => $this->conversationHistory,
            'last_ai_response' => $this->lastAIResponse,
            'started_at' => $this->startedAt?->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
        ];
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: $data['session_id'],
            configName: $data['config_name'],
            status: $data['status'] ?? self::STATUS_COLLECTING,
            collectedData: $data['collected_data'] ?? [],
            currentField: $data['current_field'] ?? null,
            validationErrors: $data['validation_errors'] ?? [],
            conversationHistory: $data['conversation_history'] ?? [],
            lastAIResponse: $data['last_ai_response'] ?? null,
            startedAt: isset($data['started_at']) ? new \DateTimeImmutable($data['started_at']) : null,
            completedAt: isset($data['completed_at']) ? new \DateTimeImmutable($data['completed_at']) : null,
        );
    }
}
