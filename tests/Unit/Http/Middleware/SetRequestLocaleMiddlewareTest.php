<?php

namespace LaravelAIEngine\Tests\Unit\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaravelAIEngine\Http\Middleware\SetRequestLocaleMiddleware;
use LaravelAIEngine\Tests\UnitTestCase;

class SetRequestLocaleMiddlewareTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-engine.localization.enabled', true);
        config()->set('ai-engine.localization.query_parameter', 'locale');
        config()->set('ai-engine.localization.header', 'X-Locale');
        config()->set('ai-engine.localization.supported_locales', ['en', 'ar']);
        config()->set('ai-engine.localization.fallback_locale', 'en');
        config()->set('ai-engine.localization.detect_from_accept_language', true);
        config()->set('ai-engine.localization.detect_from_user', false);
        config()->set('ai-engine.localization.detect_from_message', true);
        config()->set('ai-engine.localization.script_detection', [
            'ar' => '/[\x{0600}-\x{06FF}]/u',
        ]);

        app()->setLocale('en');
    }

    public function test_it_sets_locale_from_query_parameter(): void
    {
        $middleware = new SetRequestLocaleMiddleware();
        $request = Request::create('/api/test?locale=ar', 'GET');

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ar', app()->getLocale());
        $this->assertSame('ar', $request->attributes->get('ai_engine_locale'));
    }

    public function test_it_sets_locale_from_custom_header(): void
    {
        $middleware = new SetRequestLocaleMiddleware();
        $request = Request::create('/api/test', 'GET', [], [], [], ['HTTP_X_LOCALE' => 'ar']);

        $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_it_uses_accept_language_when_no_explicit_locale(): void
    {
        $middleware = new SetRequestLocaleMiddleware();
        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'ar-EG,ar;q=0.9,en;q=0.8',
        ]);

        $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_it_detects_locale_from_message_script_before_accept_language(): void
    {
        $middleware = new SetRequestLocaleMiddleware();
        $request = Request::create('/api/test', 'POST', [
            'message' => 'هل يوجد فواتير؟',
        ], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9,ar;q=0.8',
        ]);

        $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame('ar', app()->getLocale());
        $this->assertSame('ar', $request->attributes->get('ai_engine_locale'));
    }

    public function test_it_skips_when_localization_is_disabled(): void
    {
        config()->set('ai-engine.localization.enabled', false);
        app()->setLocale('en');

        $middleware = new SetRequestLocaleMiddleware();
        $request = Request::create('/api/test?locale=ar', 'GET');

        $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame('en', app()->getLocale());
        $this->assertNull($request->attributes->get('ai_engine_locale'));
    }
}
