<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\Tests\UnitTestCase;

/**
 * The user-facing fallback strings added for the degraded-turn fixes must
 * exist in every shipped locale — missing keys silently fall back to English
 * (the exact bug class these keys were added to eliminate).
 */
class RuntimeFallbackLocalizationTest extends UnitTestCase
{
    private const LOCALES = ['en', 'ar', 'de', 'es', 'fr', 'pt'];

    private const REQUIRED_RESPONSE_KEYS = [
        'completed_without_summary',
        'tool_step_failed',
        'need_more_information',
        'more_information_required',
    ];

    public function test_every_locale_ships_the_fallback_response_keys(): void
    {
        $base = dirname(__DIR__, 5) . '/resources/lang';

        foreach (self::LOCALES as $locale) {
            $file = "{$base}/{$locale}/runtime.php";
            self::assertFileExists($file);

            $strings = require $file;
            $responses = (array) ($strings['responses'] ?? []);

            foreach (self::REQUIRED_RESPONSE_KEYS as $key) {
                self::assertArrayHasKey($key, $responses, "{$locale}: responses.{$key}");
                self::assertNotSame('', trim((string) $responses[$key]), "{$locale}: responses.{$key} is empty");
            }
        }
    }

    public function test_tool_step_failed_carries_both_placeholders_in_every_locale(): void
    {
        $base = dirname(__DIR__, 5) . '/resources/lang';

        foreach (self::LOCALES as $locale) {
            $strings = require "{$base}/{$locale}/runtime.php";
            $line = (string) $strings['responses']['tool_step_failed'];

            self::assertStringContainsString(':tool', $line, $locale);
            self::assertStringContainsString(':errors', $line, $locale);
        }
    }
}
