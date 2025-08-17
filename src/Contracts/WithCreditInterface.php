<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Contracts;

use MagicAI\LaravelAIEngine\Enums\EntityEnum;

interface WithCreditInterface
{
    /**
     * Get the current credit balance
     */
    public function creditBalance(): float;

    /**
     * Check if there is sufficient credit balance
     */
    public function hasCreditBalance(): bool;

    /**
     * Check if there is sufficient credit balance for a specific input
     */
    public function hasCreditBalanceForInput(): bool;

    /**
     * Set the credit balance
     */
    public function setCredit(float $value = 1.00): bool;

    /**
     * Increase the credit balance
     */
    public function increaseCredit(float $value = 1.00): bool;

    /**
     * Decrease the credit balance
     */
    public function decreaseCredit(float $value = 1.00): bool;

    /**
     * Get the credit index (cost multiplier)
     */
    public function getCreditIndex(): float;

    /**
     * Get the calculated input credit
     */
    public function getCalculatedInputCredit(): float;

    /**
     * Set the calculated input credit
     */
    public function setCalculatedInputCredit(float $value = 0.0): self;

    /**
     * Get the credit enum for this entity
     */
    public function creditEnum(): EntityEnum;

    /**
     * Get the credit information
     */
    public function getCredit(): array;

    /**
     * Set as unlimited credit
     */
    public function setAsUnlimited(bool $unlimited = true): bool;

    /**
     * Check if credit is unlimited
     */
    public function isUnlimitedCredit(): bool;

    /**
     * Calculate the required credits for the current operation
     */
    public function calculateCredit(): self;
}
