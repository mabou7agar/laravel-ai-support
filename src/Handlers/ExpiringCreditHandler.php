<?php

declare(strict_types=1);

namespace LaravelAIEngine\Handlers;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Contracts\CreditLifecycleInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use Illuminate\Support\Facades\DB;

class ExpiringCreditHandler implements CreditLifecycleInterface
{
    /**
     * Check if user has sufficient credits (excluding expired credits)
     */
    public function hasCredits(Model $owner, AIRequest $request): bool
    {
        // Check unlimited first
        if (isset($owner->has_unlimited_credits) && $owner->has_unlimited_credits) {
            return true;
        }

        $availableCredits = $this->getAvailableCredits($owner);
        $requiredCredits = $this->calculateRequiredCredits($request);
        
        return $availableCredits >= $requiredCredits;
    }

    /**
     * Deduct credits from owner (oldest first, excluding expired)
     */
    public function deductCredits(Model $owner, AIRequest $request, float $creditsToDeduct): bool
    {
        // Don't deduct if unlimited
        if (isset($owner->has_unlimited_credits) && $owner->has_unlimited_credits) {
            return true;
        }

        // Get available credits
        $availableCredits = $this->getAvailableCredits($owner);
        
        if ($availableCredits < $creditsToDeduct) {
            throw new InsufficientCreditsException(
                "Insufficient credits. Required: {$creditsToDeduct}, Available: {$availableCredits}"
            );
        }

        // Deduct from credit packages (FIFO - oldest first)
        $remaining = $creditsToDeduct;
        
        $packages = DB::table('credit_packages')
            ->where('owner_type', get_class($owner))
            ->where('owner_id', $owner->id)
            ->where('balance', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'asc') // FIFO
            ->get();

        foreach ($packages as $package) {
            if ($remaining <= 0) {
                break;
            }

            $deductFromPackage = min($remaining, $package->balance);
            
            DB::table('credit_packages')
                ->where('id', $package->id)
                ->decrement('balance', $deductFromPackage);

            // Log the transaction
            DB::table('credit_transactions')->insert([
                'owner_type' => get_class($owner),
                'owner_id' => $owner->id,
                'package_id' => $package->id,
                'amount' => -$deductFromPackage,
                'type' => 'deduction',
                'description' => 'AI request credit deduction',
                'metadata' => json_encode([
                    'engine' => $request->engine->value,
                    'model' => $request->model->value,
                ]),
                'created_at' => now(),
            ]);

            $remaining -= $deductFromPackage;
        }

        // Update owner's total credits cache
        $owner->my_credits = $this->getAvailableCredits($owner);
        $owner->save();

        return true;
    }

    /**
     * Add credits to owner with expiration date
     */
    public function addCredits(Model $owner, float $credits, array $metadata = []): bool
    {
        $expiresAt = $metadata['expires_at'] ?? null;
        $source = $metadata['source'] ?? 'manual';
        $description = $metadata['description'] ?? 'Credit addition';

        // Create new credit package
        $packageId = DB::table('credit_packages')->insertGetId([
            'owner_type' => get_class($owner),
            'owner_id' => $owner->id,
            'amount' => $credits,
            'balance' => $credits,
            'expires_at' => $expiresAt,
            'source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Log the transaction
        DB::table('credit_transactions')->insert([
            'owner_type' => get_class($owner),
            'owner_id' => $owner->id,
            'package_id' => $packageId,
            'amount' => $credits,
            'type' => 'addition',
            'description' => $description,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
        ]);

        // Update owner's total credits cache
        $owner->my_credits = $this->getAvailableCredits($owner);
        $owner->save();

        return true;
    }

    /**
     * Get available credits (excluding expired)
     */
    public function getAvailableCredits(Model $owner): float
    {
        // Clean up expired credits first
        $this->cleanupExpiredCredits($owner);

        return (float) DB::table('credit_packages')
            ->where('owner_type', get_class($owner))
            ->where('owner_id', $owner->id)
            ->where('balance', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->sum('balance');
    }

    /**
     * Check if owner has low credits
     */
    public function hasLowCredits(Model $owner): bool
    {
        $threshold = config('ai-engine.credits.low_balance_threshold', 10.0);
        return $this->getAvailableCredits($owner) < $threshold;
    }

    /**
     * Calculate required credits for request
     */
    protected function calculateRequiredCredits(AIRequest $request): float
    {
        // Use CreditManager's calculation logic
        $creditManager = app(\LaravelAIEngine\Services\CreditManager::class);
        return $creditManager->calculateCredits($request);
    }

    /**
     * Clean up expired credit packages
     */
    protected function cleanupExpiredCredits(Model $owner): void
    {
        DB::table('credit_packages')
            ->where('owner_type', get_class($owner))
            ->where('owner_id', $owner->id)
            ->where('balance', '>', 0)
            ->where('expires_at', '<=', now())
            ->update([
                'balance' => 0,
                'expired_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
