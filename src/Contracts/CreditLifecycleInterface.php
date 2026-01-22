<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\DTOs\AIRequest;

interface CreditLifecycleInterface
{
    /**
     * Check if user has sufficient credits
     * 
     * @param Model $owner The credit owner model
     * @param AIRequest $request The AI request
     * @return bool
     */
    public function hasCredits(Model $owner, AIRequest $request): bool;

    /**
     * Deduct credits from owner
     * 
     * @param Model $owner The credit owner model
     * @param AIRequest $request The AI request
     * @param float $creditsToDeduct Amount of credits to deduct
     * @return bool
     */
    public function deductCredits(Model $owner, AIRequest $request, float $creditsToDeduct): bool;

    /**
     * Add credits to owner
     * 
     * @param Model $owner The credit owner model
     * @param float $credits Amount of credits to add
     * @param array $metadata Additional metadata (e.g., expiration_date, source, etc.)
     * @return bool
     */
    public function addCredits(Model $owner, float $credits, array $metadata = []): bool;

    /**
     * Get available credits for owner
     * 
     * @param Model $owner The credit owner model
     * @return float
     */
    public function getAvailableCredits(Model $owner): float;

    /**
     * Check if owner has low credits
     * 
     * @param Model $owner The credit owner model
     * @return bool
     */
    public function hasLowCredits(Model $owner): bool;
}
