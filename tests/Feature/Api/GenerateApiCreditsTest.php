<?php

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
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
}
