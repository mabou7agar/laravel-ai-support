<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use InvalidArgumentException;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ProviderCostCreditQuote;

final class ProviderCostCreditService
{
    public function quote(float $estimatedCredits, AIResponse $response): ProviderCostCreditQuote
    {
        $estimatedCredits = max(0.0, $estimatedCredits);
        $providerCostUsd = $response->getProviderCostUsd();

        if (! $this->enabled() || $providerCostUsd === null) {
            return new ProviderCostCreditQuote(
                estimatedCredits: $estimatedCredits,
                providerCostUsd: $providerCostUsd,
                providerCostAfterFundingFeeUsd: null,
                minimumRetailUsd: null,
                providerCostCredits: null,
                billableCredits: $estimatedCredits,
                usedProviderCost: false,
            );
        }

        $usdPerCredit = (float) config('ai-engine.credits.retail_pricing.usd_per_credit', 0.001);
        $targetMargin = (float) config(
            'ai-engine.credits.retail_pricing.target_gross_margin_percent',
            0.0
        );
        $fundingFee = (float) config(
            'ai-engine.credits.retail_pricing.provider_funding_fee_percent',
            0.0
        );
        $roundingIncrement = (float) config(
            'ai-engine.credits.retail_pricing.rounding_increment_credits',
            1.0
        );

        $this->validatePolicy($usdPerCredit, $targetMargin, $fundingFee, $roundingIncrement);

        $loadedProviderCost = $providerCostUsd * (1.0 + ($fundingFee / 100.0));
        $minimumRetail = $loadedProviderCost / (1.0 - ($targetMargin / 100.0));
        $rawProviderCredits = $minimumRetail / $usdPerCredit;
        $providerCostCredits = $this->roundUp($rawProviderCredits, $roundingIncrement);
        $billableCredits = max($estimatedCredits, $providerCostCredits);

        return new ProviderCostCreditQuote(
            estimatedCredits: $estimatedCredits,
            providerCostUsd: $providerCostUsd,
            providerCostAfterFundingFeeUsd: $loadedProviderCost,
            minimumRetailUsd: $minimumRetail,
            providerCostCredits: $providerCostCredits,
            billableCredits: round($billableCredits, 8),
            usedProviderCost: $providerCostCredits > $estimatedCredits,
        );
    }

    private function enabled(): bool
    {
        return (bool) config('ai-engine.credits.retail_pricing.enabled', false);
    }

    private function validatePolicy(
        float $usdPerCredit,
        float $targetMargin,
        float $fundingFee,
        float $roundingIncrement
    ): void {
        if ($usdPerCredit <= 0.0) {
            throw new InvalidArgumentException('AI credit retail price must be greater than zero.');
        }

        if ($targetMargin < 0.0 || $targetMargin >= 100.0) {
            throw new InvalidArgumentException('AI credit target gross margin must be between 0 and 100.');
        }

        if ($fundingFee < 0.0) {
            throw new InvalidArgumentException('AI provider funding fee cannot be negative.');
        }

        if ($roundingIncrement <= 0.0) {
            throw new InvalidArgumentException('AI credit rounding increment must be greater than zero.');
        }
    }

    private function roundUp(float $credits, float $increment): float
    {
        return round(ceil(($credits - PHP_FLOAT_EPSILON) / $increment) * $increment, 8);
    }
}
