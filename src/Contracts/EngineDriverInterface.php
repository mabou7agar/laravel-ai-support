<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;

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
    
    /**
     * Generate JSON analysis using the best approach for the model
     * Used for structured output tasks like query analysis
     * 
     * @param string $prompt The analysis prompt
     * @param string $systemPrompt System instructions
     * @param string|null $model Model to use (null = use default)
     * @param int $maxTokens Maximum tokens for response
     * @return string JSON response content
     */
    public function generateJsonAnalysis(
        string $prompt,
        string $systemPrompt,
        ?string $model = null,
        int $maxTokens = 300
    ): string;
}
