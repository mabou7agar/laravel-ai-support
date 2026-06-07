<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;

/**
 * A failover chain often lists engines whose API keys aren't set in this deployment.
 * Attempting them wastes an attempt and surfaces a misleading "<engine> API key is required"
 * error that masks the real upstream failure — so they must be dropped from the chain.
 */
class AIEngineServiceFailoverFilterTest extends TestCase
{
    private function service(): AIEngineService
    {
        return app(AIEngineService::class);
    }

    private function usable(array $chain): array
    {
        $method = new \ReflectionMethod(AIEngineService::class, 'usableFallbackEngines');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $chain);
    }

    public function test_drops_fallback_engines_with_no_api_key(): void
    {
        config()->set('ai-engine.engines.alpha', ['api_key' => 'present']);
        config()->set('ai-engine.engines.beta', ['api_key' => '']);   // configured but empty
        config()->set('ai-engine.engines.gamma', ['driver' => 'local']); // no api_key concept

        $this->assertSame(['alpha', 'gamma'], $this->usable(['alpha', 'beta', 'gamma']));
    }

    public function test_keeps_order_and_drops_unknown_engines_gracefully(): void
    {
        config()->set('ai-engine.engines.one', ['api_key' => 'k1']);
        config()->set('ai-engine.engines.two', ['api_key' => 'k2']);
        // 'missing' has no config entry at all -> treated as available (can't introspect).

        $this->assertSame(['one', 'missing', 'two'], $this->usable(['one', 'missing', 'two']));
    }

    public function test_empty_chain_stays_empty(): void
    {
        $this->assertSame([], $this->usable([]));
    }
}
