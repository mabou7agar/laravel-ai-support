<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class ProviderCostCreditQuote
{
    public function __construct(
        public float $estimatedCredits,
        public ?float $providerCostUsd,
        public ?float $providerCostAfterFundingFeeUsd,
        public ?float $minimumRetailUsd,
        public ?float $providerCostCredits,
        public float $billableCredits,
        public bool $usedProviderCost,
    ) {}

    /**
     * @return array<string, bool|float|null>
     */
    public function toArray(): array
    {
        return [
            'estimated_credits' => $this->estimatedCredits,
            'provider_cost_usd' => $this->providerCostUsd,
            'provider_cost_after_funding_fee_usd' => $this->providerCostAfterFundingFeeUsd,
            'minimum_retail_usd' => $this->minimumRetailUsd,
            'provider_cost_credits' => $this->providerCostCredits,
            'billable_credits' => $this->billableCredits,
            'used_provider_cost' => $this->usedProviderCost,
        ];
    }
}
