<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Design;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\WebsiteGenerationRequest;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Design\WebsiteBuilderService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class WebsiteBuilderCreditsTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function compliantHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1">
<style>:root{--color-primary:#1E40AF}body{font-family:"Fira Sans"}h1{font-family:"Fira Code"}
.b{cursor:pointer;transition:opacity 200ms}.b:focus{outline:2px solid}
@media (prefers-reduced-motion:reduce){*{transition:none}}</style></head>
<body><main><h1>Hi</h1><button class="b">Go</button></main></body></html>
HTML;
    }

    public function test_charges_flat_website_surcharge_after_successful_build(): void
    {
        config()->set('ai-engine.credits.enabled', true);
        config()->set('ai-engine.design.credit_cost', 5.0);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()
            ->andReturn(AIResponse::success($this->compliantHtml(), 'openai', 'gpt-4o'));
        $this->instance(AIEngineService::class, $ai);

        $credits = Mockery::mock(CreditManager::class);
        $credits->shouldReceive('hasCreditsForAmount')->once()->andReturn(true);
        $credits->shouldReceive('deductCredits')->once()
            ->withArgs(fn ($userId, $request, $amount) => $userId === '7' && $amount === 5.0)
            ->andReturn(true);
        $this->instance(CreditManager::class, $credits);

        $result = app(WebsiteBuilderService::class)->build(new WebsiteGenerationRequest(
            prompt: 'SaaS analytics dashboard modern minimal',
            projectName: 'DemoSaaS',
            userId: '7',
        ));

        $this->assertSame(5.0, $result->metadata['website_credit_cost']);
        $this->assertGreaterThanOrEqual(5.0, $result->creditsUsed);
    }

    public function test_refuses_generation_when_credits_insufficient(): void
    {
        config()->set('ai-engine.credits.enabled', true);
        config()->set('ai-engine.design.credit_cost', 5.0);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->never();
        $this->instance(AIEngineService::class, $ai);

        $credits = Mockery::mock(CreditManager::class);
        $credits->shouldReceive('hasCreditsForAmount')->once()->andReturn(false);
        $credits->shouldReceive('deductCredits')->never();
        $this->instance(CreditManager::class, $credits);

        $this->expectException(InsufficientCreditsException::class);

        app(WebsiteBuilderService::class)->build(new WebsiteGenerationRequest(
            prompt: 'SaaS analytics dashboard modern minimal',
            projectName: 'DemoSaaS',
            userId: '7',
        ));
    }

    public function test_no_surcharge_when_cost_is_zero(): void
    {
        config()->set('ai-engine.credits.enabled', true);
        config()->set('ai-engine.design.credit_cost', 0.0);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()
            ->andReturn(AIResponse::success($this->compliantHtml(), 'openai', 'gpt-4o'));
        $this->instance(AIEngineService::class, $ai);

        $credits = Mockery::mock(CreditManager::class);
        $credits->shouldReceive('hasCreditsForAmount')->never();
        $credits->shouldReceive('deductCredits')->never();
        $this->instance(CreditManager::class, $credits);

        $result = app(WebsiteBuilderService::class)->build(new WebsiteGenerationRequest(
            prompt: 'SaaS analytics dashboard modern minimal',
            projectName: 'DemoSaaS',
            userId: '7',
        ));

        $this->assertSame(0.0, $result->metadata['website_credit_cost']);
    }
}
