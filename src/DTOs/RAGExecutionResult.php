<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class RAGExecutionResult
{
    private function __construct(
        public readonly string $executor,
        public readonly ?AgentResponse $response = null,
        public readonly ?array $decisionResult = null
    ) {
    }

    public static function pipeline(AgentResponse $response): self
    {
        return new self('pipeline', response: $response);
    }

    public static function decisionEngine(array $result): self
    {
        return new self('decision_engine', decisionResult: $result);
    }

    public function usesPipeline(): bool
    {
        return $this->executor === 'pipeline';
    }

    public function usesDecisionEngine(): bool
    {
        return $this->executor === 'decision_engine';
    }
}
