<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\AIEngineServiceProvider;
use LaravelAIEngine\Services\Graph\GraphBackendResolver;
use LaravelAIEngine\Support\Config\AIEngineConfigDefaults;
use LaravelAIEngine\Tests\UnitTestCase;

final class BootDefaultsNoExternalCallsTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        $this->resetStartupGate();
        parent::tearDown();
    }

    public function test_graph_is_disabled_by_default(): void
    {
        $this->skipIfEnvSet('AI_ENGINE_GRAPH_ENABLED');

        $defaults = AIEngineConfigDefaults::defaults();

        self::assertFalse((bool) $defaults['graph']['enabled']);

        config()->set('ai-engine.graph', $defaults['graph']);
        self::assertFalse(app(GraphBackendResolver::class)->graphReadPathActive());
    }

    public function test_startup_health_gate_with_default_config_makes_no_qdrant_or_neo4j_calls(): void
    {
        $this->skipIfEnvSet('QDRANT_HOST', 'QDRANT_API_KEY', 'AI_ENGINE_QDRANT_SELF_CHECK_ENABLED', 'AI_ENGINE_GRAPH_ENABLED');

        $defaults = AIEngineConfigDefaults::defaults();
        config()->set('ai-engine.infrastructure', $defaults['infrastructure']);
        config()->set('ai-engine.vector.default_driver', $defaults['vector']['default_driver']);
        config()->set('ai-engine.vector.drivers', $defaults['vector']['drivers']);
        config()->set('ai-engine.graph', $defaults['graph']);
        // Exercise the web-request boot path (tests run in the console).
        config()->set('ai-engine.infrastructure.startup_health_gate.enabled', true);
        config()->set('ai-engine.infrastructure.startup_health_gate.skip_in_console', false);
        config()->set('ai-engine.infrastructure.startup_health_gate.cache_seconds', 0);
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.enabled', false);

        Http::fake();
        Cache::flush();
        $this->runStartupGate();

        Http::assertNothingSent();
    }

    public function test_configured_qdrant_is_still_checked_at_boot(): void
    {
        config()->set('ai-engine.infrastructure.startup_health_gate.enabled', true);
        config()->set('ai-engine.infrastructure.startup_health_gate.skip_in_console', false);
        config()->set('ai-engine.infrastructure.startup_health_gate.cache_seconds', 0);
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.enabled', false);
        config()->set('ai-engine.infrastructure.qdrant_self_check.enabled', true);
        config()->set('ai-engine.vector.default_driver', 'qdrant');
        config()->set('ai-engine.vector.drivers.qdrant.host', 'http://qdrant.test:6333');

        Http::fake(['http://qdrant.test:6333/*' => Http::response(['result' => []], 200)]);
        $this->runStartupGate();

        Http::assertSentCount(1);
    }

    public function test_qdrant_self_check_skips_when_no_host_is_configured(): void
    {
        config()->set('ai-engine.infrastructure.qdrant_self_check.enabled', true);
        config()->set('ai-engine.vector.default_driver', 'qdrant');
        config()->set('ai-engine.vector.drivers.qdrant.host', null);
        Http::fake();

        $status = app(\LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService::class)->qdrantConnectivityStatus();

        self::assertFalse($status['required']);
        self::assertTrue($status['healthy']);
        Http::assertNothingSent();
    }

    private function runStartupGate(): void
    {
        $this->resetStartupGate();
        $provider = new AIEngineServiceProvider($this->app);
        $method = new \ReflectionMethod(AIEngineServiceProvider::class, 'runStartupHealthGate');
        $method->invoke($provider);
    }

    private function resetStartupGate(): void
    {
        $property = new \ReflectionProperty(AIEngineServiceProvider::class, 'startupGateChecked');
        $property->setValue(null, false);
    }

    private function skipIfEnvSet(string ...$names): void
    {
        foreach ($names as $name) {
            if (getenv($name) !== false || isset($_ENV[$name]) || isset($_SERVER[$name])) {
                self::markTestSkipped("{$name} is set in the environment.");
            }
        }
    }
}
