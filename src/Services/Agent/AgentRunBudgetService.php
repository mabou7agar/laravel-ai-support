<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\RateLimitManager;

class AgentRunBudgetService
{
    public function __construct(
        private readonly CreditManager $credits,
        private readonly RateLimitManager $rateLimits,
        private readonly AgentRunRepository $runs
    ) {}

    public function calculateCredits(AIRequest $request): float
    {
        return $this->credits->calculateCredits($request);
    }

    public function hasCredits(string $userId, AIRequest $request): bool
    {
        return $this->credits->hasCredits($userId, $request);
    }

    public function deductCredits(string $userId, AIRequest $request, ?float $actualCreditsUsed = null): bool
    {
        return $this->credits->deductCredits($userId, $request, $actualCreditsUsed);
    }

    public function startAccumulatingCredits(): void
    {
        CreditManager::startAccumulating();
    }

    public function finishAccumulatingCredits(AIAgentRun $run): AIAgentRun
    {
        $creditsUsed = CreditManager::stopAccumulating();
        $metadata = $run->metadata ?? [];
        $metadata['credits_used'] = $creditsUsed;

        return $this->runs->update($run, ['metadata' => $metadata]);
    }

    public function checkRateLimit(string|EngineEnum $engine, int|string|null $userId = null): bool
    {
        $engine = $engine instanceof EngineEnum ? $engine : EngineEnum::from((string) $engine);

        return $this->rateLimits->checkRateLimit($engine, $userId === null ? null : (string) $userId);
    }

    public function checkScopedRateLimit(string|EngineEnum $engine, array $scope = []): bool
    {
        $engine = $engine instanceof EngineEnum ? $engine : EngineEnum::from((string) $engine);
        $userId = isset($scope['user_id']) ? (string) $scope['user_id'] : null;

        return $this->rateLimits->checkRateLimit($engine, $userId, $scope);
    }

    public function startRunBudget(int|string|AIAgentRun $runId, int|string|null $ownerId, array $limits): AIAgentRun
    {
        return $this->credits->startRunBudget($runId, $ownerId, $limits);
    }

    public function recordRunUsage(int|string|AIAgentRun $runId, AIResponse|array $usage): AIAgentRun
    {
        return $this->credits->recordRunUsage($runId, $usage);
    }

    public function remainingRunBudget(int|string|AIAgentRun $runId): array
    {
        return $this->credits->remainingRunBudget($runId);
    }

    public function assertRunBudgetAvailable(int|string|AIAgentRun $runId): bool
    {
        return $this->credits->assertRunBudgetAvailable($runId);
    }

    public function assertRuntimeBudgetAllows(AIAgentRun $run, array $policy): void
    {
        $maxTokens = $policy['max_tokens'] ?? $run->metadata['max_tokens'] ?? config('ai-agent.run_safety.queue.max_tokens');
        $usedTokens = $policy['tokens_used'] ?? $run->metadata['tokens_used'] ?? null;
        if ($maxTokens !== null && $usedTokens !== null && (int) $usedTokens > (int) $maxTokens) {
            throw new \RuntimeException("Agent run exceeded token budget [{$maxTokens}].");
        }

        $maxCost = $policy['max_cost'] ?? $run->metadata['max_cost'] ?? config('ai-agent.run_safety.queue.max_cost');
        $usedCost = $policy['cost_used'] ?? $run->metadata['cost_used'] ?? null;
        if ($maxCost !== null && $usedCost !== null && (float) $usedCost > (float) $maxCost) {
            throw new \RuntimeException("Agent run exceeded cost budget [{$maxCost}].");
        }
    }

    public function budgetExceededResponse(string $message, AIAgentRun $run): AgentResponse
    {
        $response = AgentResponse::failure($message, [
            'run_id' => $run->uuid,
            'status' => 'budget_exceeded',
        ]);
        $response->metadata = [
            'agent_run_id' => $run->uuid,
            'budget_exceeded' => true,
        ];

        return $response;
    }
}
