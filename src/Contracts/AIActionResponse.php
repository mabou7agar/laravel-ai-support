<?php

namespace LaravelAIEngine\Contracts;

interface AIActionResponse
{
    /**
     * Check if the action was successful
     */
    public function isSuccess(): bool;

    /**
     * Get the response data
     */
    public function getData(): mixed;

    /**
     * Get the response message
     */
    public function getMessage(): string;

    /**
     * Get any error information
     */
    public function getError(): ?string;

    /**
     * Convert to array format expected by ActionExecutionService
     */
    public function toArray(): array;

    /**
     * Create a successful response
     */
    public static function success(mixed $data, string $message = 'Action completed successfully'): self;

    /**
     * Create a failed response
     */
    public static function failure(string $error, ?string $message = null): self;
}
