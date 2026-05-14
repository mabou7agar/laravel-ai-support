<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Billing;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\CreditManager;

class PricingInspectionService
{
    public function __construct(
        private readonly CreditManager $credits
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $rates = [];
        $warnings = [];

        foreach ((array) config('ai-engine.credits.engine_rates', []) as $engine => $rate) {
            $numericRate = is_numeric($rate) ? (float) $rate : 0.0;
            $flags = $this->flagsForRate($numericRate);

            foreach ($flags as $flag) {
                if (in_array($flag, ['free_or_disabled_rate', 'discounted_provider_rate'], true)) {
                    $warnings[] = sprintf('%s has %s [%s].', $engine, str_replace('_', ' ', $flag), $numericRate);
                }
            }

            $rates[(string) $engine] = [
                'rate' => $numericRate,
                'flags' => $flags,
                'env' => $this->envKeyForRate((string) $engine),
            ];
        }

        return [
            'currency' => config('ai-engine.credits.currency', 'MyCredits'),
            'enabled' => (bool) config('ai-engine.credits.enabled', false),
            'engine_rates' => $rates,
            'additional_input_unit_rates' => (array) config('ai-engine.credits.additional_input_unit_rates', []),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function simulate(
        EngineEnum|string $engine,
        EntityEnum|string $model,
        string $prompt,
        array $parameters = [],
        ?string $userId = null
    ): array {
        $request = new AIRequest(
            prompt: $prompt,
            engine: $engine,
            model: $model,
            parameters: $parameters,
            userId: $userId
        );

        return array_merge($this->credits->calculateCreditBreakdown($request), [
            'prompt_preview' => mb_substr($prompt, 0, 120),
            'parameters' => $parameters,
            'has_user_id' => $userId !== null && $userId !== '',
        ]);
    }

    /**
     * @return list<string>
     */
    private function flagsForRate(float $rate): array
    {
        if ($rate <= 0.0) {
            return ['free_or_disabled_rate'];
        }

        if ($rate < 1.0) {
            return ['discounted_provider_rate'];
        }

        if ($rate > 1.0) {
            return ['configured_provider_margin'];
        }

        return ['pass_through_provider_rate'];
    }

    private function envKeyForRate(string $engine): string
    {
        return 'AI_'.strtoupper($engine).'_RATE';
    }
}
