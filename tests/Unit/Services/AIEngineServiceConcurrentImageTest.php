<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Events\AIRequestCompleted;
use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

final class AIEngineServiceConcurrentImageTest extends TestCase
{
    protected function tearDown(): void
    {
        if (CreditManager::isAccumulating()) {
            CreditManager::stopAccumulating();
        }

        parent::tearDown();
    }

    public function test_concurrent_images_are_preflighted_deducted_accumulated_and_observed(): void
    {
        Event::fake();
        CreditManager::startAccumulating();

        $user = $this->createTestUser();
        $requests = $this->requests((string) $user->id, 3);
        $credits = Mockery::mock(CreditManager::class);
        $credits->shouldReceive('calculateCredits')->times(3)->andReturn(6.0);
        $credits->shouldReceive('hasCreditsForAmount')
            ->once()
            ->with((string) $user->id, Mockery::type(AIRequest::class), 18.0)
            ->andReturnTrue();
        $credits->shouldReceive('deductCredits')
            ->times(3)
            ->with((string) $user->id, Mockery::type(AIRequest::class), 6.0)
            ->andReturnTrue();

        $responses = $this->service($credits)->generateImagesConcurrently($requests);

        self::assertCount(3, $responses);
        self::assertSame(18.0, CreditManager::stopAccumulating());
        foreach ($responses as $response) {
            self::assertTrue($response->isSuccessful());
            self::assertSame(6.0, $response->getCreditsUsed());
        }
        Event::assertDispatchedTimes(AIRequestStarted::class, 3);
        Event::assertDispatchedTimes(AIRequestCompleted::class, 3);
        Event::assertDispatched(
            AIRequestCompleted::class,
            static fn (AIRequestCompleted $event): bool => $event->providerCostUsd === 0.01
                && $event->creditsUsed === 6.0
        );
    }

    public function test_concurrent_image_batch_is_rejected_before_provider_spend_when_total_is_unaffordable(): void
    {
        Event::fake();

        $user = $this->createTestUser();
        $driver = new ConcurrentImageTestDriver();
        $credits = Mockery::mock(CreditManager::class);
        $credits->shouldReceive('calculateCredits')->times(3)->andReturn(6.0);
        $credits->shouldReceive('hasCreditsForAmount')->once()->andReturnFalse();
        $credits->shouldNotReceive('deductCredits');

        $this->expectException(InsufficientCreditsException::class);

        try {
            $this->service($credits, $driver)->generateImagesConcurrently(
                $this->requests((string) $user->id, 3)
            );
        } finally {
            self::assertSame(0, $driver->batchCalls);
            Event::assertNotDispatched(AIRequestStarted::class);
        }
    }

    public function test_concurrent_images_settle_against_real_provider_cost(): void
    {
        Event::fake();
        Config::set('ai-engine.credits.retail_pricing', [
            'enabled' => true,
            'usd_per_credit' => 0.001,
            'target_gross_margin_percent' => 25.0,
            'provider_funding_fee_percent' => 5.5,
            'rounding_increment_credits' => 0.01,
        ]);

        $user = $this->createTestUser();
        $credits = Mockery::mock(CreditManager::class);
        $credits->shouldReceive('calculateCredits')->twice()->andReturn(0.12);
        $credits->shouldReceive('hasCreditsForAmount')
            ->once()
            ->with((string) $user->id, Mockery::type(AIRequest::class), 0.24)
            ->andReturnTrue();
        $credits->shouldReceive('deductCredits')
            ->twice()
            ->with((string) $user->id, Mockery::type(AIRequest::class), 9.46)
            ->andReturnTrue();

        $responses = $this->service(
            $credits,
            new ConcurrentImageTestDriver(0.006719)
        )->generateImagesConcurrently($this->requests((string) $user->id, 2));

        self::assertSame([9.46, 9.46], array_map(
            static fn (AIResponse $response): float => $response->getCreditsUsed(),
            $responses
        ));
    }

    /**
     * @return array<int, AIRequest>
     */
    private function requests(string $userId, int $count): array
    {
        $requests = [];
        for ($index = 0; $index < $count; $index++) {
            $requests[] = new AIRequest(
                prompt: 'Image ' . $index,
                engine: EngineEnum::OPENROUTER,
                model: 'openai/gpt-image-2',
                userId: $userId
            );
        }

        return $requests;
    }

    private function service(
        CreditManager $credits,
        ?ConcurrentImageTestDriver $driver = null
    ): AIEngineService {
        $registry = new DriverRegistry($this->app);
        $registry->register(
            EngineEnum::OPENROUTER,
            static fn (): ConcurrentImageTestDriver => $driver ?? new ConcurrentImageTestDriver()
        );

        return new AIEngineService(
            $credits,
            app(ConversationManager::class),
            $registry
        );
    }
}

final class ConcurrentImageTestDriver implements EngineDriverInterface
{
    public int $batchCalls = 0;

    public function __construct(
        private readonly float $providerCostUsd = 0.01
    ) {}

    public function generateImagesConcurrently(array $requests): array
    {
        $this->batchCalls++;

        return array_map(
            fn (AIRequest $request): AIResponse => AIResponse::success(
                'image',
                $request->getEngine(),
                $request->getModel(),
                ['usage' => ['provider_cost_usd' => $this->providerCostUsd]]
            ),
            $requests
        );
    }

    public function generate(AIRequest $request): AIResponse
    {
        return $this->generateImagesConcurrently([$request])[0];
    }

    public function stream(AIRequest $request): \Generator
    {
        yield 'image';
    }

    public function validateRequest(AIRequest $request): bool
    {
        return true;
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::OPENROUTER);
    }

    public function supports(string $capability): bool
    {
        return $capability === 'image';
    }

    public function getAvailableModels(): array
    {
        return ['openai/gpt-image-2'];
    }

    public function test(): bool
    {
        return true;
    }

    public function generateJsonAnalysis(
        string $prompt,
        string $systemPrompt,
        ?string $model = null,
        int $maxTokens = 300
    ): string {
        return '{}';
    }
}
