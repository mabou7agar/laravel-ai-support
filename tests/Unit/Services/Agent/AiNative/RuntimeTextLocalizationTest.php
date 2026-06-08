<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseFactory;
use LaravelAIEngine\Tests\TestCase;

/**
 * The runtime's directly-shown messages (not re-expressed by the model) must follow the active
 * locale instead of being hardcoded English.
 */
class RuntimeTextLocalizationTest extends TestCase
{
    public function test_already_completed_message_follows_active_locale(): void
    {
        $factory = app(AiNativeResponseFactory::class);
        $context = new UnifiedActionContext('loc-test');

        app()->setLocale('en');
        $this->assertSame(
            'That action has already been completed.',
            $factory->alreadyCompleted($context, [])->message
        );

        app()->setLocale('de');
        $this->assertSame(
            'Diese Aktion wurde bereits ausgeführt.',
            $factory->alreadyCompleted($context, [])->message
        );

        app()->setLocale('fr');
        $this->assertSame(
            'Cette action a déjà été effectuée.',
            $factory->alreadyCompleted($context, [])->message
        );

        app()->setLocale('en');
    }

    public function test_context_noted_message_localizes(): void
    {
        $factory = app(AiNativeResponseFactory::class);
        $context = new UnifiedActionContext('loc-test-2');

        app()->setLocale('es');
        $this->assertSame(
            'He tomado nota de ese contexto. Dime qué quieres hacer a continuación.',
            $factory->nonActionContext($context, [])->message
        );

        app()->setLocale('en');
    }
}
