<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AgentResponse;

class AiNativeActionOutcome
{
    private function __construct(
        public readonly ?AgentResponse $response,
        public readonly bool $continueLoop
    ) {}

    public static function response(AgentResponse $response): self
    {
        return new self($response, false);
    }

    public static function continueLoop(): self
    {
        return new self(null, true);
    }
}
