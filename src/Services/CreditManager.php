<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\Exceptions\InsufficientCreditsException;

class CreditManager
{
    public function __construct(
        private Application $app
    ) {}

    /**
     * Calculate required credits for a request
     */
    public function calculateCredits(AIRequest $request): float
    {
        $inputCount = $this->getInputCount($request);
        $creditIndex = $request->model->creditIndex();

        return $inputCount * $creditIndex;
    }

    /**
     * Check if user has sufficient credits
     */
    public function hasCredits(string $userId, AIRequest $request): bool
    {
        $requiredCredits = $this->calculateCredits($request);
        $userCredits = $this->getUserCredits($userId, $request->engine, $request->model);

        return $userCredits['is_unlimited'] || $userCredits['balance'] >= $requiredCredits;
    }

    /**
     * Deduct credits from user
     */
    public function deductCredits(string $userId, AIRequest $request, float $actualCreditsUsed = null): bool
    {
        $creditsToDeduct = $actualCreditsUsed ?? $this->calculateCredits($request);
        
        if (!$this->hasCredits($userId, $request)) {
            throw new InsufficientCreditsException(
                "Insufficient credits. Required: {$creditsToDeduct}, Available: " . 
                $this->getUserCredits($userId, $request->engine, $request->model)['balance']
            );
        }

        return $this->updateUserCredits($userId, $request->engine, $request->model, -$creditsToDeduct);
    }

    /**
     * Add credits to user
     */
    public function addCredits(string $userId, EngineEnum $engine, EntityEnum $model, float $credits): bool
    {
        return $this->updateUserCredits($userId, $engine, $model, $credits);
    }

    /**
     * Set user credits
     */
    public function setCredits(string $userId, EngineEnum $engine, EntityEnum $model, float $credits): bool
    {
        $user = $this->getUserModel($userId);
        $entityCredits = $user->entity_credits ?? $this->getDefaultCredits();
        
        // Handle JSON string conversion
        if (is_string($entityCredits)) {
            $entityCredits = json_decode($entityCredits, true) ?? $this->getDefaultCredits();
        }

        $entityCredits[$engine->value][$model->value] = [
            'balance' => $credits,
            'is_unlimited' => false,
        ];

        return $user->update(['entity_credits' => json_encode($entityCredits)]);
    }

    /**
     * Set unlimited credits for user
     */
    public function setUnlimitedCredits(string $userId, EngineEnum $engine, EntityEnum $model): bool
    {
        $user = $this->getUserModel($userId);
        $entityCredits = $user->entity_credits ?? $this->getDefaultCredits();
        
        // Handle JSON string conversion
        if (is_string($entityCredits)) {
            $entityCredits = json_decode($entityCredits, true) ?? $this->getDefaultCredits();
        }

        $entityCredits[$engine->value][$model->value] = [
            'balance' => 0,
            'is_unlimited' => true,
        ];

        return $user->update(['entity_credits' => json_encode($entityCredits)]);
    }

    /**
     * Get user credits for specific engine and model
     */
    public function getUserCredits(string $userId, EngineEnum $engine, EntityEnum $model): array
    {
        $user = $this->getUserModel($userId);
        $entityCredits = $user->entity_credits ?? $this->getDefaultCredits();
        
        // Handle JSON string conversion
        if (is_string($entityCredits)) {
            $entityCredits = json_decode($entityCredits, true) ?? $this->getDefaultCredits();
        }

        return $entityCredits[$engine->value][$model->value] ?? [
            'balance' => config('ai-engine.credits.default_balance', 100.0),
            'is_unlimited' => false,
        ];
    }

    /**
     * Get all user credits
     */
    public function getAllUserCredits(string $userId): array
    {
        $user = $this->getUserModel($userId);
        $credits = $user->entity_credits ?? $this->getDefaultCredits();
        
        // Handle JSON string conversion
        if (is_string($credits)) {
            $credits = json_decode($credits, true) ?? $this->getDefaultCredits();
        }
        
        return is_array($credits) ? $credits : $this->getDefaultCredits();
    }

    /**
     * Get total credits across all engines/models
     */
    public function getTotalCredits(string $userId): float
    {
        $allCredits = $this->getAllUserCredits($userId);
        $total = 0.0;

        foreach ($allCredits as $engineCredits) {
            foreach ($engineCredits as $modelCredits) {
                if ($modelCredits['is_unlimited']) {
                    return PHP_FLOAT_MAX; // Unlimited
                }
                $total += $modelCredits['balance'];
            }
        }

        return $total;
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
        return $user->update(['entity_credits' => $this->getDefaultCredits()]);
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
     * Update user credits
     */
    private function updateUserCredits(string $userId, EngineEnum $engine, EntityEnum $model, float $creditChange): bool
    {
        $user = $this->getUserModel($userId);
        $entityCredits = $user->entity_credits ?? $this->getDefaultCredits();
        
        // Handle JSON string conversion
        if (is_string($entityCredits)) {
            $entityCredits = json_decode($entityCredits, true) ?? $this->getDefaultCredits();
        }

        $currentCredits = $entityCredits[$engine->value][$model->value] ?? [
            'balance' => config('ai-engine.credits.default_balance', 100.0),
            'is_unlimited' => false,
        ];

        // Don't modify unlimited credits
        if ($currentCredits['is_unlimited']) {
            return true;
        }

        $newBalance = max(0, $currentCredits['balance'] + $creditChange);

        $entityCredits[$engine->value][$model->value] = [
            'balance' => $newBalance,
            'is_unlimited' => false,
        ];

        return $user->update(['entity_credits' => json_encode($entityCredits)]);
    }

    /**
     * Get user model
     */
    private function getUserModel(string $userId): Model
    {
        $userModel = config('ai-engine.user_model', 'App\\Models\\User');
        return $userModel::findOrFail($userId);
    }

    /**
     * Get default credits structure
     */
    private function getDefaultCredits(): array
    {
        $defaultBalance = config('ai-engine.credits.default_balance', 100.0);
        $credits = [];

        foreach (EngineEnum::cases() as $engine) {
            foreach ($engine->getDefaultModels() as $model) {
                $credits[$engine->value][$model->value] = [
                    'balance' => $defaultBalance,
                    'is_unlimited' => false,
                ];
            }
        }

        return $credits;
    }
}
