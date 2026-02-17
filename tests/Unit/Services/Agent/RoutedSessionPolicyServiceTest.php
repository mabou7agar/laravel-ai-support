<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Agent\RoutedSessionPolicyService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use Mockery;
use PHPUnit\Framework\TestCase;

class RoutedSessionPolicyServiceTest extends TestCase
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

    public function test_returns_false_when_no_routed_node_in_context(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $registry = Mockery::mock(NodeRegistryService::class);
        $service = $this->makeService($ai, $registry);

        $result = $service->shouldContinue('show invoices', new UnifiedActionContext('session-1', 1));

        $this->assertFalse($result);
    }

    public function test_returns_false_when_ai_classifies_unrelated_message(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $registry = Mockery::mock(NodeRegistryService::class);
        $service = $this->makeService($ai, $registry);
        $context = new UnifiedActionContext('session-2', 1);
        $context->set('routed_to_node', ['node_slug' => 'billing-node']);

        $registry->shouldReceive('getNode')
            ->once()
            ->with('billing-node')
            ->andReturn(new AINode([
                'slug' => 'billing-node',
                'name' => 'Billing Node',
                'collections' => ['invoices', 'customers'],
            ]));
        $registry->shouldReceive('getActiveNodes')
            ->andReturn(collect([]));
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse('DIFFERENT'));

        $this->assertFalse($service->shouldContinue('list emails', $context));
    }

    public function test_returns_true_when_ai_classifies_message_as_related(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $registry = Mockery::mock(NodeRegistryService::class);
        $service = $this->makeService($ai, $registry, [
            'explicit_topic_checks' => [],
        ]);
        $context = new UnifiedActionContext('session-3', 1, [
            ['role' => 'user', 'content' => 'list invoices'],
            ['role' => 'assistant', 'content' => 'invoice list'],
        ]);
        $context->set('routed_to_node', ['node_slug' => 'billing-node']);

        $registry->shouldReceive('getNode')
            ->once()
            ->with('billing-node')
            ->andReturn(new AINode([
                'slug' => 'billing-node',
                'name' => 'Billing Node',
                'collections' => ['invoices'],
            ]));
        $registry->shouldReceive('getActiveNodes')
            ->andReturn(collect([]));
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse('RELATED'));

        $this->assertTrue($service->shouldContinue('show invoice 10', $context));
    }

    public function test_returns_false_when_ai_classifies_message_as_different(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $registry = Mockery::mock(NodeRegistryService::class);
        $service = $this->makeService($ai, $registry, [
            'explicit_topic_checks' => [],
        ]);
        $context = new UnifiedActionContext('session-4', 1);
        $context->set('routed_to_node', ['node_slug' => 'billing-node']);

        $registry->shouldReceive('getNode')
            ->once()
            ->with('billing-node')
            ->andReturn(new AINode([
                'slug' => 'billing-node',
                'name' => 'Billing Node',
                'collections' => ['invoices'],
            ]));
        $registry->shouldReceive('getActiveNodes')
            ->andReturn(collect([]));
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse('DIFFERENT'));

        $this->assertFalse($service->shouldContinue('show emails', $context));
    }

    public function test_returns_false_on_ai_error_when_fallback_continue_disabled(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $registry = Mockery::mock(NodeRegistryService::class);
        $service = $this->makeService($ai, $registry, [
            'fallback_continue_on_ai_error' => false,
        ]);
        $context = new UnifiedActionContext('session-5', 1);
        $context->set('routed_to_node', ['node_slug' => 'billing-node']);

        $registry->shouldReceive('getNode')
            ->once()
            ->with('billing-node')
            ->andReturn(new AINode([
                'slug' => 'billing-node',
                'name' => 'Billing Node',
                'collections' => ['invoices'],
            ]));
        $registry->shouldReceive('getActiveNodes')
            ->andReturn(collect([]));

        $ai->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('provider timeout'));

        $this->assertFalse($service->shouldContinue('show invoice 9', $context));
    }

    protected function makeService(
        AIEngineService $ai,
        NodeRegistryService $registry,
        array $settings = []
    ): RoutedSessionPolicyService {
        return new RoutedSessionPolicyService(
            $ai,
            $registry,
            array_merge([
                'history_window' => 3,
                'engine' => 'openai',
                'model' => 'gpt-4o-mini',
                'use_explicit_topic_checks' => false,
                'explicit_topic_checks' => [],
            ], $settings)
        );
    }

    protected function makeAIResponse(string $content): AIResponse
    {
        return AIResponse::success(
            $content,
            EngineEnum::from('openai'),
            EntityEnum::from('gpt-4o-mini')
        );
    }
}
