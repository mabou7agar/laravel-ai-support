<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\Services\Agent\Tools\RouteToNodeTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class RouteToNodeRegistrationTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // The route_to_node tool is only registered when node federation is active;
        // enable it before the federation provider boots.
        $app['config']->set('ai-engine.nodes.enabled', true);
    }

    public function test_route_to_node_tool_is_registered_when_federation_is_active(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->has('route_to_node'), 'Federation must register the route_to_node AiNative tool when nodes are enabled.');
        $this->assertInstanceOf(RouteToNodeTool::class, $registry->get('route_to_node'));
        $this->assertArrayHasKey('node', $registry->get('route_to_node')->getParameters());
    }
}
