<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;

interface AiNativePlannerTransportContract
{
    public function name(): string;

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function prepare(
        AIRequest $request,
        string $message,
        array $state,
        array $options = []
    ): AIRequest;

    /**
     * @return array<string, mixed>
     */
    public function parse(AIResponse $response): array;
}
