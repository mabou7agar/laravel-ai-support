<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AIResponse;

interface RAGPipelineContract
{
    public function answer(string $query, array $options = [], int|string|null $userId = null): AgentResponse;

    public function process(
        string $message,
        string $sessionId,
        array $collections = [],
        array $conversationHistory = [],
        array $options = [],
        mixed $userId = null
    ): AIResponse;
}
