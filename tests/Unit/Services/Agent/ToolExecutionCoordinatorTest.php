<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\ToolExecutionCoordinator;
use Mockery;
use PHPUnit\Framework\TestCase;

class ToolExecutionCoordinatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Facade::clearResolvedInstances();

        $app = new Container();
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->andReturnSelf();
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('warning')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $app->instance('log', $logger);
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Mockery::close();
        parent::tearDown();
    }

    public function test_executes_tool_and_overrides_selected_entity_id(): void
    {
        TestToolModelConfig::$lastParams = [];

        $context = new UnifiedActionContext('session-1', 1);
        $context->metadata['suggested_actions'] = [
            [
                'tool' => 'invoice.update',
                'params' => ['invoice_id' => 3, 'status' => 'paid'],
            ],
        ];

        $coordinator = new ToolExecutionCoordinator();
        $response = $coordinator->execute(
            'invoice.update',
            'update it',
            $context,
            [TestToolModelConfig::class],
            ['entity_id' => 99]
        );

        $this->assertNotNull($response);
        $this->assertTrue($response->success);
        $this->assertSame(99, TestToolModelConfig::$lastParams['invoice_id']);
        $this->assertSame('paid', TestToolModelConfig::$lastParams['status']);
    }

    public function test_returns_null_when_tool_not_found(): void
    {
        $context = new UnifiedActionContext('session-2', 1);
        $coordinator = new ToolExecutionCoordinator();

        $response = $coordinator->execute(
            'missing.tool',
            'anything',
            $context,
            [TestToolModelConfig::class],
            null
        );

        $this->assertNull($response);
    }
}

class TestToolModelConfig
{
    public static array $lastParams = [];

    public static function getName(): string
    {
        return 'Invoice';
    }

    public static function getTools(): array
    {
        return [
            'invoice.update' => [
                'description' => 'Update invoice',
                'parameters' => [
                    'invoice_id' => 'integer',
                    'status' => 'string',
                ],
                'handler' => function (array $params) {
                    self::$lastParams = $params;

                    return [
                        'success' => true,
                        'message' => 'Updated',
                        'data' => $params,
                    ];
                },
            ],
        ];
    }
}
