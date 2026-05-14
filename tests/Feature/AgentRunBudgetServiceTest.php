<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Exceptions\RateLimitExceededException;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Services\Agent\AgentRunBudgetService;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\RateLimitManager;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentRunBudgetServiceTest extends TestCase
{
    public function test_budget_service_delegates_credit_calculation_checks_and_deduction(): void
    {
        $credits = Mockery::mock(CreditManager::class);
        $rateLimits = Mockery::mock(RateLimitManager::class);
        $request = Mockery::mock(AIRequest::class);
        $service = new AgentRunBudgetService($credits, $rateLimits, app(AgentRunRepository::class));

        $credits->shouldReceive('calculateCredits')->once()->with($request)->andReturn(1.25);
        $credits->shouldReceive('hasCredits')->once()->with('7', $request)->andReturnTrue();
        $credits->shouldReceive('deductCredits')->once()->with('7', $request, 1.0)->andReturnTrue();

        $this->assertSame(1.25, $service->calculateCredits($request));
        $this->assertTrue($service->hasCredits('7', $request));
        $this->assertTrue($service->deductCredits('7', $request, 1.0));
    }

    public function test_budget_service_persists_accumulated_credits_on_run_metadata(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'credits-session',
            'status' => AIAgentRun::STATUS_RUNNING,
            'metadata' => ['existing' => true],
        ]);
        $service = app(AgentRunBudgetService::class);

        $service->startAccumulatingCredits();
        CreditManager::accumulate(2.5);
        $updated = $service->finishAccumulatingCredits($run);

        $this->assertSame(2.5, $updated->metadata['credits_used']);
        $this->assertTrue($updated->metadata['existing']);
    }

    public function test_budget_service_reuses_rate_limit_manager(): void
    {
        config()->set('ai-engine.rate_limiting.enabled', true);
        config()->set('ai-engine.rate_limiting.driver', 'array');
        config()->set('ai-engine.rate_limiting.per_engine.openai', [
            'requests' => 1,
            'per_minute' => 1,
        ]);

        $service = app(AgentRunBudgetService::class);

        $this->assertTrue($service->checkRateLimit(EngineEnum::OPENAI, 'user-1'));

        $this->expectException(RateLimitExceededException::class);
        $service->checkRateLimit('openai', 'user-1');
    }

    public function test_budget_service_supports_scoped_rate_limit_keys(): void
    {
        config()->set('ai-engine.rate_limiting.enabled', true);
        config()->set('ai-engine.rate_limiting.driver', 'array');
        config()->set('ai-engine.rate_limiting.per_engine.openai', [
            'requests' => 1,
            'per_minute' => 1,
        ]);

        $service = app(AgentRunBudgetService::class);

        $this->assertTrue($service->checkScopedRateLimit(EngineEnum::OPENAI, [
            'user_id' => 'user-2',
            'runtime' => 'laravel',
            'run_id' => 'run-a',
            'tenant_id' => 'tenant-1',
            'workspace_id' => 'workspace-1',
            'tool' => 'web_search',
            'provider' => 'openai',
        ]));
        $this->assertTrue($service->checkScopedRateLimit(EngineEnum::OPENAI, [
            'user_id' => 'user-2',
            'runtime' => 'laravel',
            'run_id' => 'run-b',
            'tenant_id' => 'tenant-1',
            'workspace_id' => 'workspace-1',
            'tool' => 'web_search',
            'provider' => 'openai',
        ]));
        $this->assertTrue($service->checkRateLimit(EngineEnum::OPENAI, 'user-2'));

        $this->expectException(RateLimitExceededException::class);
        $service->checkScopedRateLimit(EngineEnum::OPENAI, [
            'user_id' => 'user-2',
            'runtime' => 'laravel',
            'run_id' => 'run-a',
            'tenant_id' => 'tenant-1',
            'workspace_id' => 'workspace-1',
            'tool' => 'web_search',
            'provider' => 'openai',
        ]);
    }

    public function test_budget_service_enforces_runtime_policy_and_builds_budget_response(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'budget-session',
            'status' => AIAgentRun::STATUS_RUNNING,
            'metadata' => ['tokens_used' => 120],
        ]);
        $service = app(AgentRunBudgetService::class);

        try {
            $service->assertRuntimeBudgetAllows($run, ['max_tokens' => 100]);
            $this->fail('Budget exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Agent run exceeded token budget [100].', $e->getMessage());
        }

        $response = $service->budgetExceededResponse('Budget exceeded.', $run);

        $this->assertFalse($response->success);
        $this->assertTrue($response->metadata['budget_exceeded']);
        $this->assertSame($run->uuid, $response->data['run_id']);
    }
}
