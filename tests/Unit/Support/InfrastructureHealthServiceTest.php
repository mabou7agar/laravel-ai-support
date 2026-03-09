<?php

namespace LaravelAIEngine\Tests\Unit\Support;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;
use LaravelAIEngine\Tests\UnitTestCase;

class InfrastructureHealthServiceTest extends UnitTestCase
{
    public function test_remote_node_migration_guard_reports_missing_tables(): void
    {
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.enabled', true);
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.required_tables', ['__missing_guard_table__']);
        config()->set('ai-engine.infrastructure.qdrant_self_check.enabled', false);

        $service = app(InfrastructureHealthService::class);
        $report = $service->evaluate();

        $this->assertFalse($report['ready']);
        $this->assertSame('degraded', $report['status']);
        $this->assertSame(['__missing_guard_table__'], $report['checks']['remote_node_migrations']['missing_tables']);
    }

    public function test_qdrant_self_check_passes_with_successful_response(): void
    {
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.enabled', false);
        config()->set('ai-engine.infrastructure.qdrant_self_check.enabled', true);
        config()->set('ai-engine.vector.default_driver', 'qdrant');
        config()->set('ai-engine.vector.drivers.qdrant.host', 'http://qdrant.test:6333');

        Http::fake([
            'http://qdrant.test:6333/collections' => Http::response(['status' => 'ok'], 200),
        ]);

        $service = app(InfrastructureHealthService::class);
        $report = $service->evaluate();

        $this->assertTrue($report['ready']);
        $this->assertTrue($report['checks']['qdrant_connectivity']['healthy']);
        $this->assertSame(200, $report['checks']['qdrant_connectivity']['status_code']);
    }

    public function test_qdrant_self_check_fails_on_non_success_status(): void
    {
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.enabled', false);
        config()->set('ai-engine.infrastructure.qdrant_self_check.enabled', true);
        config()->set('ai-engine.vector.default_driver', 'qdrant');
        config()->set('ai-engine.vector.drivers.qdrant.host', 'http://qdrant.test:6333');

        Http::fake([
            'http://qdrant.test:6333/collections' => Http::response(['status' => 'down'], 503),
        ]);

        $service = app(InfrastructureHealthService::class);
        $report = $service->evaluate();

        $this->assertFalse($report['ready']);
        $this->assertFalse($report['checks']['qdrant_connectivity']['healthy']);
        $this->assertSame(503, $report['checks']['qdrant_connectivity']['status_code']);
    }
}
