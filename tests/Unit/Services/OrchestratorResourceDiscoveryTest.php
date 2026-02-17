<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Collection;
use LaravelAIEngine\Services\Agent\OrchestratorResourceDiscovery;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use Mockery;
use Orchestra\Testbench\TestCase;

class OrchestratorResourceDiscoveryTest extends TestCase
{
    protected $mockNodeRegistry;
    protected OrchestratorResourceDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');

        $this->mockNodeRegistry = Mockery::mock(NodeRegistryService::class);
        AutonomousCollectorRegistry::clear();
        $collectorRegistry = new AutonomousCollectorRegistry();
        $this->discovery = new OrchestratorResourceDiscovery($this->mockNodeRegistry, $collectorRegistry);
    }

    protected function tearDown(): void
    {
        AutonomousCollectorRegistry::clear();
        Mockery::close();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    //  discover() returns all resource types
    // ──────────────────────────────────────────────

    public function test_discover_returns_tools_collectors_nodes(): void
    {
        $this->mockNodeRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection());

        $result = $this->discovery->discover();

        $this->assertArrayHasKey('tools', $result);
        $this->assertArrayHasKey('collectors', $result);
        $this->assertArrayHasKey('nodes', $result);
        $this->assertIsArray($result['tools']);
        $this->assertIsArray($result['collectors']);
        $this->assertIsArray($result['nodes']);
    }

    // ──────────────────────────────────────────────
    //  discoverTools: from model configs
    // ──────────────────────────────────────────────

    public function test_discover_tools_from_model_configs(): void
    {
        $configClass = $this->createMockModelConfig('Invoice', [
            'create_invoice' => ['description' => 'Create a new invoice', 'parameters' => []],
            'list_invoices' => ['description' => 'List all invoices', 'parameters' => []],
        ]);

        $tools = $this->discovery->discoverTools(['model_configs' => [$configClass]]);

        $this->assertCount(2, $tools);
        $this->assertSame('create_invoice', $tools[0]['name']);
        $this->assertSame('Invoice', $tools[0]['model']);
        $this->assertSame('Create a new invoice', $tools[0]['description']);
        $this->assertSame('list_invoices', $tools[1]['name']);
    }

    public function test_discover_tools_skips_configs_without_getTools(): void
    {
        $className = 'NoToolsConfig_' . uniqid();
        eval("class {$className} { public static function getName(): string { return 'empty'; } }");

        $tools = $this->discovery->discoverTools(['model_configs' => [$className]]);

        $this->assertEmpty($tools);
    }

    public function test_discover_tools_handles_exception_gracefully(): void
    {
        $className = 'FailingConfig_' . uniqid();
        eval("class {$className} { public static function getTools(): array { throw new \RuntimeException('fail'); } }");

        $tools = $this->discovery->discoverTools(['model_configs' => [$className]]);

        $this->assertEmpty($tools);
    }

    // ──────────────────────────────────────────────
    //  discoverCollectors: local + remote
    // ──────────────────────────────────────────────

    public function test_discover_local_collectors(): void
    {
        AutonomousCollectorRegistry::register('invoice_collector', [
            'goal' => 'Collect invoice data',
            'description' => 'Collects invoice information step by step',
        ]);

        $this->mockNodeRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection());

        $collectors = $this->discovery->discoverCollectors();

        $this->assertCount(1, $collectors);
        $this->assertSame('invoice_collector', $collectors[0]['name']);
        $this->assertSame('Collect invoice data', $collectors[0]['goal']);
    }

    public function test_discover_remote_collectors_from_nodes(): void
    {
        $this->mockNodeRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection([
            [
                'slug' => 'email-node',
                'name' => 'Email Node',
                'autonomous_collectors' => [
                    ['name' => 'send_email', 'goal' => 'Send emails', 'description' => 'Compose and send email'],
                ],
            ],
        ]));

        $collectors = $this->discovery->discoverCollectors();

        $remoteCollectors = array_filter($collectors, fn($c) => isset($c['node']));
        $this->assertCount(1, $remoteCollectors);
        $remote = array_values($remoteCollectors)[0];
        $this->assertSame('send_email', $remote['name']);
        $this->assertSame('email-node', $remote['node']);
    }

    public function test_discover_collectors_merges_local_and_remote(): void
    {
        AutonomousCollectorRegistry::register('local_collector', [
            'goal' => 'Local goal',
            'description' => 'Local description',
        ]);

        $this->mockNodeRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection([
            [
                'slug' => 'remote-node',
                'autonomous_collectors' => [
                    ['name' => 'remote_collector', 'goal' => 'Remote goal', 'description' => 'Remote desc'],
                ],
            ],
        ]));

        $collectors = $this->discovery->discoverCollectors();

        $this->assertCount(2, $collectors);
        $names = array_column($collectors, 'name');
        $this->assertContains('local_collector', $names);
        $this->assertContains('remote_collector', $names);
    }

    // ──────────────────────────────────────────────
    //  discoverNodes
    // ──────────────────────────────────────────────

    public function test_discover_nodes_from_registry(): void
    {
        $this->mockNodeRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection([
            ['slug' => 'invoicing-node', 'name' => 'Invoicing', 'description' => 'Handles invoices', 'domains' => ['billing', 'payments']],
            ['slug' => 'email-node', 'name' => 'Email', 'description' => 'Handles email', 'domains' => ['communication']],
        ]));

        $nodes = $this->discovery->discoverNodes();

        $this->assertCount(2, $nodes);
        $this->assertSame('invoicing-node', $nodes[0]['slug']);
        $this->assertSame(['billing', 'payments'], $nodes[0]['domains']);
        $this->assertSame('email-node', $nodes[1]['slug']);
    }

    public function test_discover_nodes_handles_empty_registry(): void
    {
        $this->mockNodeRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection());

        $nodes = $this->discovery->discoverNodes();

        $this->assertEmpty($nodes);
    }

    // ──────────────────────────────────────────────
    //  discoverModelConfigs: filesystem-based
    // ──────────────────────────────────────────────

    public function test_discover_model_configs_returns_empty_when_no_paths(): void
    {
        $this->app['config']->set('ai-agent.model_config_discovery', [
            'paths' => ['/nonexistent/path'],
            'namespaces' => ['App\\AI\\Configs'],
        ]);

        $configs = $this->discovery->discoverModelConfigs();

        $this->assertEmpty($configs);
    }

    // ──────────────────────────────────────────────
    //  Helper
    // ──────────────────────────────────────────────

    protected function createMockModelConfig(string $modelName, array $tools): string
    {
        $className = 'TestConfig_' . uniqid();
        $GLOBALS['_test_tools_' . $className] = $tools;
        $GLOBALS['_test_name_' . $className] = $modelName;

        eval("
            class {$className} {
                public static function getName(): string { return \$GLOBALS['_test_name_{$className}']; }
                public static function getTools(): array { return \$GLOBALS['_test_tools_{$className}']; }
            }
        ");

        return $className;
    }
}
