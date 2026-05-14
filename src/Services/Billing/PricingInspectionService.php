<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Billing;

use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AIModel;
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

        $priceFloors = $this->modelPriceFloors();
        foreach ($priceFloors['warnings'] as $warning) {
            $warnings[] = $warning;
        }

        return [
            'currency' => config('ai-engine.credits.currency', 'MyCredits'),
            'enabled' => (bool) config('ai-engine.credits.enabled', false),
            'engine_rates' => $rates,
            'additional_input_unit_rates' => (array) config('ai-engine.credits.additional_input_unit_rates', []),
            'model_price_floors' => $priceFloors,
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

    /**
     * @return array{checked:int,warnings_count:int,warnings:list<string>}
     */
    private function modelPriceFloors(): array
    {
        if (!Schema::hasTable('ai_models')) {
            return ['checked' => 0, 'warnings_count' => 0, 'warnings' => []];
        }

        $warnings = [];
        $checked = 0;

        AIModel::query()
            ->where('is_active', true)
            ->where('is_deprecated', false)
            ->whereNotNull('pricing')
            ->get()
            ->each(function (AIModel $model) use (&$warnings, &$checked): void {
                $floor = $model->pricing['minimum_app_credits_per_unit'] ?? null;
                if (!is_numeric($floor) || (float) $floor <= 0.0) {
                    return;
                }

                $checked++;
                $engine = (string) $model->provider;
                $engineRate = (float) config("ai-engine.credits.engine_rates.{$engine}", 1.0);
                $unitCharge = EntityEnum::from((string) $model->model_id)->creditIndex() * $engineRate;

                if ($unitCharge < (float) $floor) {
                    $warnings[] = sprintf(
                        '%s/%s configured unit charge [%s] is below pricing floor [%s].',
                        $engine,
                        $model->model_id,
                        round($unitCharge, 8),
                        (float) $floor
                    );
                }
            });

        return [
            'checked' => $checked,
            'warnings_count' => count($warnings),
            'warnings' => $warnings,
        ];
    }
}
