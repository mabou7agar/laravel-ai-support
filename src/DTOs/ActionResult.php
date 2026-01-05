<?php

namespace LaravelAIEngine\DTOs;

/**
 * Action Execution Result DTO
 * 
 * Standardized response format for all action executions
 */
class ActionResult
{
    public function __construct(
        public bool $success,
        public ?string $message = null,
        public mixed $data = null,
        public ?string $error = null,
        public array $metadata = [],
        public ?int $durationMs = null,
        public ?string $actionId = null,
        public ?string $actionType = null
    ) {}
    
    /**
     * Create a successful result
     */
    public static function success(
        string $message,
        mixed $data = null,
        array $metadata = []
    ): self {
        return new self(
            success: true,
            message: $message,
            data: $data,
            metadata: $metadata
        );
    }
    
    /**
     * Create a failure result
     */
    public static function failure(
        string $error,
        mixed $data = null,
        array $metadata = []
    ): self {
        return new self(
            success: false,
            error: $error,
            data: $data,
            metadata: $metadata
        );
    }
    
    /**
     * Create result from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'] ?? false,
            message: $data['message'] ?? null,
            data: $data['data'] ?? null,
            error: $data['error'] ?? null,
            metadata: $data['metadata'] ?? [],
            durationMs: $data['duration_ms'] ?? null,
            actionId: $data['action_id'] ?? null,
            actionType: $data['action_type'] ?? null
        );
    }
    
    /**
     * Convert to array
     */
    public function toArray(): array
    {
        $result = [
            'success' => $this->success,
        ];
        
        if ($this->message !== null) {
            $result['message'] = $this->message;
        }
        
        if ($this->data !== null) {
            $result['data'] = $this->data;
        }
        
        if ($this->error !== null) {
            $result['error'] = $this->error;
        }
        
        if (!empty($this->metadata)) {
            $result['metadata'] = $this->metadata;
        }
        
        if ($this->durationMs !== null) {
            $result['duration_ms'] = $this->durationMs;
        }
        
        if ($this->actionId !== null) {
            $result['action_id'] = $this->actionId;
        }
        
        if ($this->actionType !== null) {
            $result['action_type'] = $this->actionType;
        }
        
        return $result;
    }
    
    /**
     * Add metadata
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }
    
    /**
     * Set duration
     */
    public function withDuration(int $durationMs): self
    {
        $this->durationMs = $durationMs;
        return $this;
    }
    
    /**
     * Set action info
     */
    public function withActionInfo(string $actionId, string $actionType): self
    {
        $this->actionId = $actionId;
        $this->actionType = $actionType;
        return $this;
    }
    
    /**
     * Check if result has data
     */
    public function hasData(): bool
    {
        return $this->data !== null;
    }
    
    /**
     * Check if result has error
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }
    
    /**
     * Get metadata value
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
