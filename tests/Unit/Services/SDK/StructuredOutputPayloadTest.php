<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\StructuredOutputSchema;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;

class StructuredOutputPayloadTest extends UnitTestCase
{
    public function test_openai_compatible_payload_receives_json_schema_response_format(): void
    {
        $driver = new class([]) extends BaseEngineDriver {
            public function generate(AIRequest $request): AIResponse
            {
                return AIResponse::success('', $request->getEngine(), $request->getModel());
            }
            public function stream(AIRequest $request): \Generator { yield ''; }
            public function validateRequest(AIRequest $request): bool { return true; }
            public function getEngine(): EngineEnum { return new EngineEnum(EngineEnum::OPENAI); }
            public function getAvailableModels(): array { return []; }
            public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string { return '{}'; }
            public function payload(AIRequest $request): array
            {
                return $this->buildChatPayload($request, [['role' => 'user', 'content' => $request->getPrompt()]]);
            }
            protected function getSupportedCapabilities(): array { return ['text']; }
            protected function getEngineEnum(): EngineEnum { return new EngineEnum(EngineEnum::OPENAI); }
            protected function getDefaultModel(): EntityEnum { return EntityEnum::GPT_4O; }
            protected function validateConfig(): void {}
        };

        $schema = StructuredOutputSchema::make([
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
            ],
            'required' => ['answer'],
            'additionalProperties' => false,
        ], 'answer_payload');

        $payload = $driver->payload(
            (new AIRequest('Return JSON', EngineEnum::OPENAI, EntityEnum::GPT_4O))->withStructuredOutput($schema)
        );

        $this->assertSame('json_schema', $payload['response_format']['type']);
        $this->assertSame('answer_payload', $payload['response_format']['json_schema']['name']);
        $this->assertTrue($payload['response_format']['json_schema']['strict']);
    }
}
