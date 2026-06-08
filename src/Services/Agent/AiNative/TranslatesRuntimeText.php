<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Localization\LocaleResourceService;

/**
 * Resolves a localized runtime string by key, falling back to the English default when the
 * key is missing (or the locale resources are unavailable). Used by the AiNative runtime and
 * its response factory so the messages the user sees DIRECTLY — "I need more information to
 * continue.", "That action has already been completed.", confirmation labels, etc. — follow
 * the active locale instead of being hardcoded English.
 */
trait TranslatesRuntimeText
{
    /**
     * @param array<string, mixed> $replace
     */
    protected function runtimeText(string $key, string $fallback, array $replace = []): string
    {
        $translated = app(LocaleResourceService::class)->translation($key, $replace);

        return $translated !== '' ? $translated : $fallback;
    }
}
