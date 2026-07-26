<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;

class PromptJsonPlannerTransport implements AiNativePlannerTransportContract
{
    public function __construct(
        private readonly AiNativeResponseParser $parser
    ) {
    }

    public function name(): string
    {
        return 'prompt_json';
    }

    public function prepare(
        AIRequest $request,
        string $message,
        array $state,
        array $options = []
    ): AIRequest {
        return $request;
    }

    public function parse(AIResponse $response): array
    {
        return $this->parser->parse($response->getContent());
    }
}
