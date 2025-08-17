<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Contracts;

use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;

interface EngineDriverInterface
{
    /**
     * Generate content using the AI engine
     */
    public function generate(AIRequest $request): AIResponse;

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator;

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool;

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum;

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool;

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array;

    /**
     * Test the engine connection
     */
    public function test(): bool;
}
