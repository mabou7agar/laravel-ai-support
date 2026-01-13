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
    public function __construct(
        private Application $app
    ) {}

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
     * Check if user has sufficient MyCredits
     */
    public function hasCredits(string $userId, AIRequest $request): bool
    {
        $user = $this->getUserModel($userId);
        
        // Check unlimited first
        if ($user->has_unlimited_credits) {
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
        
        // Don't deduct if unlimited
        if ($user->has_unlimited_credits) {
            return true;
        }
        
        $creditsToDeduct = $actualCreditsUsed ?? $this->calculateCredits($request);
        
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
    public function addCredits(string $userId, float $credits): bool
    {
        $user = $this->getUserModel($userId);
        $user->my_credits += $credits;
        return $user->save();
    }

    /**
     * Set user MyCredits balance
     */
    public function setCredits(string $userId, float $credits): bool
    {
        $user = $this->getUserModel($userId);
        $user->my_credits = $credits;
        return $user->save();
    }

    /**
     * Set unlimited credits for user
     */
    public function setUnlimitedCredits(string $userId, bool $unlimited = true): bool
    {
        $user = $this->getUserModel($userId);
        $user->has_unlimited_credits = $unlimited;
        return $user->save();
    }

    /**
     * Get user MyCredits balance
     */
    public function getUserCredits(string $userId): array
    {
        $user = $this->getUserModel($userId);
        
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
     * Get user model
     */
    private function getUserModel(string $userId): Model
    {
        $userModel = config('ai-engine.user_model', 'App\\Models\\User');
        return $userModel::findOrFail($userId);
    }

}
