<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AICreditReservation;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\CreditReservationRepository;

class CreditManager
{
    protected static ?string $globalQueryResolver = null;
    protected static ?string $globalLifecycleHandler = null;
    protected static array $columnSupportCache = [];
    
    /**
     * Request-scoped credit accumulator for tracking cumulative credits
     * across multiple AI calls within a single request
     */
    protected static float $requestCreditsAccumulator = 0.0;
    protected static bool $isAccumulating = false;
    
    public function __construct(
        private Application $app
    ) {}
    
    /**
     * Start accumulating credits for this request.
     * Call this at the beginning of a chat request to track all AI calls.
     */
    public static function startAccumulating(): void
    {
        static::$requestCreditsAccumulator = 0.0;
        static::$isAccumulating = true;
    }
    
    /**
     * Stop accumulating and return total credits used.
     */
    public static function stopAccumulating(): float
    {
        $total = static::$requestCreditsAccumulator;
        static::$requestCreditsAccumulator = 0.0;
        static::$isAccumulating = false;
        return $total;
    }
    
    /**
     * Add credits to the accumulator (called by AIEngineService after each AI call).
     */
    public static function accumulate(float $credits): void
    {
        if (static::$isAccumulating) {
            static::$requestCreditsAccumulator += $credits;
        }
    }
    
    /**
     * Get current accumulated credits without stopping.
     */
    public static function getAccumulatedCredits(): float
    {
        return static::$requestCreditsAccumulator;
    }
    
    /**
     * Check if currently accumulating credits.
     */
    public static function isAccumulating(): bool
    {
        return static::$isAccumulating;
    }
    
    /**
     * Set global query resolver at runtime
     */
    public static function setQueryResolver(?string $resolverClass): void
    {
        static::$globalQueryResolver = $resolverClass;
    }
    
    /**
     * Set global credit lifecycle handler at runtime
     */
    public static function setLifecycleHandler(?string $handlerClass): void
    {
        static::$globalLifecycleHandler = $handlerClass;
    }
    
    /**
     * Get the configured query resolver
     */
    protected function getQueryResolverClass(): ?string
    {
        return static::$globalQueryResolver ?? config('ai-engine.credits.query_resolver');
    }
    
    /**
     * Get the configured lifecycle handler
     */
    protected function getLifecycleHandlerClass(): ?string
    {
        return static::$globalLifecycleHandler ?? config('ai-engine.credits.lifecycle_handler');
    }
    
