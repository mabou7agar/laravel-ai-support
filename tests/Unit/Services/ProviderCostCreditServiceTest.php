<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Facades\Config;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\ProviderCostCreditService;
use LaravelAIEngine\Tests\TestCase;

final class ProviderCostCreditServiceTest extends TestCase
{
    public function test_provider_cost_becomes_a_credit_floor_with_margin_and_funding_fee(): void
    {
        Config::set('ai-engine.credits.retail_pricing', [
            'enabled' => true,
            'usd_per_credit' => 0.001,
            'target_gross_margin_percent' => 25.0,
            'provider_funding_fee_percent' => 5.5,
            'rounding_increment_credits' => 0.01,
        ]);

        $quote = app(ProviderCostCreditService::class)->quote(
            0.24,
            $this->responseWithProviderCost(0.013438)
        );

        self::assertSame(18.91, $quote->billableCredits);
        self::assertSame(18.91, $quote->providerCostCredits);
        self::assertTrue($quote->usedProviderCost);
        self::assertGreaterThanOrEqual(0.013438, $quote->billableCredits * 0.001);
    }

    public function test_existing_estimate_remains_the_floor(): void
    {
        Config::set('ai-engine.credits.retail_pricing', [
            'enabled' => true,
            'usd_per_credit' => 0.001,
            'target_gross_margin_percent' => 25.0,
            'provider_funding_fee_percent' => 5.5,
            'rounding_increment_credits' => 0.01,
        ]);

        $quote = app(ProviderCostCreditService::class)->quote(
            25.0,
            $this->responseWithProviderCost(0.001)
        );

        self::assertSame(25.0, $quote->billableCredits);
        self::assertFalse($quote->usedProviderCost);
    }

    public function test_provider_cost_mode_uses_reported_cost_instead_of_an_inflated_estimate(): void
    {
        Config::set('ai-engine.credits.retail_pricing', [
            'enabled' => true,
            'settlement_mode' => 'provider_cost',
            'usd_per_credit' => 0.002,
            'target_gross_margin_percent' => 25.0,
            'provider_funding_fee_percent' => 5.5,
            'rounding_increment_credits' => 0.01,
        ]);

        $quote = app(ProviderCostCreditService::class)->quote(
            61.08,
            $this->responseWithProviderCost(0.006172)
        );

        self::assertSame(4.35, $quote->billableCredits);
        self::assertSame(4.35, $quote->providerCostCredits);
        self::assertTrue($quote->usedProviderCost);
    }

    public function test_provider_cost_mode_falls_back_to_estimate_when_provider_cost_is_absent(): void
    {
        Config::set('ai-engine.credits.retail_pricing', [
            'enabled' => true,
            'settlement_mode' => 'provider_cost',
        ]);

        $response = AIResponse::success(
            'text',
            EngineEnum::OPENROUTER,
            EntityEnum::GPT_4O,
        );

        $quote = app(ProviderCostCreditService::class)->quote(3.25, $response);

        self::assertSame(3.25, $quote->billableCredits);
        self::assertNull($quote->providerCostCredits);
        self::assertFalse($quote->usedProviderCost);
    }

    public function test_unknown_settlement_mode_preserves_estimate_floor_for_backward_compatibility(): void
    {
        Config::set('ai-engine.credits.retail_pricing', [
            'enabled' => true,
            'settlement_mode' => 'unsupported',
            'usd_per_credit' => 0.001,
            'target_gross_margin_percent' => 0.0,
            'provider_funding_fee_percent' => 0.0,
            'rounding_increment_credits' => 0.01,
        ]);

        $quote = app(ProviderCostCreditService::class)->quote(
            25.0,
            $this->responseWithProviderCost(0.001)
        );

        self::assertSame(25.0, $quote->billableCredits);
        self::assertFalse($quote->usedProviderCost);
    }

    public function test_feature_is_backward_compatible_when_disabled(): void
    {
        Config::set('ai-engine.credits.retail_pricing.enabled', false);

        $quote = app(ProviderCostCreditService::class)->quote(
            0.24,
            $this->responseWithProviderCost(0.013438)
        );

        self::assertSame(0.24, $quote->billableCredits);
        self::assertFalse($quote->usedProviderCost);
    }

    private function responseWithProviderCost(float $providerCostUsd): AIResponse
    {
        return AIResponse::success(
            'image',
            EngineEnum::OPENROUTER,
            EntityEnum::GPT_IMAGE_2,
            ['usage' => ['provider_cost_usd' => $providerCostUsd]]
        );
    }
}
