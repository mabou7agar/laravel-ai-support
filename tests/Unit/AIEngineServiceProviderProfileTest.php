<?php

namespace LaravelAIEngine\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\AIEngineServiceProvider;
use PHPUnit\Framework\TestCase;

class AIEngineServiceProviderProfileTest extends TestCase
{
    protected Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        Facade::clearResolvedInstances();
        $this->app = new Container();
        $this->app->instance('config', new Repository([]));
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_balanced_profile_keeps_section_fallback_settings(): void
    {
        $this->app['config']->set('ai-agent.ai_first.profile', 'balanced');
        $this->app['config']->set('ai-agent.ai_first.strict', null);
        $this->app['config']->set('ai-agent.followup_guard', [
            'rules_fallback_on_ai_failure' => true,
            'rules_fallback_when_ai_disabled' => true,
        ]);

        $resolved = $this->invokeResolveSection('followup_guard');

        $this->assertTrue($resolved['rules_fallback_on_ai_failure']);
        $this->assertTrue($resolved['rules_fallback_when_ai_disabled']);
    }

    public function test_strict_profile_forces_fallback_settings_off(): void
    {
        $this->app['config']->set('ai-agent.ai_first.profile', 'strict_ai_first');
        $this->app['config']->set('ai-agent.ai_first.strict', null);
        $this->app['config']->set('ai-agent.followup_guard', [
            'rules_fallback_on_ai_failure' => true,
            'rules_fallback_when_ai_disabled' => true,
        ]);

        $resolved = $this->invokeResolveSection('followup_guard');

        $this->assertFalse($resolved['rules_fallback_on_ai_failure']);
        $this->assertFalse($resolved['rules_fallback_when_ai_disabled']);
    }

    public function test_explicit_strict_false_overrides_strict_profile(): void
    {
        $this->app['config']->set('ai-agent.ai_first.profile', 'strict_ai_first');
        $this->app['config']->set('ai-agent.ai_first.strict', false);
        $this->app['config']->set('ai-agent.routed_session', [
            'use_explicit_topic_checks' => true,
            'fallback_continue_on_ai_error' => true,
        ]);

        $resolved = $this->invokeResolveSection('routed_session');

        $this->assertTrue($resolved['use_explicit_topic_checks']);
        $this->assertTrue($resolved['fallback_continue_on_ai_error']);
    }

    public function test_strict_profile_overrides_routed_session_fallbacks(): void
    {
        $this->app['config']->set('ai-agent.ai_first.profile', 'strict_ai_first');
        $this->app['config']->set('ai-agent.ai_first.strict', null);
        $this->app['config']->set('ai-agent.routed_session', [
            'use_explicit_topic_checks' => true,
            'fallback_continue_on_ai_error' => true,
        ]);

        $resolved = $this->invokeResolveSection('routed_session');

        $this->assertFalse($resolved['use_explicit_topic_checks']);
        $this->assertFalse($resolved['fallback_continue_on_ai_error']);
    }

    protected function invokeResolveSection(string $section): array
    {
        $provider = new AIEngineServiceProvider($this->app);
        $method = new \ReflectionMethod(AIEngineServiceProvider::class, 'resolveAgentSectionSettings');
        $method->setAccessible(true);

        return $method->invoke($provider, $section);
    }
}
