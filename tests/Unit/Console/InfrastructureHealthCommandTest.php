<?php

namespace LaravelAIEngine\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Tests\UnitTestCase;

class InfrastructureHealthCommandTest extends UnitTestCase
{
    public function test_json_output_contains_health_report_shape(): void
    {
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.enabled', false);
        config()->set('ai-engine.infrastructure.qdrant_self_check.enabled', false);

        $exitCode = Artisan::call('ai-engine:infra-health', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('ready', $payload);
        $this->assertArrayHasKey('checks', $payload);
        $this->assertArrayHasKey('remote_node_migrations', $payload['checks']);
    }

    public function test_fail_on_unhealthy_returns_failure_exit_code(): void
    {
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.enabled', true);
        config()->set('ai-engine.infrastructure.remote_node_migration_guard.required_tables', ['__missing_infra_table__']);
        config()->set('ai-engine.infrastructure.qdrant_self_check.enabled', false);

        $exitCode = Artisan::call('ai-engine:infra-health', ['--fail-on-unhealthy' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('remote_node_migrations', Artisan::output());
    }
}
