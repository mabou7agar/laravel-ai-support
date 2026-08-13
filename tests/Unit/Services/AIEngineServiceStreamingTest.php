<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

final class AIEngineServiceStreamingTest extends TestCase
{
    public function test_stream_settles_credits_from_the_provider_usage_response(): void
    {
        Event::fake();
        config()->set('ai-engine.credits.enabled', true);
        config()->set('ai-engine.nodes.enabled', false);
        config()->set('ai-engine.error_handling.fallback_engines.openrouter', []);
        config()->set('ai-engine.credits.retail_pricing', [
            'enabled' => true,
            'settlement_mode' => 'provider_cost',
            'usd_per_credit' => 0.001,
            'target_gross_margin_percent' => 0.0,
            'provider_funding_fee_percent' => 0.0,
            'rounding_increment_credits' => 0.01,
        ]);

        $credits = Mockery::mock(CreditManager::class);
        $credits->expects('hasCredits')->once()->andReturnTrue();
        $credits->expects('calculateCredits')->once()->andReturn(96.0);
        $credits->expects('deductCredits')
            ->once()
            ->with('48', Mockery::type(AIRequest::class), 1.0)
            ->andReturnTrue();
        $registry = new DriverRegistry($this->app);
        $registry->register(
            EngineEnum::OPENROUTER,
            static fn (): StreamingUsageTestDriver => new StreamingUsageTestDriver(),
        );
        $service = new AIEngineService(
            $credits,
            app(ConversationManager::class),
            $registry,
        );
        $request = new AIRequest(
            prompt: 'Stream accurately.',
            engine: EngineEnum::OPENROUTER,
            model: 'openai/gpt-4o-mini',
            userId: '48',
        );

        $stream = $service->stream($request);
        $chunks = iterator_to_array($stream);
        $response = $stream->getReturn();

        self::assertSame(['Accurate', ' billing'], $chunks);
        self::assertInstanceOf(AIResponse::class, $response);
        self::assertSame('Accurate billing', $response->getContent());
        self::assertSame(1.0, $response->getCreditsUsed());
    }
}

final class StreamingUsageTestDriver implements EngineDriverInterface
{
    public function generate(AIRequest $request): AIResponse
    {
        return AIResponse::success('', $request->getEngine(), $request->getModel());
    }

    public function stream(AIRequest $request): \Generator
    {
        yield 'Accurate';
        yield ' billing';

        return AIResponse::success(
            'Accurate billing',
            $request->getEngine(),
            $request->getModel(),
            ['usage' => ['cost' => 0.001]],
        )->withDetailedUsage([
            'total_tokens' => 12,
            'cost' => 0.001,
        ]);
    }

    public function validateRequest(AIRequest $request): bool
    {
        return true;
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::OPENROUTER;
    }

    public function supports(string $capability): bool
    {
        return $capability === 'streaming';
    }

    public function getAvailableModels(): array
    {
        return ['openai/gpt-4o-mini'];
    }

    public function test(): bool
    {
        return true;
    }

    public function generateJsonAnalysis(
        string $prompt,
        string $systemPrompt,
        ?string $model = null,
        int $maxTokens = 300,
    ): string {
        return '{}';
    }
}