    /**
     * Get lifecycle handler instance
     */
    protected function getLifecycleHandler(): ?\LaravelAIEngine\Contracts\CreditLifecycleInterface
    {
        $handlerClass = $this->getLifecycleHandlerClass();
        
        if ($handlerClass && class_exists($handlerClass)) {
            try {
                $handler = app($handlerClass);
                if ($handler instanceof \LaravelAIEngine\Contracts\CreditLifecycleInterface) {
                    return $handler;
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to instantiate lifecycle handler', [
                    'handler' => $handlerClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return null;
    }

    /**
     * Calculate required MyCredits for a request (with engine conversion)
     */
    public function calculateCredits(AIRequest $request): float
    {
        return $this->calculateCreditBreakdown($request)['final_credits'];
    }

    /**
     * Calculate a transparent credit breakdown for audits, dry-runs, and UI previews.
     *
     * @return array{
     *     engine:string,
     *     model:string,
     *     calculation_method:string,
     *     input_count:float,
     *     credit_index:float,
     *     base_engine_credits:float,
     *     additional_input_engine_credits:float,
     *     total_engine_credits:float,
     *     engine_rate:float,
     *     final_credits:float
     * }
     */
    public function calculateCreditBreakdown(AIRequest $request): array
    {
        $inputCount = $this->getInputCount($request);
        $creditIndex = $request->model->creditIndex();
        $baseEngineCredits = $inputCount * $creditIndex;
        $additionalInputCredits = $this->getAdditionalInputUnitEngineCredits($request);
        $totalEngineCredits = $baseEngineCredits + $additionalInputCredits;
        $engineRate = $this->getEngineRate($request->engine);

        return [
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'calculation_method' => $request->model->calculationMethod(),
            'input_count' => round($inputCount, 8),
            'credit_index' => round($creditIndex, 8),
            'base_engine_credits' => round($baseEngineCredits, 8),
            'additional_input_engine_credits' => round($additionalInputCredits, 8),
            'total_engine_credits' => round($totalEngineCredits, 8),
            'engine_rate' => round($engineRate, 8),
            'final_credits' => round($totalEngineCredits * $engineRate, 8),
        ];
    }

    /**
     * Get engine conversion rate (MyCredits to Engine Credits)
     */
    private function getEngineRate(EngineEnum $engine): float
    {
        $rates = config('ai-engine.credits.engine_rates', []);
        return $rates[$engine->value] ?? 1.0;
    }

    /**
     * Check if user has enough MyCredits for a request
     */
    public function hasCredits(string $userId, AIRequest $request): bool
    {
        return $this->hasCreditsForAmount($userId, $request, $this->calculateCredits($request));
    }

    /**
     * Check if user has enough MyCredits for a known credit amount.
     */
    public function hasCreditsForAmount(string $userId, AIRequest $request, float $requiredCredits): bool
    {
        $user = $this->getUserModel($userId);

        if ($this->usesEntityCreditLedger($user)) {
            $entry = $this->getEntityCreditEntry($user, $request->engine, $request->model);
            if (($entry['is_unlimited'] ?? false) === true) {
                return true;
            }

            return ((float) ($entry['balance'] ?? 0.0)) >= $requiredCredits;
        }
        
        // Use custom lifecycle handler if configured
        $handler = $this->getLifecycleHandler();
        if ($handler) {
            return $handler->getAvailableCredits($user) >= $requiredCredits || $handler->hasCredits($user, $request);
        }

        if (!$this->supportsScalarCreditFields($user)) {
            return true;
        }
        
        // Default behavior
        // Check unlimited first
        if ($this->readUnlimitedCreditsFlag($user)) {
            return true;
        }
        
        return $this->readScalarCreditBalance($user) >= $requiredCredits;
    }

    /**
     * Deduct MyCredits from user
     */
    public function deductCredits(string $userId, AIRequest $request, float $actualCreditsUsed = null): bool
    {
        $user = $this->getUserModel($userId);
        
        $creditsToDeduct = $actualCreditsUsed ?? $this->calculateCredits($request);

        if ($this->usesEntityCreditLedger($user)) {
            $entry = $this->getEntityCreditEntry($user, $request->engine, $request->model);
            if (($entry['is_unlimited'] ?? false) === true) {
                return true;
            }

            $currentBalance = (float) ($entry['balance'] ?? 0.0);
            if ($currentBalance < $creditsToDeduct) {
                throw new InsufficientCreditsException(
                    "Insufficient credits. Required: {$creditsToDeduct}, Available: {$currentBalance}"
                );
            }

            $this->setEntityCreditEntry(
                $user,
                $request->engine,
                $request->model,
                $currentBalance - $creditsToDeduct,
                false
            );

            return true;
        }
        
        // Use custom lifecycle handler if configured
        $handler = $this->getLifecycleHandler();
        if ($handler) {
            return $handler->deductCredits($user, $request, $creditsToDeduct);
        }

        if (!$this->supportsScalarCreditFields($user)) {
            return true;
        }
        
        // Default behavior
        // Don't deduct if unlimited
        if ($this->readUnlimitedCreditsFlag($user)) {
            return true;
        }
        
        $currentBalance = $this->readScalarCreditBalance($user);
        if ($currentBalance < $creditsToDeduct) {
            throw new InsufficientCreditsException(
                "Insufficient MyCredits. Required: {$creditsToDeduct}, Available: {$currentBalance}"
            );
        }

        return $this->writeScalarCreditState($user, $currentBalance - $creditsToDeduct, false);
    }

    /**
     * Reserve credits by deducting them now and storing a reservation that can be finalized or refunded later.
     *
     * @param array<string, mixed> $metadata
     */
    public function reserveCredits(
        string $userId,
        AIRequest $request,
        ?float $creditsToReserve = null,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): ?AICreditReservation {
        $amount = $creditsToReserve ?? $this->calculateCredits($request);
        if ($amount <= 0.0) {
            return null;
        }

        if (!$this->creditReservationTableAvailable()) {
            $this->deductCredits($userId, $request, $amount);
            return null;
        }

        $repository = $this->creditReservationRepository();
        $existing = $repository->findByIdempotencyKey($idempotencyKey);
        if ($existing instanceof AICreditReservation) {
            return $existing;
        }

        return DB::transaction(function () use ($userId, $request, $amount, $metadata, $idempotencyKey, $repository): AICreditReservation {
            if (!$this->hasCreditsForAmount($userId, $request, $amount)) {
                throw new InsufficientCreditsException('Insufficient credits for this request');
            }

            $this->deductCredits($userId, $request, $amount);

            return $repository->createReserved($userId, $request, $amount, $metadata, $idempotencyKey);
        });
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function finalizeCreditReservation(?string $reservationUuid, array $metadata = []): bool
    {
        if (!$this->creditReservationTableAvailable() || $reservationUuid === null || trim($reservationUuid) === '') {
            return false;
        }

        $reservation = $this->creditReservationRepository()->findByUuid($reservationUuid);
        if (!$reservation instanceof AICreditReservation || $reservation->status !== AICreditReservation::STATUS_RESERVED) {
            return false;
        }

        return $this->creditReservationRepository()->markFinalized($reservation, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function refundCreditReservation(?string $reservationUuid, array $metadata = []): bool
    {
        if (!$this->creditReservationTableAvailable() || $reservationUuid === null || trim($reservationUuid) === '') {
            return false;
        }

        $reservation = $this->creditReservationRepository()->findByUuid($reservationUuid);
        if (!$reservation instanceof AICreditReservation || $reservation->status !== AICreditReservation::STATUS_RESERVED) {
            return false;
        }

        return DB::transaction(function () use ($reservation, $metadata): bool {
            $this->addCredits(
                (string) $reservation->owner_id,
                (float) $reservation->amount,
                metadata: array_merge($metadata, ['reservation_uuid' => $reservation->uuid]),
                engine: $reservation->engine,
                model: $reservation->ai_model
            );

            return $this->creditReservationRepository()->markRefunded($reservation, $metadata);
        });
    }

    /**
     * Add MyCredits to user
     */
    public function addCredits(
        string $userId,
        float $credits,
        array $metadata = [],
        EngineEnum|string|null $engine = null,
        EntityEnum|string|null $model = null
    ): bool
    {
        $user = $this->getUserModel($userId);
        $engine = $engine === null ? null : $this->resolveEngine($engine);
        $model = $model === null ? null : $this->resolveModel($model);

        if ($engine !== null && $model !== null && $this->usesEntityCreditLedger($user)) {
            $entry = $this->getEntityCreditEntry($user, $engine, $model);
            $this->setEntityCreditEntry(
                $user,
                $engine,
                $model,
                (float) ($entry['balance'] ?? 0.0) + $credits,
                (bool) ($entry['is_unlimited'] ?? false)
            );

            return true;
        }
        
        // Use custom lifecycle handler if configured
        $handler = $this->getLifecycleHandler();
        if ($handler) {
            return $handler->addCredits($user, $credits, $metadata);
        }
        
        // Default behavior
        $user->my_credits += $credits;
        return $user->save();
    }

    /**
     * Set user MyCredits balance
     */
    public function setCredits(
        string $userId,
        float $credits,
        EngineEnum|string|null $engine = null,
        EntityEnum|string|null $model = null
    ): bool
    {
        $user = $this->getUserModel($userId);
        $engine = $engine === null ? null : $this->resolveEngine($engine);
        $model = $model === null ? null : $this->resolveModel($model);

        if ($engine !== null && $model !== null && $this->usesEntityCreditLedger($user)) {
            $this->setEntityCreditEntry($user, $engine, $model, $credits, false);
            return true;
        }

        $user->my_credits = $credits;
        return $user->save();
    }

    /**
     * Set unlimited credits for user
     */
    public function setUnlimitedCredits(
        string $userId,
        bool $unlimited = true,
        EngineEnum|string|null $engine = null,
        EntityEnum|string|null $model = null
    ): bool
    {
        $user = $this->getUserModel($userId);

        $engine = $engine === null ? null : $this->resolveEngine($engine);
        $model = $model === null ? null : $this->resolveModel($model);
        if ($engine !== null && $model !== null && $this->usesEntityCreditLedger($user)) {
            $entry = $this->getEntityCreditEntry($user, $engine, $model);
            $this->setEntityCreditEntry(
                $user,
                $engine,
                $model,
                (float) ($entry['balance'] ?? config('ai-engine.credits.default_balance', 100.0)),
                $unlimited
            );

            return true;
        }

        $user->has_unlimited_credits = $unlimited;
        return $user->save();
    }

    /**
     * Get user MyCredits balance
     */
    public function getUserCredits(string $userId, mixed ...$args): array
    {
        $user = $this->getUserModel($userId);

        if (count($args) >= 2) {
            $engine = $this->resolveEngine($args[0]);
            $model = $this->resolveModel($args[1]);
            $entry = $this->getEntityCreditEntry($user, $engine, $model);

            return [
                'balance' => (float) ($entry['balance'] ?? config('ai-engine.credits.default_balance', 100.0)),
                'is_unlimited' => (bool) ($entry['is_unlimited'] ?? false),
                'currency' => config('ai-engine.credits.currency', 'MyCredits'),
            ];
        }
        
        return [
            'balance' => $user->my_credits ?? 0,
            'is_unlimited' => $user->has_unlimited_credits ?? false,
            'currency' => config('ai-engine.credits.currency', 'MyCredits'),
        ];
    }

    /**
     * Get user credits converted for specific engine
     */
    public function getUserCreditsForEngine(string $userId, EngineEnum $engine): array
    {
        $user = $this->getUserModel($userId);
        $rate = $this->getEngineRate($engine);
        
        return [
            'my_credits' => $user->my_credits ?? 0,
            'engine_credits' => ($user->my_credits ?? 0) / $rate,
            'is_unlimited' => $user->has_unlimited_credits ?? false,
            'conversion_rate' => $rate,
            'engine' => $engine->value,
        ];
    }

    /**
     * Get user credits for all engines
     */
    public function getAllUserCredits(string $userId): array
    {
        $user = $this->getUserModel($userId);
        $myCredits = $user->my_credits ?? 0;
        $rates = config('ai-engine.credits.engine_rates', []);
        
        $credits = [
            'my_credits' => $myCredits,
            'is_unlimited' => $user->has_unlimited_credits ?? false,
            'engines' => [],
        ];
        
        foreach ($rates as $engine => $rate) {
            $credits['engines'][$engine] = [
                'engine_credits' => $myCredits / $rate,
                'conversion_rate' => $rate,
            ];
        }
        
        return $credits;
    }

    /**
     * Get total MyCredits balance
     */
    public function getTotalCredits(string $userId): float
    {
        $user = $this->getUserModel($userId);

        if ($this->usesEntityCreditLedger($user)) {
            $ledger = $this->getEntityCreditLedger($user);
            $total = 0.0;

            foreach ($ledger as $engineCredits) {
                if (!is_array($engineCredits)) {
                    continue;
                }

                foreach ($engineCredits as $modelCredits) {
                    if (!is_array($modelCredits)) {
                        continue;
                    }

                    if (($modelCredits['is_unlimited'] ?? false) === true) {
                        return PHP_FLOAT_MAX;
                    }

                    $total += (float) ($modelCredits['balance'] ?? 0.0);
                }
            }

            return $total;
        }

        $handler = $this->getLifecycleHandler();
        if ($handler) {
            return $handler->getAvailableCredits($user);
        }
        
        if ($this->readUnlimitedCreditsFlag($user)) {
            return PHP_FLOAT_MAX;
        }
        
        return $this->readScalarCreditBalance($user);
    }

    /**
     * Check if user has low credits
     */
    public function hasLowCredits(string $userId): bool
    {
        $threshold = config('ai-engine.credits.low_balance_threshold', 10.0);
        return $this->getTotalCredits($userId) < $threshold;
    }

    /**
     * Get credit usage statistics
     */
    public function getUsageStats(string $userId, ?EngineEnum $engine = null, ?EntityEnum $model = null): array
    {
        // This would typically query a usage tracking table
        // For now, return basic structure
        return [
            'total_requests' => 0,
            'total_credits_used' => 0.0,
            'average_credits_per_request' => 0.0,
            'most_used_engine' => null,
            'most_used_model' => null,
            'period' => '30_days',
        ];
    }

    /**
     * Start tracking a budget envelope for an agent run.
     */
    public function startRunBudget(int|string|AIAgentRun $runId, int|string|null $ownerId, array $limits): AIAgentRun
    {
        $run = $this->resolveRun($runId);
        $metadata = $run->metadata ?? [];

        $metadata['budget'] = [
            'owner_id' => $ownerId === null ? null : (string) $ownerId,
            'limits' => $this->normalizeRunBudgetLimits($limits),
            'usage' => [
                'tokens' => 0,
                'cost' => 0.0,
                'credits' => 0.0,
                'requests' => 0,
            ],
            'started_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        foreach (['max_tokens', 'max_cost', 'max_credits'] as $key) {
            if (array_key_exists($key, $metadata['budget']['limits'])) {
                $metadata[$key] = $metadata['budget']['limits'][$key];
            }
        }

        return $this->runRepository()->update($run, ['metadata' => $metadata]);
    }

    /**
     * Record usage against a run budget, optionally deducting credits from the configured owner.
     */
    public function recordRunUsage(int|string|AIAgentRun $runId, AIResponse|array $usage): AIAgentRun
    {
        $run = $this->resolveRun($runId);
        $metadata = $run->metadata ?? [];
        $budget = $metadata['budget'] ?? [
            'owner_id' => $run->user_id === null ? null : (string) $run->user_id,
            'limits' => [],
            'usage' => [],
        ];

        $delta = $this->normalizeRunUsage($usage);
        $limits = $this->normalizeRunBudgetLimits($budget['limits'] ?? []);

        if (($limits['deduct_credits'] ?? false) === true && ($budget['owner_id'] ?? null) !== null && $delta['credits'] > 0) {
            $this->deductRunCredits((string) $budget['owner_id'], $delta['credits'], $limits);
        }

        $current = array_merge([
            'tokens' => 0,
            'cost' => 0.0,
            'credits' => 0.0,
            'requests' => 0,
        ], $budget['usage'] ?? []);

        $budget['limits'] = $limits;
        $budget['usage'] = [
            'tokens' => (int) $current['tokens'] + $delta['tokens'],
            'cost' => round((float) $current['cost'] + $delta['cost'], 8),
            'credits' => round((float) $current['credits'] + $delta['credits'], 8),
            'requests' => (int) $current['requests'] + 1,
        ];
        $budget['last_usage'] = $delta['raw'];
        $budget['updated_at'] = now()->toISOString();

        $metadata['budget'] = $budget;
        $metadata['tokens_used'] = $budget['usage']['tokens'];
        $metadata['cost_used'] = $budget['usage']['cost'];
        $metadata['credits_used'] = $budget['usage']['credits'];

        if (($delta['provider_model'] ?? null) !== null) {
            $metadata['provider_model'] = $delta['provider_model'];
        }

        return $this->runRepository()->update($run, ['metadata' => $metadata]);
    }

    /**
     * Return remaining run budget for tokens, cost, credits, and owner balance.
     */
    public function remainingRunBudget(int|string|AIAgentRun $runId): array
    {
        $run = $this->resolveRun($runId);
        $budget = ($run->metadata ?? [])['budget'] ?? [];
        $limits = $this->normalizeRunBudgetLimits($budget['limits'] ?? []);
        $usage = array_merge([
            'tokens' => 0,
            'cost' => 0.0,
            'credits' => 0.0,
            'requests' => 0,
        ], $budget['usage'] ?? []);

        $remaining = [
            'tokens' => array_key_exists('max_tokens', $limits) ? (int) $limits['max_tokens'] - (int) $usage['tokens'] : null,
            'cost' => array_key_exists('max_cost', $limits) ? round((float) $limits['max_cost'] - (float) $usage['cost'], 8) : null,
            'credits' => array_key_exists('max_credits', $limits) ? round((float) $limits['max_credits'] - (float) $usage['credits'], 8) : null,
        ];

        $owner = ['id' => $budget['owner_id'] ?? null, 'available_credits' => null, 'is_unlimited' => false];
        if (($budget['owner_id'] ?? null) !== null) {
            $ownerCredits = $this->ownerCreditSnapshot((string) $budget['owner_id'], $limits);
            $owner['available_credits'] = $ownerCredits['balance'];
            $owner['is_unlimited'] = $ownerCredits['is_unlimited'];

            if ($remaining['credits'] === null) {
                $remaining['credits'] = $owner['is_unlimited'] ? null : $owner['available_credits'];
            } elseif (!$owner['is_unlimited']) {
                $remaining['credits'] = min((float) $remaining['credits'], (float) $owner['available_credits']);
            }
        }

        return [
            'run_id' => $run->uuid,
            'owner' => $owner,
            'limits' => $limits,
            'usage' => $usage,
            'remaining' => $remaining,
        ];
    }

    /**
     * Throw when the tracked run budget has already been exhausted.
     */
    public function assertRunBudgetAvailable(int|string|AIAgentRun $runId): bool
    {
        $summary = $this->remainingRunBudget($runId);

        foreach (['tokens' => 'token', 'cost' => 'cost', 'credits' => 'credit'] as $key => $label) {
            $remaining = $summary['remaining'][$key] ?? null;
            if ($remaining !== null && (float) $remaining < 0) {
                $limitKey = 'max_' . $key;
                throw new \RuntimeException("Agent run exceeded {$label} budget [{$summary['limits'][$limitKey]}].");
            }
        }

        if (($summary['owner']['available_credits'] ?? null) !== null
            && ($summary['owner']['is_unlimited'] ?? false) === false
            && (float) $summary['owner']['available_credits'] < 0
        ) {
            throw new \RuntimeException('Agent run credit owner has no available credits.');
        }

        return true;
    }

    /**
     * Reset user credits to default
     */
    public function resetCredits(string $userId): bool
    {
        $user = $this->getUserModel($userId);

        if ($this->usesEntityCreditLedger($user)) {
            $ledger = $this->getEntityCreditLedger($user);
            $defaultBalance = (float) config('ai-engine.credits.default_balance', 100.0);

            foreach ($ledger as $engine => $engineCredits) {
                if (!is_array($engineCredits)) {
                    continue;
                }

                foreach ($engineCredits as $model => $modelCredits) {
                    if (!is_array($modelCredits)) {
                        continue;
                    }
                    $ledger[$engine][$model]['balance'] = $defaultBalance;
                    $ledger[$engine][$model]['is_unlimited'] = false;
                }
            }

            $user->entity_credits = $ledger;
            return $user->save();
        }

        $user->my_credits = config('ai-engine.credits.default_balance', 100.0);
        $user->has_unlimited_credits = false;
        return $user->save();
    }

    /**
     * Get input count based on content type
     */
    private function getInputCount(AIRequest $request): float
    {
        return match ($request->model->calculationMethod()) {
            'words' => $this->countWords($request->prompt),
            'characters' => $this->countCharacters($request->prompt),
            'images' => $this->firstNumericParameter($request, ['image_count', 'num_images', 'frame_count'], 1),
            'videos' => $this->firstNumericParameter($request, ['video_count', 'num_videos'], 1),
            'minutes' => $this->firstNumericParameter($request, ['audio_minutes', 'duration_minutes'], 1),
            default => 1,
        };
    }

    private function firstNumericParameter(AIRequest $request, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $request->parameters)) {
                continue;
            }

            $value = $request->parameters[$key];
            if (is_numeric($value)) {
                return max(0.0, (float) $value);
            }
        }

        return $default;
    }

    private function getAdditionalInputUnitEngineCredits(AIRequest $request): float
    {
        $engine = $request->engine->value;
        $model = $request->model->value;
        $policy = config("ai-engine.credits.additional_input_unit_rates.{$engine}", []);

        if (!is_array($policy) || $policy === []) {
            return 0.0;
        }

        $rates = array_replace(
            $policy['default'] ?? [],
            $policy['models'][$model] ?? []
        );

        if (!is_array($rates) || $rates === []) {
            return 0.0;
        }

        $engineCredits = 0.0;

        foreach ($rates as $unit => $rate) {
            if (!is_numeric($rate) || (float) $rate <= 0) {
                continue;
            }

            $engineCredits += $this->countAdditionalInputUnits($request, (string) $unit) * (float) $rate;
        }

        return $engineCredits;
    }

    private function countAdditionalInputUnits(AIRequest $request, string $unit): float
    {
        return match ($unit) {
            'image', 'images' => $this->countInputMediaUnits($request->parameters, [
                'image_url',
                'image_urls',
                'input_image',
                'input_images',
                'reference_image',
                'reference_images',
                'reference_image_url',
                'reference_image_urls',
                'start_image',
                'start_image_url',
                'end_image',
                'end_image_url',
                'mask_image',
                'mask_image_url',
                'init_image',
                'init_images',
                'source_image',
                'source_images',
            ]),
            default => 0.0,
        };
    }

    private function countInputMediaUnits(array $parameters, array $keys): float
    {
        $count = 0.0;

        foreach ($keys as $key) {
            if (!array_key_exists($key, $parameters)) {
                continue;
            }

            $count += $this->countMediaValue($parameters[$key]);
        }

        return $count;
    }

    private function countMediaValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_string($value)) {
            return 1.0;
        }

        if (!is_array($value)) {
            return 0.0;
        }

        if (array_is_list($value)) {
            return array_sum(array_map(fn (mixed $item): float => $this->countMediaValue($item), $value));
        }

        foreach (['url', 'image_url', 'path', 'file_id'] as $mediaKey) {
            if (isset($value[$mediaKey]) && $value[$mediaKey] !== '') {
                return 1.0;
            }
        }

        return array_sum(array_map(fn (mixed $item): float => $this->countMediaValue($item), $value));
    }

    /**
     * Count words in text
     */
    private function countWords(string $text): int
    {
        return str_word_count(strip_tags($text));
    }

    /**
     * Count characters in text
     */
    private function countCharacters(string $text): int
    {
        return mb_strlen(strip_tags($text));
    }

    private function resolveRun(int|string|AIAgentRun $runId): AIAgentRun
    {
        return $runId instanceof AIAgentRun
            ? $runId->refresh()
            : $this->runRepository()->findOrFail($runId);
    }

    private function runRepository(): AgentRunRepository
    {
        return app(AgentRunRepository::class);
    }

    private function creditReservationRepository(): CreditReservationRepository
    {
        return app(CreditReservationRepository::class);
    }

    private function creditReservationTableAvailable(): bool
    {
        return Schema::hasTable('ai_credit_reservations');
    }

    private function normalizeRunBudgetLimits(array $limits): array
    {
        foreach (['engine', 'model'] as $key) {
            if (($limits[$key] ?? null) instanceof EngineEnum || ($limits[$key] ?? null) instanceof EntityEnum) {
                $limits[$key] = $limits[$key]->value;
            }
        }

        foreach (['max_tokens'] as $key) {
            if (array_key_exists($key, $limits) && $limits[$key] !== null) {
                $limits[$key] = (int) $limits[$key];
            }
        }

        foreach (['max_cost', 'max_credits'] as $key) {
            if (array_key_exists($key, $limits) && $limits[$key] !== null) {
                $limits[$key] = (float) $limits[$key];
            }
        }

        if (array_key_exists('deduct_credits', $limits)) {
            $limits['deduct_credits'] = (bool) $limits['deduct_credits'];
        }

        return $limits;
    }

    /**
     * @return array{tokens:int,cost:float,credits:float,provider_model:?string,raw:array}
     */
    private function normalizeRunUsage(AIResponse|array $usage): array
    {
        $raw = $usage instanceof AIResponse ? array_merge($usage->getUsage() ?? [], [
            'tokens_used' => $usage->getTokensUsed(),
            'credits_used' => $usage->getCreditsUsed(),
            'provider_model' => $usage->getModel()->value,
        ]) : $usage;

        $tokens = $raw['tokens_used']
            ?? $raw['total_tokens']
            ?? $raw['tokens']
            ?? ((int) ($raw['input_tokens'] ?? 0) + (int) ($raw['output_tokens'] ?? 0));

        $cost = $raw['cost_used']
            ?? $raw['total_cost']
            ?? $raw['cost']
            ?? $raw['amount']
            ?? 0.0;

        $credits = $raw['credits_used']
            ?? $raw['credits']
            ?? $raw['total_credits']
            ?? $cost;

        return [
            'tokens' => (int) $tokens,
            'cost' => (float) $cost,
            'credits' => (float) $credits,
            'provider_model' => isset($raw['provider_model']) ? (string) $raw['provider_model'] : null,
            'raw' => $raw,
        ];
    }

    private function deductRunCredits(string $ownerId, float $credits, array $limits): void
    {
        if (($limits['engine'] ?? null) !== null && ($limits['model'] ?? null) !== null) {
            $request = new AIRequest(
                prompt: '',
                engine: (string) $limits['engine'],
                model: (string) $limits['model'],
                userId: $ownerId,
                metadata: ['source' => 'agent_run_budget']
            );

            $this->deductCredits($ownerId, $request, $credits);
            return;
        }

        $user = $this->getUserModel($ownerId);
        $handler = $this->getLifecycleHandler();
        if ($handler) {
            $request = new AIRequest(prompt: '', userId: $ownerId, metadata: ['source' => 'agent_run_budget']);
            $handler->deductCredits($user, $request, $credits);
            return;
        }

        if ($this->usesEntityCreditLedger($user)) {
            throw new \InvalidArgumentException('Run budget credit deduction requires engine and model when using entity credit ledgers.');
        }

        if (!$this->supportsScalarCreditFields($user) || $this->readUnlimitedCreditsFlag($user)) {
            return;
        }

        $currentBalance = $this->readScalarCreditBalance($user);
        if ($currentBalance < $credits) {
            throw new InsufficientCreditsException(
                "Insufficient MyCredits. Required: {$credits}, Available: {$currentBalance}"
            );
        }

        $this->writeScalarCreditState($user, $currentBalance - $credits, false);
    }

    /**
     * @return array{balance:float,is_unlimited:bool}
     */
    private function ownerCreditSnapshot(string $ownerId, array $limits): array
    {
        if (($limits['engine'] ?? null) !== null && ($limits['model'] ?? null) !== null) {
            $credits = $this->getUserCredits($ownerId, (string) $limits['engine'], (string) $limits['model']);

            return [
                'balance' => (float) $credits['balance'],
                'is_unlimited' => (bool) ($credits['is_unlimited'] ?? false),
            ];
        }

        $total = $this->getTotalCredits($ownerId);

        return [
            'balance' => $total,
            'is_unlimited' => $total === PHP_FLOAT_MAX,
        ];
    }

    private function resolveEngine(mixed $engine): EngineEnum
    {
        if ($engine instanceof EngineEnum) {
            return $engine;
        }

        if (!is_string($engine) || $engine === '') {
            throw new \InvalidArgumentException('Invalid engine value provided.');
        }

        return EngineEnum::fromSlug($engine);
    }

    private function resolveModel(mixed $model): EntityEnum
    {
        if ($model instanceof EntityEnum) {
            return $model;
        }

        if (!is_string($model) || $model === '') {
            throw new \InvalidArgumentException('Invalid model value provided.');
        }

        return EntityEnum::from($model);
    }

    private function usesEntityCreditLedger(Model $user): bool
    {
        $attributes = $user->getAttributes();
        if (array_key_exists('entity_credits', $attributes)) {
            return true;
        }

        if (isset($user->entity_credits) && $user->entity_credits !== null) {
            return true;
        }

        return $this->modelHasColumn($user, 'entity_credits');
    }

    private function supportsScalarCreditFields(Model $user): bool
    {
        return $this->modelHasColumn($user, 'my_credits')
            || $this->modelHasColumn($user, 'has_unlimited_credits');
    }

    private function readScalarCreditBalance(Model $user): float
    {
        $balance = $user->getAttribute('my_credits');
        if ($balance === null || $balance === '') {
            return (float) config('ai-engine.credits.default_balance', 100.0);
        }

        return (float) $balance;
    }

    private function readUnlimitedCreditsFlag(Model $user): bool
    {
        if (!$this->modelHasColumn($user, 'has_unlimited_credits')) {
            return false;
        }

        return (bool) $user->getAttribute('has_unlimited_credits');
    }

    private function writeScalarCreditState(Model $user, float $balance, bool $isUnlimited): bool
    {
        if ($this->modelHasColumn($user, 'my_credits')) {
            $user->setAttribute('my_credits', $balance);
        }

        if ($this->modelHasColumn($user, 'has_unlimited_credits')) {
            $user->setAttribute('has_unlimited_credits', $isUnlimited);
        }

        return $user->save();
    }

    private function modelHasColumn(Model $user, string $column): bool
    {
        $connectionName = $user->getConnectionName() ?: config('database.default');
        $cacheKey = implode(':', [$connectionName, $user->getTable(), $column]);

        if (array_key_exists($cacheKey, static::$columnSupportCache)) {
            return static::$columnSupportCache[$cacheKey];
        }

        try {
            return static::$columnSupportCache[$cacheKey] = Schema::connection($connectionName)
                ->hasColumn($user->getTable(), $column);
        } catch (\Throwable) {
            return static::$columnSupportCache[$cacheKey] = false;
        }
    }

    private function getEntityCreditLedger(Model $user): array
    {
        $ledger = $user->entity_credits ?? [];
        if (is_string($ledger)) {
            $decoded = json_decode($ledger, true);
            $ledger = is_array($decoded) ? $decoded : [];
        }

        return is_array($ledger) ? $ledger : [];
    }

    private function getEntityCreditEntry(Model $user, EngineEnum $engine, EntityEnum $model): array
    {
        $ledger = $this->getEntityCreditLedger($user);
        $defaultBalance = (float) config('ai-engine.credits.default_balance', 100.0);

        return $ledger[$engine->value][$model->value] ?? [
            'balance' => $defaultBalance,
            'is_unlimited' => false,
        ];
    }

    private function setEntityCreditEntry(
        Model $user,
        EngineEnum $engine,
        EntityEnum $model,
        float $balance,
        bool $isUnlimited
    ): void {
        $ledger = $this->getEntityCreditLedger($user);
        $ledger[$engine->value][$model->value] = [
            'balance' => $balance,
            'is_unlimited' => $isUnlimited,
        ];
        $user->entity_credits = $ledger;
        $user->save();
    }


    /**
     * Get credit owner model (User, Tenant, Workspace, etc.)
     * 
     * @param string $ownerId The ID of the credit owner (user_id, tenant_id, workspace_id, etc.)
     * @return Model
     */
    private function getUserModel(string $ownerId): Model
    {
        // Check for custom query resolver first
        $queryResolverClass = $this->getQueryResolverClass();
        
        if ($queryResolverClass && class_exists($queryResolverClass)) {
            try {
                $resolver = app($queryResolverClass);
                
                // Support callable resolvers (with __invoke method)
                if (is_callable($resolver)) {
                    $model = $resolver($ownerId);
                    if ($model instanceof Model) {
                        return $model;
                    }
                }
                
                // Support resolvers with resolve() method
                if (method_exists($resolver, 'resolve')) {
                    $model = $resolver->resolve($ownerId);
                    if ($model instanceof Model) {
                        return $model;
                    }
                }
                
                // Support resolvers with query() method that returns a query builder
                if (method_exists($resolver, 'query')) {
                    $query = $resolver->query($ownerId);
                    if ($query) {
                        return $query->firstOrFail();
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to resolve owner model with custom query resolver', [
                    'resolver' => $queryResolverClass,
                    'owner_id' => $ownerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Fallback to configured owner model (User, Tenant, Workspace, etc.)
        $ownerModel = config('ai-engine.credits.owner_model');
        if (!is_string($ownerModel) || $ownerModel === '' || !class_exists($ownerModel)) {
            $ownerModel = config('ai-engine.user_model');
        }

        if (!is_string($ownerModel) || $ownerModel === '' || !class_exists($ownerModel)) {
            throw new \RuntimeException(
                'Credit owner model is not configured. Set ai-engine.credits.owner_model or ai-engine.user_model.'
            );
        }
        
        // Get the ID column name from config (e.g., 'id', 'tenant_id', 'workspace_id')
        $ownerIdColumn = config('ai-engine.credits.owner_id_column', 'id');
        
        return $ownerModel::where($ownerIdColumn, $ownerId)->firstOrFail();
    }

}
