<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentActionExecutionServiceCollectorStub
{
    public static function getConfig(): AutonomousCollectorConfig
    {
        return new AutonomousCollectorConfig(
            goal: 'Create a stub entity',
            description: 'Stub collector for unit tests'
        );
    }
}

class AgentActionExecutionServiceTest extends UnitTestCase
{
    public function test_execute_resume_session_restores_last_paused_collector(): void
    {
        $service = new AgentActionExecutionService(
            $this->createMock(AutonomousCollectorRegistry::class),
            $this->createMock(AutonomousCollectorDiscoveryService::class),
            $this->createMock(AutonomousCollectorHandler::class),
            new SelectedEntityContextService()
        );

        $context = new UnifiedActionContext('resume-session', 1);
        $context->set('session_stack', [[
            'config_name' => 'invoice',
            'paused_at' => now()->toIso8601String(),
            'status' => 'collecting',
        ]]);

        $response = $service->executeResumeSession($context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('invoice', $context->get('autonomous_collector')['config_name']);
        $this->assertFalse($context->has('session_stack'));
    }

    public function test_execute_pause_and_handle_moves_active_collector_to_stack(): void
    {
        $service = new AgentActionExecutionService(
            $this->createMock(AutonomousCollectorRegistry::class),
            $this->createMock(AutonomousCollectorDiscoveryService::class),
            $this->createMock(AutonomousCollectorHandler::class),
            new SelectedEntityContextService()
        );

        $context = new UnifiedActionContext('pause-session', 1);
        $context->set('autonomous_collector', [
            'config_name' => 'invoice',
            'status' => 'collecting',
        ]);

        $called = false;
        $response = $service->executePauseAndHandle('list invoices', $context, [], function () use (&$called, $context) {
            $called = true;
            return \LaravelAIEngine\DTOs\AgentResponse::conversational('delegated', $context);
        });

        $this->assertTrue($called);
        $this->assertFalse($context->has('autonomous_collector'));
        $this->assertSame('invoice', $context->get('session_stack')[0]['config_name']);
        $this->assertSame('delegated', $response->message);
    }

    public function test_execute_start_collector_uses_get_config_when_present(): void
    {
        $context = new UnifiedActionContext('collector-session', 1);

        $registry = $this->createMock(AutonomousCollectorRegistry::class);
        $discovery = $this->createMock(AutonomousCollectorDiscoveryService::class);
        $handler = $this->createMock(AutonomousCollectorHandler::class);

        $discovery->expects($this->once())
            ->method('discoverCollectors')
            ->with(useCache: true, includeRemote: true)
            ->willReturn([
                'stub_collector' => [
                    'class' => AgentActionExecutionServiceCollectorStub::class,
                    'source' => 'local',
                    'description' => 'stub',
                ],
            ]);

        $handler->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function (string $message, UnifiedActionContext $receivedContext, array $options): AgentResponse {
                $this->assertSame('create stub', $message);
                $this->assertSame('start_autonomous_collector', $options['action'] ?? null);
                $this->assertInstanceOf(
                    AutonomousCollectorConfig::class,
                    $options['collector_match']['config'] ?? null
                );
                $this->assertSame(
                    'Create a stub entity',
                    $options['collector_match']['config']->goal ?? null
                );

                return AgentResponse::conversational('collector-started', $receivedContext);
            });

        $service = new AgentActionExecutionService(
            $registry,
            $discovery,
            $handler,
            new SelectedEntityContextService()
        );

        $response = $service->executeStartCollector(
            'stub_collector',
            'create stub',
            $context,
            [],
            function () {
                $this->fail('Remote routing fallback should not be used for local getConfig collector');
            }
        );

        $this->assertSame('collector-started', $response->message);
    }
}
