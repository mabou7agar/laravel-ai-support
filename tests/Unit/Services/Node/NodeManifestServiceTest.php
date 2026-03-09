<?php

namespace LaravelAIEngine\Tests\Unit\Services\Node;

use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Services\Node\NodeManifestService;
use LaravelAIEngine\Services\Node\NodeMetadataDiscovery;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;
use Mockery;
use Orchestra\Testbench\TestCase;

class NodeManifestServiceTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.name', 'Billing');
        $app['config']->set('app.url', 'https://billing.test');
        $app['config']->set('ai-engine.version', '2.0.0');
        $app['config']->set('ai-engine.nodes.slug', 'billing');
    }

    public function test_manifest_normalizes_metadata_into_stable_contract(): void
    {
        $metadata = Mockery::mock(NodeMetadataDiscovery::class);
        $metadata->shouldReceive('discover')->once()->andReturn([
            'description' => 'Handles invoices',
            'capabilities' => ['search', 'tools'],
            'domains' => ['billing'],
            'data_types' => ['invoice'],
            'keywords' => ['invoice', 'invoices'],
            'collections' => [
                [
                    'name' => 'invoice',
                    'class' => 'App\\Models\\Invoice',
                    'table' => 'invoices',
                    'description' => 'Invoice records',
                    'capabilities' => ['db_query' => true],
                ],
            ],
            'workflows' => ['App\\AI\\Workflows\\CreateInvoiceWorkflow'],
        ]);

        $collectors = Mockery::mock(AutonomousCollectorDiscoveryService::class);
        $collectors->shouldReceive('discoverCollectors')->once()->with(false, false)->andReturn([
            'create_invoice' => [
                'name' => 'create_invoice',
                'goal' => 'Create invoice',
                'description' => 'Collect invoice data',
            ],
        ]);

        $service = new NodeManifestService($metadata, $collectors);
        $manifest = $service->manifest();

        $this->assertSame('billing', $manifest['node']['slug']);
        $this->assertSame('Billing', $manifest['node']['name']);
        $this->assertSame('https://billing.test', $manifest['node']['url']);
        $this->assertSame('App\\Models\\Invoice', $manifest['collections'][0]['class']);
        $this->assertSame('Invoice', $manifest['collections'][0]['display_name']);
        $this->assertSame(['invoice'], $manifest['ownership']['collections']);
        $this->assertSame(['create_invoice'], $manifest['ownership']['tools']);
        $this->assertSame('jwt', $manifest['auth']['scheme']);
    }

    public function test_health_reflects_infrastructure_report(): void
    {
        $metadata = Mockery::mock(NodeMetadataDiscovery::class);
        $collectors = Mockery::mock(AutonomousCollectorDiscoveryService::class);
        $infra = Mockery::mock(InfrastructureHealthService::class);

        $infra->shouldReceive('evaluate')->once()->andReturn([
            'status' => 'degraded',
            'ready' => false,
            'checks' => [
                'remote_node_migrations' => [
                    'healthy' => false,
                    'message' => 'Missing required tables: ai_conversations',
                ],
            ],
        ]);

        $service = new NodeManifestService($metadata, $collectors, $infra);
        $health = $service->health();

        $this->assertSame('degraded', $health['status']);
        $this->assertFalse($health['ready']);
        $this->assertSame(false, $health['checks']['remote_node_migrations']['healthy']);
    }
}
