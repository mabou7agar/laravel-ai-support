<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;

class CreditRequestEstimator
{
    public function breakdown(AIRequest $request): array
    {
        $inputCount = $this->inputCount($request);
        $creditIndex = $request->model->creditIndex();
        $baseEngineCredits = $inputCount * $creditIndex;
        $additionalInputCredits = $this->additionalInputUnitEngineCredits($request);
        $totalEngineCredits = $baseEngineCredits + $additionalInputCredits;
        $engineRate = $this->engineRate($request->engine);

        return [
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'calculation_method' => $request->model->calculationMethod(),
            'input_count' => round($inputCount, 8),
            'credit_index' => round($creditIndex, 8),
            'base_engine_credits' => round($baseEngineCredits, 8),
            'additional_input_engine_credits' => round($additionalInputCredits, 8),
            'total_engine_credits' => round($totalEngineCredits, 8),
            'engine_rate' => round($engineRate, 8),
            'final_credits' => round($totalEngineCredits * $engineRate, 8),
        ];
    }

    private function engineRate(EngineEnum $engine): float
    {
        $rates = config('ai-engine.credits.engine_rates', []);

        return $rates[$engine->value] ?? 1.0;
    }

    private function inputCount(AIRequest $request): float
    {
        return match ($request->model->calculationMethod()) {
            'words' => $this->countWords($request->prompt),
            'characters' => $this->countCharacters($request->prompt),
            'images' => $this->firstNumericParameter($request, ['image_count', 'num_images', 'frame_count'], 1),
            'videos' => $this->firstNumericParameter($request, ['video_count', 'num_videos'], 1),
            'minutes' => $this->firstNumericParameter($request, ['audio_minutes', 'duration_minutes'], 1),
            default => 1,
        };
    }

    private function firstNumericParameter(AIRequest $request, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $request->parameters)) {
                continue;
            }

            $value = $request->parameters[$key];
            if (is_numeric($value)) {
                return max(0.0, (float) $value);
            }
        }

        return $default;
    }

    private function additionalInputUnitEngineCredits(AIRequest $request): float
    {
        $engine = $request->engine->value;
        $model = $request->model->value;
        $policy = config("ai-engine.credits.additional_input_unit_rates.{$engine}", []);

        if (!is_array($policy) || $policy === []) {
            return 0.0;
        }

        $rates = array_replace(
            $policy['default'] ?? [],
            $policy['models'][$model] ?? []
        );

        if (!is_array($rates) || $rates === []) {
            return 0.0;
        }

        $engineCredits = 0.0;

        foreach ($rates as $unit => $rate) {
            if (!is_numeric($rate) || (float) $rate <= 0) {
                continue;
            }

            $engineCredits += $this->additionalInputUnitCount($request, (string) $unit) * (float) $rate;
        }

        return $engineCredits;
    }

    private function additionalInputUnitCount(AIRequest $request, string $unit): float
    {
        return match ($unit) {
            'image', 'images' => $this->inputMediaUnitCount($request->parameters, [
                'image_url',
                'image_urls',
                'input_image',
                'input_images',
                'reference_image',
                'reference_images',
                'reference_image_url',
                'reference_image_urls',
                'start_image',
                'start_image_url',
                'end_image',
                'end_image_url',
                'mask_image',
                'mask_image_url',
                'init_image',
                'init_images',
                'source_image',
                'source_images',
            ]),
            default => 0.0,
        };
    }

    private function inputMediaUnitCount(array $parameters, array $keys): float
    {
        $count = 0.0;

        foreach ($keys as $key) {
            if (array_key_exists($key, $parameters)) {
                $count += $this->mediaValueCount($parameters[$key]);
            }
        }

        return $count;
    }

    private function mediaValueCount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_string($value)) {
            return 1.0;
        }

        if (!is_array($value)) {
            return 0.0;
        }

        if (array_is_list($value)) {
            return array_sum(array_map(fn (mixed $item): float => $this->mediaValueCount($item), $value));
        }

        foreach (['url', 'image_url', 'path', 'file_id'] as $mediaKey) {
            if (isset($value[$mediaKey]) && $value[$mediaKey] !== '') {
                return 1.0;
            }
        }

        return array_sum(array_map(fn (mixed $item): float => $this->mediaValueCount($item), $value));
    }

    private function countWords(string $text): int
    {
        return str_word_count(strip_tags($text));
    }

    private function countCharacters(string $text): int
    {
        return mb_strlen(strip_tags($text));
    }
}
