<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;

class PositionalReferenceAIService
{
    public function __construct(
        protected ?AIEngineService $ai = null,
        protected ?IntentClassifierService $intentClassifier = null,
        protected ?FollowUpStateService $followUpState = null,
        protected array $settings = []
    ) {
    }

    public function resolvePosition(string $message, UnifiedActionContext $context): ?int
    {
        if (!$this->hasListContext($context)) {
            return null;
        }

        if (!$this->isAIEnabled()) {
            return $this->useRulesFallbackWhenAIDisabled()
                ? $this->resolveWithRules($message)
                : null;
        }

        try {
            $request = new AIRequest(
                prompt: $this->buildPrompt($message, $context),
                engine: $this->resolveEngine(),
                model: $this->resolveModel(),
                maxTokens: (int) ($this->settings['max_tokens'] ?? 24),
                temperature: (float) ($this->settings['temperature'] ?? 0.0)
            );

            $response = $this->getAI()->generate($request);
            $position = $this->parsePosition($response->getContent());
            if ($position !== null) {
                return $position;
            }
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('PositionalReferenceAIService: AI resolution failed', [
                'error' => $e->getMessage(),
                'rules_fallback_on_ai_failure' => $this->useRulesFallbackOnAIFailure(),
            ]);
        }

        if ($this->useRulesFallbackOnAIFailure()) {
            return $this->resolveWithRules($message);
        }

        return null;
    }

    protected function resolveWithRules(string $message): ?int
    {
        if (!$this->getIntentClassifier()->isPositionalReference($message)) {
            return null;
        }

        return $this->sanitizePosition($this->getIntentClassifier()->extractPosition($message));
    }

    protected function parsePosition(string $content): ?int
    {
        $responseKey = preg_quote($this->responseKey(), '/');
        $noneValue = strtoupper($this->noneValue());

        if (preg_match('/' . $responseKey . ':\s*([A-Z0-9_\/-]+)/i', $content, $matches)) {
            if (strtoupper($matches[1]) === $noneValue) {
                return null;
            }

            if (is_numeric($matches[1])) {
                return $this->sanitizePosition((int) $matches[1]);
            }

            return null;
        }

        $line = strtoupper(trim((string) strtok($content, "\n")));
        if (str_contains($line, ':')) {
            $parts = explode(':', $line, 2);
            $line = strtoupper(trim((string) ($parts[1] ?? '')));
        }

        if ($line === $noneValue) {
            return null;
        }

        if (is_numeric($line)) {
            return $this->sanitizePosition((int) $line);
        }

        return null;
    }

    protected function buildPrompt(string $message, UnifiedActionContext $context): string
    {
        $listContext = $this->getFollowUpState()->formatEntityListContext($context);
        $responseKey = $this->responseKey();
        $noneValue = $this->noneValue();

        return <<<PROMPT
Decide if the user message refers to a numbered item from a previously listed result set.

Previous Result Context:
{$listContext}

User Message:
"{$message}"

If the user clearly refers to a position/order/numbered item (first, second, #3, number 4, item 2), respond:
{$responseKey}: <number>

If not a positional selection, respond:
{$responseKey}: {$noneValue}

Return only one line in exactly that format.
PROMPT;
    }

    protected function sanitizePosition(?int $position): ?int
    {
        if ($position === null) {
            return null;
        }

        $max = (int) ($this->settings['max_position'] ?? 100);
        if ($position < 1 || $position > $max) {
            return null;
        }

        return $position;
    }

    protected function hasListContext(UnifiedActionContext $context): bool
    {
        return $this->getFollowUpState()->hasEntityListContext($context);
    }

    protected function isAIEnabled(): bool
    {
        return (bool) ($this->settings['enabled'] ?? true);
    }

    protected function useRulesFallbackOnAIFailure(): bool
    {
        return (bool) ($this->settings['rules_fallback_on_ai_failure'] ?? false);
    }

    protected function useRulesFallbackWhenAIDisabled(): bool
    {
        return (bool) ($this->settings['rules_fallback_when_ai_disabled'] ?? true);
    }

    protected function resolveEngine(): EngineEnum
    {
        $engine = (string) ($this->settings['engine'] ?? config('ai-engine.default', 'openai'));
        return EngineEnum::from($engine);
    }

    protected function resolveModel(): EntityEnum
    {
        $model = (string) ($this->settings['model'] ?? config('ai-engine.orchestration_model', 'gpt-4o-mini'));
        return EntityEnum::from($model);
    }

    protected function responseKey(): string
    {
        $key = (string) $this->protocol('response_key', 'POSITION');
        return strtoupper(trim($key)) !== '' ? strtoupper(trim($key)) : 'POSITION';
    }

    protected function noneValue(): string
    {
        $value = (string) $this->protocol('none_value', 'NONE');
        return strtoupper(trim($value)) !== '' ? strtoupper(trim($value)) : 'NONE';
    }

    protected function protocol(string $key, $default = null)
    {
        if (array_key_exists($key, $this->settings)) {
            return $this->settings[$key];
        }

        try {
            return config("ai-agent.protocol.positional_reference.{$key}", $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    protected function getAI(): AIEngineService
    {
        if ($this->ai === null) {
            $this->ai = app(AIEngineService::class);
        }

        return $this->ai;
    }

    protected function getIntentClassifier(): IntentClassifierService
    {
        if ($this->intentClassifier === null) {
            $this->intentClassifier = app(IntentClassifierService::class);
        }

        return $this->intentClassifier;
    }

    protected function getFollowUpState(): FollowUpStateService
    {
        if ($this->followUpState === null) {
            $this->followUpState = app(FollowUpStateService::class);
        }

        return $this->followUpState;
    }
}
