<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\DecisionPolicyService;
use LaravelAIEngine\Services\Agent\FollowUpDecisionAIService;
use LaravelAIEngine\Services\Agent\FollowUpStateService;
use LaravelAIEngine\Services\Agent\IntentClassifierService;
use LaravelAIEngine\Services\Agent\MinimalAIOrchestrator;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

class MinimalAIOrchestratorFollowUpGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $logManager = Mockery::mock();
        $aiEngineLogger = Mockery::mock();

        $aiEngineLogger->shouldReceive('info')->andReturnNull();
        $aiEngineLogger->shouldReceive('debug')->andReturnNull();
        $aiEngineLogger->shouldReceive('warning')->andReturnNull();
        $aiEngineLogger->shouldReceive('error')->andReturnNull();
        $logManager->shouldReceive('channel')
            ->with('ai-engine')
            ->andReturn($aiEngineLogger);

        $app->instance('log', $logManager);
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::setFacadeApplication(null);
        Mockery::close();
        parent::tearDown();
    }

    public function test_apply_follow_up_decision_guard_switches_search_rag_to_conversational(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $context = $this->makeContextWithEntityList();
        $decision = [
            'action' => 'search_rag',
            'resource_name' => 'none',
            'reasoning' => 'default',
        ];

        $updated = $this->callProtected(
            $orchestrator,
            'applyFollowUpDecisionGuard',
            [$decision, 'what is the total amount?', $context, ['followup_guard_classification' => 'FOLLOW_UP_ANSWER']]
        );

        $this->assertSame('conversational', $updated['action']);
        $this->assertNull($updated['resource_name']);
    }

    public function test_apply_follow_up_decision_guard_keeps_search_for_explicit_lookup(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $context = $this->makeContextWithEntityList();
        $decision = [
            'action' => 'search_rag',
            'resource_name' => 'none',
            'reasoning' => 'default',
        ];

        $updated = $this->callProtected(
            $orchestrator,
            'applyFollowUpDecisionGuard',
            [$decision, 'show invoice 2', $context, ['followup_guard_classification' => 'ENTITY_LOOKUP']]
        );

        $this->assertSame('search_rag', $updated['action']);
    }

    public function test_should_handle_entity_lookup_by_code_uses_followup_decision_service(): void
    {
        $followUpDecision = Mockery::mock(FollowUpDecisionAIService::class);
        $followUpDecision->shouldReceive('isEntityLookupClassification')
            ->once()
            ->with('LOOKUP_CONTEXT')
            ->andReturn(true);

        $orchestrator = $this->makeOrchestrator($followUpDecision);

        $result = $this->callProtected(
            $orchestrator,
            'shouldHandleEntityLookupByCode',
            ['LOOKUP_CONTEXT']
        );

        $this->assertTrue($result);
    }

    public function test_should_handle_entity_lookup_by_code_returns_false_for_empty_classification(): void
    {
        $orchestrator = $this->makeOrchestrator();

        $result = $this->callProtected(
            $orchestrator,
            'shouldHandleEntityLookupByCode',
            [null]
        );

        $this->assertFalse($result);
    }

    protected function makeOrchestrator(?FollowUpDecisionAIService $followUpDecisionAIService = null): MinimalAIOrchestrator
    {
        return new MinimalAIOrchestrator(
            Mockery::mock(AIEngineService::class),
            Mockery::mock(ContextManager::class),
            Mockery::mock(AutonomousCollectorRegistry::class),
            Mockery::mock(NodeRegistryService::class),
            new IntentClassifierService(),
            new DecisionPolicyService(),
            new FollowUpStateService(),
            null,
            null,
            null,
            null,
            null,
            null,
            $followUpDecisionAIService
        );
    }

    protected function makeContextWithEntityList(): UnifiedActionContext
    {
        $context = new UnifiedActionContext('session-1', 1);
        $context->metadata['last_entity_list'] = [
            'entity_ids' => [11, 12, 13],
            'entity_type' => 'invoice',
        ];

        return $context;
    }

    protected function callProtected(object $instance, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }
}
