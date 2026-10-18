<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\CreditManager;

/**
 * Credit billing for laravel/ai turns, mirroring how AIEngineService bills
 * built-in engine calls:
 *
 * - credits = units x model credit index (from the package model catalog,
 *   1.0 for uncatalogued models, as for engine calls) x engine rate;
 * - the credits are always added to the request accumulator, so the run
 *   records them as `credits_used`;
 * - when `ai-engine.credits.enabled` is on (master node only under
 *   federation) and the turn has a user, the credits are deducted from the
 *   user, and the balance is checked before the SDK is called.
 *
 * The SDK reports tokens, not the package's credit units, so units are
 * derived from the turn's input + output tokens: words for text models
 * (tokens x `words_per_token`), characters for character-billed models
 * (tokens x `characters_per_token`), otherwise one unit per turn.
 */
final class LaravelAiCreditBiller
{
    public function __construct(
        private readonly CreditManager $credits
    ) {
    }

    /**
     * Refuse a turn the user cannot afford, before the SDK is called. Uses
     * the configured model and the prompt's word count as the estimate; when
     * no model is configured (the SDK picks its default) the check is left to
     * the charge after the turn.
     *
     * @throws InsufficientCreditsException
     */
    public function assertCanAfford(string $message, mixed $userId, ?string $provider, ?string $model): void
    {
        if (!$this->deducts($userId) || $model === null) {
            return;
        }

        $request = $this->request($message, $provider, $model, $userId);

        if (!$this->credits->hasCredits((string) $userId, $request)) {
            throw new InsufficientCreditsException('Insufficient credits for this request');
        }
    }

    /**
     * Price the turn, accumulate it on the run and deduct it from the user.
     *
     * @return array<string, mixed> billing details for the response metadata
     */
    public function charge(LaravelAiTurn $turn, string $message, mixed $userId, ?string $provider, ?string $model): array
    {
        if (!(bool) config('ai-agent.runtime.laravel_ai.billing.enabled', true)) {
            return ['status' => 'disabled'];
        }

        $model = $turn->model ?? $model;
        if ($model === null || trim($model) === '') {
            \Illuminate\Support\Facades\Log::channel('ai-engine')->warning('laravel_ai turn could not be priced: the SDK reported no model', [
                'invocation_id' => $turn->invocationId,
            ]);

            return ['status' => 'unpriced', 'reason' => 'model_unknown'];
        }

        $request = $this->request($message, $turn->provider ?? $provider, $model, $userId);
        $method = $request->model->calculationMethod();
        $units = $this->units($turn, $method);
        $engineRate = (float) (config('ai-engine.credits.engine_rates', [])[$request->engine->value] ?? 1.0);
        $credits = round($units * $request->model->creditIndex() * $engineRate, 8);

        CreditManager::accumulate($credits);

        $deducted = false;
        if ($credits > 0 && $this->deducts($userId)) {
            $deducted = $this->credits->deductCredits((string) $userId, $request, $credits);
        }

        return [
            'status' => $deducted ? 'charged' : 'accumulated',
            'credits' => $credits,
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'calculation_method' => $method,
            'units' => $units,
            'credit_index' => $request->model->creditIndex(),
            'engine_rate' => $engineRate,
        ];
    }

    private function units(LaravelAiTurn $turn, string $method): float
    {
        $tokens = $turn->totalTokens();

        return match ($method) {
            'words' => (float) max(1, (int) ceil($tokens * (float) config('ai-agent.runtime.laravel_ai.billing.words_per_token', 0.75))),
            'characters' => (float) max(1, (int) ceil($tokens * (float) config('ai-agent.runtime.laravel_ai.billing.characters_per_token', 4))),
            default => 1.0,
        };
    }

    private function deducts(mixed $userId): bool
    {
        if (!(bool) config('ai-agent.runtime.laravel_ai.billing.enabled', true)
            || !(bool) config('ai-engine.credits.enabled', false)
        ) {
            return false;
        }

        if ((bool) config('ai-engine.nodes.enabled', false) && !(bool) config('ai-engine.nodes.is_master', true)) {
            return false;
        }

        return is_int($userId) || (is_string($userId) && trim($userId) !== '');
    }

    private function request(string $message, ?string $provider, string $model, mixed $userId): AIRequest
    {
        $entity = EntityEnum::from($model);

        return new AIRequest(
            prompt: $message,
            engine: $this->engine($provider) ?? $entity->engine(),
            model: $entity,
            userId: is_int($userId) || is_string($userId) ? (string) $userId : null
        );
    }

    /**
     * Map a laravel/ai provider name onto a package engine. Most names match;
     * `ai-agent.runtime.laravel_ai.billing.engines` overrides or adds
     * mappings. Unknown providers fall back to the engine the catalog
     * associates with the model.
     */
    private function engine(?string $provider): ?EngineEnum
    {
        if ($provider === null || trim($provider) === '') {
            return null;
        }

        $provider = strtolower(trim($provider));
        $mapped = (array) config('ai-agent.runtime.laravel_ai.billing.engines', []);
        $name = is_string($mapped[$provider] ?? null) ? $mapped[$provider] : $provider;

        return EngineEnum::tryFrom($name);
    }
}
