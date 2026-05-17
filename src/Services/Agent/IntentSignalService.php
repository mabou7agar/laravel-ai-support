<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Services\Localization\LocaleResourceService;

class IntentSignalService
{
    public function __construct(
        protected ?LocaleResourceService $localeResources = null,
        protected ?AgentIntentUnderstandingService $intentUnderstanding = null,
    ) {
    }

    public function isAffirmative(string $message): bool
    {
        if ($this->understoodIntentIs($message, ['confirm'])) {
            return true;
        }

        return $this->matches($message, ['intent.confirm', 'response.affirmative']);
    }

    public function isNegative(string $message): bool
    {
        if ($this->understoodIntentIs($message, ['reject'])) {
            return true;
        }

        return $this->matches($message, ['intent.deny', 'intent.reject', 'intent.cancel', 'response.negative']);
    }

    public function isRelationUseExisting(string $message): bool
    {
        if ($this->understoodIntentIs($message, ['choose_existing'])) {
            return true;
        }

        return $this->matches($message, ['relation.use_existing']);
    }

    public function isRelationCreateNew(string $message): bool
    {
        if ($this->understoodIntentIs($message, ['create_new'])) {
            return true;
        }

        return $this->matches($message, ['relation.create_new']);
    }

    /**
     * @param array<int, string> $keys
     */
    public function matches(string $message, array $keys): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        foreach ($keys as $key) {
            if ($this->locale()->isLexiconMatch($message, $key)
                || $this->locale()->startsWithLexicon($message, $key)
                || $this->locale()->containsLexicon($message, $key)) {
                return true;
            }
        }

        return false;
    }

    protected function locale(): LocaleResourceService
    {
        return $this->localeResources ??= app(LocaleResourceService::class);
    }

    /**
     * @param array<int, string> $intents
     */
    protected function understoodIntentIs(string $message, array $intents): bool
    {
        if (strtolower((string) config('ai-agent.intent_understanding.mode', 'heuristic')) === 'heuristic') {
            return false;
        }

        try {
            $decision = $this->understanding()->decide($message);

            return in_array($decision->intent, $intents, true)
                && $decision->confidence >= (float) config('ai-agent.intent_understanding.min_confidence', 0.6);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function understanding(): AgentIntentUnderstandingService
    {
        return $this->intentUnderstanding ??= app(AgentIntentUnderstandingService::class);
    }
}
