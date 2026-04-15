<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Services\RequestRouteResolver;
use LaravelAIEngine\Tests\TestCase;

class RequestRouteResolverTest extends TestCase
{
    use RefreshDatabase;

    protected RequestRouteResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(RequestRouteResolver::class);
    }

    public function test_resolver_prefers_native_provider_over_openrouter_for_model_only_requests(): void
    {
        Config::set('ai-engine.default', 'openrouter');
        Config::set('ai-engine.engines.openrouter.api_key', 'test-openrouter-key');
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');

        $this->createModel('openai', 'gpt-4o');
        $this->createModel('openrouter', 'openai/gpt-4o');

        $request = new AIRequest(
            prompt: 'Hello',
            model: 'gpt-4o'
        );

        $resolved = $this->resolver->resolve($request);

        $this->assertSame('openai', $resolved->getEngine()->value);
        $this->assertSame('gpt-4o', $resolved->getModel()->value);
    }

    public function test_resolver_uses_openrouter_when_native_provider_is_not_configured(): void
    {
        Config::set('ai-engine.default', 'anthropic');
        Config::set('ai-engine.engines.openai.api_key', '');
        Config::set('ai-engine.engines.openrouter.api_key', 'test-openrouter-key');

        $this->createModel('openai', 'gpt-4o');
        $this->createModel('openrouter', 'openai/gpt-4o');

        $request = new AIRequest(
            prompt: 'Hello',
            model: 'gpt-4o'
        );

        $resolved = $this->resolver->resolve($request);

        $this->assertSame('openrouter', $resolved->getEngine()->value);
        $this->assertSame('openai/gpt-4o', $resolved->getModel()->value);
    }

    public function test_resolver_preserves_explicit_engine_and_model(): void
    {
        Config::set('ai-engine.engines.openrouter.api_key', 'test-openrouter-key');
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');

        $this->createModel('openai', 'gpt-4o');
        $this->createModel('openrouter', 'openai/gpt-4o');

        $request = new AIRequest(
            prompt: 'Hello',
            engine: 'openrouter',
            model: 'gpt-4o'
        );

        $resolved = $this->resolver->resolve($request);

        $this->assertSame('openrouter', $resolved->getEngine()->value);
        $this->assertSame('gpt-4o', $resolved->getModel()->value);
    }

    public function test_resolver_respects_configured_provider_priority(): void
    {
        Config::set('ai-engine.request_routing.provider_priority', ['openrouter', 'native', 'anthropic']);
        Config::set('ai-engine.engines.openrouter.api_key', 'test-openrouter-key');
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');

        $this->createModel('openai', 'gpt-4o');
        $this->createModel('openrouter', 'openai/gpt-4o');

        $request = new AIRequest(
            prompt: 'Hello',
            model: 'gpt-4o'
        );

        $resolved = $this->resolver->resolve($request);

        $this->assertSame('openrouter', $resolved->getEngine()->value);
        $this->assertSame('openai/gpt-4o', $resolved->getModel()->value);
    }

    public function test_resolver_can_choose_cheapest_model_from_preference(): void
    {
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');
        Config::set('ai-engine.engines.anthropic.api_key', 'test-anthropic-key');

        $this->createModel('openai', 'gpt-4o-mini', ['pricing' => ['input' => 0.00015]]);
        $this->createModel('anthropic', 'claude-4-sonnet', ['pricing' => ['input' => 0.003]]);

        $request = new AIRequest(
            prompt: 'Hello',
            metadata: ['routing_preference' => 'cost']
        );

        $resolved = $this->resolver->resolve($request);

        $this->assertSame('openai', $resolved->getEngine()->value);
        $this->assertSame('gpt-4o-mini', $resolved->getModel()->value);
    }

    public function test_resolver_can_choose_performance_model_from_preference(): void
    {
        Config::set('ai-engine.engines.openai.api_key', 'test-openai-key');
        Config::set('ai-engine.engines.anthropic.api_key', 'test-anthropic-key');

        $this->createModel('openai', 'gpt-4o-mini', [
            'pricing' => ['input' => 0.00015],
            'context_window' => ['input' => 128000, 'output' => 16000],
            'supports_streaming' => true,
            'supports_function_calling' => true,
        ]);
        $this->createModel('anthropic', 'claude-4-sonnet', [
            'pricing' => ['input' => 0.003],
            'context_window' => ['input' => 200000, 'output' => 8000],
            'supports_streaming' => true,
            'supports_function_calling' => true,
        ]);

        $request = new AIRequest(
            prompt: 'Hello',
            metadata: ['routing_preference' => 'performance']
        );

        $resolved = $this->resolver->resolve($request);

        $this->assertSame('openai', $resolved->getEngine()->value);
        $this->assertSame('gpt-4o-mini', $resolved->getModel()->value);
    }

    protected function createModel(string $provider, string $modelId, array $overrides = []): AIModel
    {
        return AIModel::create(array_merge([
            'provider' => $provider,
            'model_id' => $modelId,
            'name' => $modelId,
            'capabilities' => ['chat'],
            'context_window' => ['input' => 64000, 'output' => 4000],
            'pricing' => ['input' => 0.001, 'output' => 0.002],
            'supports_streaming' => true,
            'supports_vision' => false,
            'supports_function_calling' => true,
            'supports_json_mode' => true,
            'is_active' => true,
            'is_deprecated' => false,
        ], $overrides));
    }
}
