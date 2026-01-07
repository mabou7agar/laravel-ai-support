<?php

namespace LaravelAIEngine\Resources;

use LaravelAIEngine\Contracts\AIActionResponse;

class AIActionResource implements AIActionResponse
{
    protected bool $success;
    protected mixed $data;
    protected string $message;
    protected ?string $error;

    public function __construct(
        bool $success,
        mixed $data = null,
        string $message = '',
        ?string $error = null
    ) {
        $this->success = $success;
        $this->data = $data;
        $this->message = $message;
        $this->error = $error;
    }

    /**
     * Check if the action was successful
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get the response data
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Get the response message
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get any error information
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Convert to array format expected by ActionExecutionService
     */
    public function toArray(): array
    {
        $response = [
            'success' => $this->success,
            'message' => $this->message,
        ];

        if ($this->success && $this->data !== null) {
            $response['data'] = $this->data;
        }

        if (!$this->success && $this->error !== null) {
            $response['error'] = $this->error;
        }

        return $response;
    }

    /**
     * Create a successful response
     */
    public static function success(mixed $data, string $message = 'Action completed successfully'): self
    {
        return new self(
            success: true,
            data: $data,
            message: $message,
            error: null
        );
    }

    /**
     * Create a failed response
     */
    public static function failure(string $error, ?string $message = null): self
    {
        return new self(
            success: false,
            data: null,
            message: $message ?? 'Action failed',
            error: $error
        );
    }

    /**
     * Create from array (for backward compatibility)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'] ?? false,
            data: $data['data'] ?? null,
            message: $data['message'] ?? '',
            error: $data['error'] ?? null
        );
    }

    /**
     * Magic method to allow array access
     */
    public function __get(string $key): mixed
    {
        return match($key) {
            'success' => $this->success,
            'data' => $this->data,
            'message' => $this->message,
            'error' => $this->error,
            default => null,
        };
    }
}
