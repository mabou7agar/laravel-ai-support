<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class AiNativeConfirmationIntent
{
    public function __construct(
        private readonly IntentSignalService $signals,
        private readonly ?LocaleResourceService $locale = null
    ) {}

    public function isApproval(string $message): bool
    {
        if ($this->signals->isAffirmative($message)) {
            return true;
        }

        $locale = $this->locale ?? app(LocaleResourceService::class);
        if ($locale->isLexiconMatch($message, 'intent.confirm')
            || $locale->isLexiconMatch($message, 'response.affirmative')) {
            return true;
        }

        if (preg_match('/^\s*(confirm|confirmed|approve|approved|yes|ok|okay|proceed)[\s.!?]*$/iu', $message) === 1) {
            return true;
        }

        foreach ((array) config('ai-agent.skills.continuation_terms', []) as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }

            $pattern = '/^'.str_replace('\.\*', '.*', preg_quote(mb_strtolower($term), '/')).'$/iu';
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
