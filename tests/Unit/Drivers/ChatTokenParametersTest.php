<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use LaravelAIEngine\Drivers\OpenRouter\OpenRouterEngineDriver;
use LaravelAIEngine\Tests\UnitTestCase;
use ReflectionMethod;

/**
 * Covers the shared reasoning-aware payload logic in BaseEngineDriver that every
 * OpenAI-compatible chat driver (OpenAI, OpenRouter, Grok, DeepSeek, Perplexity,
 * NvidiaNim, ...) now delegates to. A concrete driver is used only as a vehicle
 * to reach the protected base methods.
 */
class ChatTokenParametersTest extends UnitTestCase
{
    private OpenRouterEngineDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new OpenRouterEngineDriver(['api_key' => 'test-key']);
    }

    private function apply(string $model, ?int $maxTokens, ?float $temperature, float $defaultTemp = 0.7): array
    {
        $method = new ReflectionMethod($this->driver, 'applyChatTokenParameters');
        $method->setAccessible(true);

        return $method->invoke($this->driver, [], $model, $maxTokens, $temperature, $defaultTemp);
    }

    private function invokeModelCheck(string $name, string $model): bool
    {
        $method = new ReflectionMethod($this->driver, $name);
        $method->setAccessible(true);

        return $method->invoke($this->driver, $model);
    }

    /** @return iterable<string, array{0: string}> */
    public static function gpt5ModelProvider(): iterable
    {
        yield 'bare gpt-5' => ['gpt-5'];
        yield 'gpt-5-mini' => ['gpt-5-mini'];
        yield 'openrouter-prefixed' => ['openai/gpt-5'];
        yield 'gateway double-prefixed' => ['openrouter/openai/gpt-5-nano'];
    }

    /**
     * @dataProvider gpt5ModelProvider
     */
    public function test_gpt5_uses_floored_completion_tokens_and_drops_temperature(string $model): void
    {
        $payload = $this->apply($model, 50, 0.7);

        $this->assertSame(1024, $payload['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $payload);
        $this->assertArrayNotHasKey('temperature', $payload);
    }

    public function test_gpt5_keeps_large_explicit_cap_above_floor(): void
    {
        $payload = $this->apply('openai/gpt-5', 5000, 0.7);

        $this->assertSame(5000, $payload['max_completion_tokens']);
    }

    public function test_gpt5_with_null_max_tokens_omits_cap_for_provider_default(): void
    {
        $payload = $this->apply('openai/gpt-5', null, null);

        $this->assertArrayNotHasKey('max_completion_tokens', $payload);
        $this->assertArrayNotHasKey('max_tokens', $payload);
        $this->assertArrayNotHasKey('temperature', $payload);
    }

    public function test_o_series_reasoning_models_pin_temperature_to_one(): void
    {
        $payload = $this->apply('openai/o1-mini', 50, 0.7);

        $this->assertSame(1024, $payload['max_completion_tokens']);
        $this->assertSame(1, $payload['temperature']);
        $this->assertArrayNotHasKey('max_tokens', $payload);
    }

    public function test_standard_models_keep_max_tokens_and_temperature(): void
    {
        $payload = $this->apply('meta-llama/llama-3.1-8b-instruct', 50, 0.3);

        $this->assertSame(50, $payload['max_tokens']);
        $this->assertSame(0.3, $payload['temperature']);
        $this->assertArrayNotHasKey('max_completion_tokens', $payload);
    }

    public function test_standard_models_apply_default_temperature_when_unset(): void
    {
        $payload = $this->apply('openai/gpt-4o', 100, null, 0.2);

        $this->assertSame(0.2, $payload['temperature']);
    }

    public function test_model_family_detection_handles_provider_prefixes(): void
    {
        $this->assertTrue($this->invokeModelCheck('isGpt5FamilyModel', 'openai/gpt-5'));
        $this->assertTrue($this->invokeModelCheck('isGpt5FamilyModel', 'gpt-5-mini'));
        $this->assertFalse($this->invokeModelCheck('isGpt5FamilyModel', 'openai/gpt-4o'));

        $this->assertTrue($this->invokeModelCheck('isReasoningModel', 'openai/o1-preview'));
        $this->assertTrue($this->invokeModelCheck('isReasoningModel', 'o3-mini'));
        $this->assertFalse($this->invokeModelCheck('isReasoningModel', 'meta-llama/llama-3.1-8b-instruct'));
    }
}
