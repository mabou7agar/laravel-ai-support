<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;

class CreditManager
{
    protected static ?string $globalQueryResolver = null;
    protected static ?string $globalLifecycleHandler = null;
    
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
        $inputCount = $this->getInputCount($request);
        $creditIndex = $request->model->creditIndex();
        $engineRate = $this->getEngineRate($request->engine);

        // Calculate engine credits then convert to MyCredits
        $engineCredits = $inputCount * $creditIndex;
        return $engineCredits * $engineRate;
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
        $user = $this->getUserModel($userId);

        if ($this->usesEntityCreditLedger($user)) {
            $entry = $this->getEntityCreditEntry($user, $request->engine, $request->model);
            if (($entry['is_unlimited'] ?? false) === true) {
                return true;
            }

            $requiredCredits = $this->calculateCredits($request);
            return ((float) ($entry['balance'] ?? 0.0)) >= $requiredCredits;
        }
        
        // Use custom lifecycle handler if configured
        $handler = $this->getLifecycleHandler();
        if ($handler) {
            return $handler->hasCredits($user, $request);
        }
        
        // Default behavior
        // Check unlimited first
        if (isset($user->has_unlimited_credits) && $user->has_unlimited_credits) {
            return true;
        }
        
        $requiredCredits = $this->calculateCredits($request);
        return $user->my_credits >= $requiredCredits;
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
        
        // Default behavior
        // Don't deduct if unlimited
        if ($user->has_unlimited_credits) {
            return true;
        }
        
        if ($user->my_credits < $creditsToDeduct) {
            throw new InsufficientCreditsException(
                "Insufficient MyCredits. Required: {$creditsToDeduct}, Available: {$user->my_credits}"
            );
        }

        $user->my_credits -= $creditsToDeduct;
        return $user->save();
    }

    /**
     * Add MyCredits to user
     */
    public function addCredits(string $userId, mixed ...$args): bool
    {
        $user = $this->getUserModel($userId);
        [$engine, $model, $credits, $metadata] = $this->parseCreditMutationArguments($args);

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
    public function setCredits(string $userId, mixed ...$args): bool
    {
        $user = $this->getUserModel($userId);
        [$engine, $model, $credits] = $this->parseCreditSetArguments($args);

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
    public function setUnlimitedCredits(string $userId, mixed ...$args): bool
    {
        $user = $this->getUserModel($userId);

        [$engine, $model, $unlimited] = $this->parseUnlimitedArguments($args);
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
        
        if ($user->has_unlimited_credits) {
            return PHP_FLOAT_MAX;
        }
        
        return $user->my_credits ?? 0;
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
            'images' => $request->parameters['image_count'] ?? 1,
            'videos' => $request->parameters['video_count'] ?? 1,
            'minutes' => $request->parameters['audio_minutes'] ?? 1,
            default => 1,
        };
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

    /**
     * Parse addCredits args supporting legacy (engine, model, credits) and modern (credits, metadata) signatures.
     *
     * @return array{0:?EngineEnum,1:?EntityEnum,2:float,3:array}
     */
    private function parseCreditMutationArguments(array $args): array
    {
        if ($args === []) {
            throw new \InvalidArgumentException('addCredits requires at least one argument.');
        }

        if (is_numeric($args[0])) {
            $credits = (float) $args[0];
            $metadata = (isset($args[1]) && is_array($args[1])) ? $args[1] : [];
            return [null, null, $credits, $metadata];
        }

        $engine = $this->resolveEngine($args[0] ?? null);
        $model = $this->resolveModel($args[1] ?? null);
        $credits = (float) ($args[2] ?? 0);
        $metadata = (isset($args[3]) && is_array($args[3])) ? $args[3] : [];

        return [$engine, $model, $credits, $metadata];
    }

    /**
     * Parse setCredits args supporting legacy (engine, model, credits) and modern (credits) signatures.
     *
     * @return array{0:?EngineEnum,1:?EntityEnum,2:float}
     */
    private function parseCreditSetArguments(array $args): array
    {
        if ($args === []) {
            throw new \InvalidArgumentException('setCredits requires at least one argument.');
        }

        if (is_numeric($args[0])) {
            return [null, null, (float) $args[0]];
        }

        $engine = $this->resolveEngine($args[0] ?? null);
        $model = $this->resolveModel($args[1] ?? null);
        $credits = (float) ($args[2] ?? 0);

        return [$engine, $model, $credits];
    }

    /**
     * Parse setUnlimitedCredits args supporting legacy and modern signatures.
     *
     * @return array{0:?EngineEnum,1:?EntityEnum,2:bool}
     */
    private function parseUnlimitedArguments(array $args): array
    {
        if ($args === []) {
            return [null, null, true];
        }

        if (is_bool($args[0])) {
            return [null, null, (bool) $args[0]];
        }

        $engine = $this->resolveEngine($args[0] ?? null);
        $model = $this->resolveModel($args[1] ?? null);

        if (isset($args[2]) && is_bool($args[2])) {
            return [$engine, $model, (bool) $args[2]];
        }

        if (isset($args[3]) && is_bool($args[3])) {
            return [$engine, $model, (bool) $args[3]];
        }

        return [$engine, $model, true];
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

        return isset($user->entity_credits) && $user->entity_credits !== null;
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
