<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Support\Collection;
use LaravelAIEngine\Models\AIModel;

class AIModelRecommendationService
{
    public function recommend(string $task, Collection $models): ?AIModel
    {
        $task = $this->normalizeTask($task);

        return match ($task) {
            'vision' => $this->sortByLowestInputPrice($models->filter(fn (AIModel $model): bool => $model->isVisionModel()))->first(),
            'coding' => $models->first(fn (AIModel $model): bool => in_array('coding', $model->capabilities ?? [], true)),
            'reasoning' => $models->first(fn (AIModel $model): bool => in_array('reasoning', $model->capabilities ?? [], true)),
            'fast', 'cheap' => $this->sortByLowestInputPrice($models)->first(),
            'performance' => $this->bestPerformanceModel($models),
            'quality' => $this->sortByLargestContext($models)->first(),
            default => $this->sortByLowestInputPrice(
                $models->filter(fn (AIModel $model): bool => in_array('chat', $model->capabilities ?? [], true))
            )->first(),
        };
    }

    public function normalizeTask(string $task): string
    {
        return match (strtolower(trim($task))) {
            'cost' => 'cheap',
            'speed' => 'fast',
            default => strtolower(trim($task)),
        };
    }

    public function bestPerformanceModel(Collection $models): ?AIModel
    {
        if ($models->isEmpty()) {
            return null;
        }

        return $models
            ->sortByDesc(fn (AIModel $model): float => $this->performanceScore($model))
            ->first();
    }

    public function performanceScore(AIModel $model): float
    {
        $context = (float) ($model->context_window['input'] ?? 0);
        $price = $model->pricing['input'] ?? null;
        $price = is_numeric($price) ? (float) $price : null;

        $score = 0.0;
        $score += min($context / 100000, 4.0);
        $score += $model->supports_streaming ? 1.0 : 0.0;
        $score += $model->supports_function_calling ? 1.0 : 0.0;
        $score += $model->supports_vision ? 0.5 : 0.0;

        if ($price === null) {
            $score += 0.5;
        } elseif ($price <= 0.0002) {
            $score += 3.0;
        } elseif ($price <= 0.001) {
            $score += 2.2;
        } elseif ($price <= 0.005) {
            $score += 1.5;
        } elseif ($price <= 0.02) {
            $score += 0.8;
        } else {
            $score += 0.3;
        }

        return $score;
    }

    public function sortByLowestInputPrice(Collection $models): Collection
    {
        return $models->sortBy(function (AIModel $model): float {
            $price = $model->pricing['input'] ?? null;

            return is_numeric($price) ? (float) $price : INF;
        })->values();
    }

    public function sortByLargestContext(Collection $models): Collection
    {
        return $models->sortByDesc(function (AIModel $model): int {
            return (int) ($model->context_window['input'] ?? 0);
        })->values();
    }
}
