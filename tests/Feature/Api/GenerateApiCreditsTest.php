<?php

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Services\RequestRouteResolver;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class GenerateApiCreditsTest extends TestCase
{
    public function test_image_endpoint_returns_402_when_authenticated_user_has_no_credits(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'dall-e-3' => ['balance' => 0.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/v1/ai/generate/image', [
            'prompt' => 'Simple logo',
            'engine' => 'openai',
            'model' => 'dall-e-3',
            'count' => 1,
        ]);

        $response->assertStatus(402)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Insufficient credits for this request.');
    }

    public function test_text_endpoint_deducts_credits_for_authenticated_user(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o-mini' => ['balance' => 100.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $mockDriver = Mockery::mock(EngineDriverInterface::class);
        $mockDriver->shouldReceive('validateRequest')->once();
        $mockDriver->shouldReceive('generate')->once()->andReturn(
            AIResponse::success('ok', 'openai', 'gpt-4o-mini')
        );

        $registry = new DriverRegistry($this->app);
        $registry->register('openai', static fn () => $mockDriver);
        $this->app->instance(
            AIEngineService::class,
            new AIEngineService(app(CreditManager::class), null, $registry)
        );

        $request = new AIRequest(
            prompt: 'hello world',
            engine: 'openai',
            model: 'gpt-4o-mini',
            userId: (string) $user->id
        );
        $expectedCredits = app(CreditManager::class)->calculateCredits($request);

        $this->actingAs($user);

        $response = $this->postJson('/api/v1/ai/generate/text', [
            'prompt' => 'hello world',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $remaining = (float) data_get($user->entity_credits, 'openai.gpt-4o-mini.balance', 0.0);

        $this->assertEqualsWithDelta(100.0 - $expectedCredits, $remaining, 0.0001);
    }

    public function test_text_endpoint_can_route_by_preference_without_engine_or_model(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o-mini' => ['balance' => 100.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        AIModel::create([
            'provider' => 'openai',
            'model_id' => 'gpt-4o-mini',
            'name' => 'GPT-4o Mini',
            'capabilities' => ['chat'],
            'context_window' => ['input' => 128000, 'output' => 16000],
            'pricing' => ['input' => 0.00015, 'output' => 0.0006],
            'supports_streaming' => true,
            'supports_function_calling' => true,
            'supports_json_mode' => true,
            'is_active' => true,
            'is_deprecated' => false,
        ]);

        AIModel::create([
            'provider' => 'anthropic',
            'model_id' => 'claude-4-sonnet',
            'name' => 'Claude 4 Sonnet',
            'capabilities' => ['chat'],
            'context_window' => ['input' => 200000, 'output' => 8000],
            'pricing' => ['input' => 0.003, 'output' => 0.015],
            'supports_streaming' => true,
            'supports_function_calling' => true,
            'supports_json_mode' => false,
            'is_active' => true,
            'is_deprecated' => false,
        ]);

        $mockDriver = Mockery::mock(EngineDriverInterface::class);
        $mockDriver->shouldReceive('validateRequest')->once();
        $mockDriver->shouldReceive('generate')->once()->andReturn(
            AIResponse::success('ok', 'openai', 'gpt-4o-mini')
        );

        $registry = new DriverRegistry($this->app);
        $registry->register('openai', static fn () => $mockDriver);
        $this->app->instance(
            AIEngineService::class,
            new AIEngineService(
                app(CreditManager::class),
                null,
                $registry,
                app(RequestRouteResolver::class)
            )
        );

        $request = new AIRequest(
            prompt: 'hello world',
            engine: 'openai',
            model: 'gpt-4o-mini',
            userId: (string) $user->id
        );
        $expectedCredits = app(CreditManager::class)->calculateCredits($request);

        $this->actingAs($user);

        $response = $this->postJson('/api/v1/ai/generate/text', [
            'prompt' => 'hello world',
            'preference' => 'cost',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.engine', 'openai')
            ->assertJsonPath('data.model', 'gpt-4o-mini');

        $user->refresh();
        $remaining = (float) data_get($user->entity_credits, 'openai.gpt-4o-mini.balance', 0.0);

        $this->assertEqualsWithDelta(100.0 - $expectedCredits, $remaining, 0.0001);
    }
}
