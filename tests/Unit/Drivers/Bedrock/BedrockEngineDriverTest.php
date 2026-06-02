<?php

namespace LaravelAIEngine\Tests\Unit\Drivers\Bedrock;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\Drivers\Bedrock\BedrockEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\MissingDependencyException;
use LaravelAIEngine\Tests\UnitTestCase;

class BedrockEngineDriverTest extends UnitTestCase
{
    public function test_engine_enum_wiring_resolves(): void
    {
        $this->assertSame('bedrock', EngineEnum::Bedrock->value);
        $this->assertSame(EngineEnum::BEDROCK, EngineEnum::Bedrock->value);
        $this->assertSame(BedrockEngineDriver::class, EngineEnum::Bedrock->driverClass());
        $this->assertSame('AWS Bedrock', EngineEnum::Bedrock->label());
        $this->assertContains('text', EngineEnum::Bedrock->capabilities());
    }

    public function test_entity_enum_wiring_resolves(): void
    {
        $model = EntityEnum::from(EntityEnum::BEDROCK_CLAUDE_SONNET);

        $this->assertSame(EngineEnum::Bedrock, $model->engine());
        $this->assertSame(BedrockEngineDriver::class, $model->driverClass());
        $this->assertSame('text', $model->getContentType());
        $this->assertGreaterThan(0.0, $model->creditIndex());
    }

    public function test_driver_implements_interface_and_reports_engine(): void
    {
        $driver = new BedrockEngineDriver(['region' => 'us-east-1']);

        $this->assertInstanceOf(EngineDriverInterface::class, $driver);
        $this->assertSame(EngineEnum::Bedrock, $driver->getEngine());
        $this->assertTrue($driver->supports('text'));
        $this->assertTrue($driver->supports('chat'));
    }

    public function test_throws_clear_exception_when_aws_sdk_missing(): void
    {
        // The AWS SDK is intentionally NOT a hard dependency of this package, so
        // \Aws\BedrockRuntime\BedrockRuntimeClient should not be available here.
        $this->assertFalse(
            class_exists(BedrockEngineDriver::SDK_CLIENT_CLASS),
            'AWS SDK must not be installed for this contract test.'
        );

        $driver = new BedrockEngineDriver(['region' => 'us-east-1']);

        try {
            $driver->generateText(new AIRequest(
                prompt: 'Hello Bedrock',
                engine: EngineEnum::Bedrock,
                model: EntityEnum::BEDROCK_CLAUDE_SONNET
            ));
            $this->fail('Expected MissingDependencyException to be thrown.');
        } catch (MissingDependencyException $e) {
            $this->assertStringContainsString('aws/aws-sdk-php', $e->getMessage());
            $this->assertStringContainsString('composer require', $e->getMessage());
        }
    }

    public function test_generate_text_builds_converse_request_and_maps_response(): void
    {
        $client = new FakeBedrockRuntimeClient([
            'output' => [
                'message' => [
                    'role' => 'assistant',
                    'content' => [
                        ['text' => 'Hello from Bedrock Claude.'],
                    ],
                ],
            ],
            'stopReason' => 'end_turn',
            'usage' => [
                'inputTokens' => 12,
                'outputTokens' => 8,
                'totalTokens' => 20,
            ],
        ]);

        $driver = new BedrockEngineDriver(['region' => 'us-east-1'], $client);

        $response = $driver->generate(new AIRequest(
            prompt: 'Say hello',
            engine: EngineEnum::Bedrock,
            model: EntityEnum::BEDROCK_CLAUDE_SONNET,
            parameters: ['top_p' => 0.9],
            systemPrompt: 'You are concise.',
            maxTokens: 64,
            temperature: 0.2
        ));

        // Response mapping.
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Hello from Bedrock Claude.', $response->getContent());
        $this->assertSame('end_turn', $response->getFinishReason());
        $this->assertSame(20, $response->getTokensUsed());

        // Request building (converse payload).
        $payload = $client->lastConverseArgs;
        $this->assertSame(EntityEnum::BEDROCK_CLAUDE_SONNET, $payload['modelId']);
        $this->assertSame([['text' => 'You are concise.']], $payload['system']);
        $this->assertSame('user', $payload['messages'][0]['role']);
        $this->assertSame('Say hello', $payload['messages'][0]['content'][0]['text']);
        $this->assertSame(64, $payload['inferenceConfig']['maxTokens']);
        $this->assertSame(0.2, $payload['inferenceConfig']['temperature']);
        $this->assertSame(0.9, $payload['inferenceConfig']['topP']);
    }
}

/**
 * Minimal test double for \Aws\BedrockRuntime\BedrockRuntimeClient.
 *
 * Mirrors the converse() seam used by the driver without touching the network or
 * requiring the real AWS SDK.
 */
class FakeBedrockRuntimeClient
{
    public array $lastConverseArgs = [];

    public function __construct(private array $result)
    {
    }

    public function converse(array $args = []): array
    {
        $this->lastConverseArgs = $args;

        return $this->result;
    }
}
