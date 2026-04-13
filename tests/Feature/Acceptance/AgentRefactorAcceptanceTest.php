<?php

namespace LaravelAIEngine\Tests\Feature\Acceptance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentExecutionFacade;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\AgentOrchestrator;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\Node\NodeOwnershipResolver;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\RAG\AutonomousRAGAgent;
use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService;
use LaravelAIEngine\Services\RAG\AutonomousRAGExecutionService;
use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;
use LaravelAIEngine\Services\RAG\AutonomousRAGStateService;
use LaravelAIEngine\Services\RAG\AutonomousRAGStructuredDataService;
use LaravelAIEngine\Tests\Models\User;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentRefactorAcceptanceTest extends UnitTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('ai-engine.user_model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->json('entity_credits')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('acceptance_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('number');
            $table->decimal('total', 10, 2);
            $table->string('status')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function test_total_orders_within_last_12_months_uses_scoped_aggregate(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();

        AcceptanceOrder::query()->create([
            'user_id' => $user->id,
            'number' => 'ORD-100',
            'total' => 120.00,
            'status' => 'paid',
            'created_at' => '2025-06-15 10:00:00',
            'updated_at' => '2025-06-15 10:00:00',
        ]);
        AcceptanceOrder::query()->create([
            'user_id' => $user->id,
            'number' => 'ORD-101',
            'total' => 90.00,
            'status' => 'open',
            'created_at' => '2025-12-03 10:00:00',
            'updated_at' => '2025-12-03 10:00:00',
        ]);
        AcceptanceOrder::query()->create([
            'user_id' => $user->id,
            'number' => 'ORD-099',
            'total' => 80.00,
            'status' => 'paid',
            'created_at' => '2024-01-10 10:00:00',
            'updated_at' => '2024-01-10 10:00:00',
        ]);
        AcceptanceOrder::query()->create([
            'user_id' => $otherUser->id,
            'number' => 'ORD-999',
            'total' => 999.00,
            'status' => 'paid',
            'created_at' => '2025-07-01 10:00:00',
            'updated_at' => '2025-07-01 10:00:00',
        ]);

        $result = $this->structuredDataService()->aggregate([
            'model' => 'order',
            'filters' => [
                'created_at' => [
                    'gte' => '2025-03-02',
                    'lte' => '2026-03-02',
                ],
            ],
            'aggregate' => [
                'operation' => 'count',
                'field' => 'id',
            ],
        ], $user->id, [], $this->orderDependencies());

        $this->assertTrue($result['success']);
        $this->assertSame('db_aggregate', $result['tool']);
        $this->assertSame(2, $result['result']);
        $this->assertStringContainsString('2.00', $result['response']);
        $this->assertStringNotContainsString('$2.00', $result['response']);
    }

    public function test_revenue_by_month_last_year_returns_grouped_summary_with_limits(): void
    {
        $user = $this->createUser();

        foreach (range(1, 12) as $month) {
            AcceptanceOrder::query()->create([
                'user_id' => $user->id,
                'number' => sprintf('ORD-%03d', $month),
                'total' => $month * 10,
                'status' => 'paid',
                'created_at' => sprintf('2025-%02d-15 10:00:00', $month),
                'updated_at' => sprintf('2025-%02d-15 10:00:00', $month),
            ]);
        }

        $result = $this->structuredDataService()->aggregate([
            'model' => 'order',
            'filters' => [
                'created_at' => [
                    'gte' => '2025-01-01',
                    'lte' => '2025-12-31',
                ],
            ],
            'aggregate' => [
                'operation' => 'sum',
                'field' => 'total',
                'group_by' => 'month',
            ],
        ], $user->id, [], $this->orderDependencies());

        $this->assertTrue($result['success']);
        $this->assertSame('month', $result['group_by']);
        $this->assertCount(10, $result['groups']);
        $this->assertTrue($result['has_more_groups']);
        $this->assertSame('2025-01', $result['groups'][0]['bucket']);
        $this->assertSame(10.0, $result['groups'][0]['value']);
        $this->assertStringContainsString('Showing first 10 groups due to policy limits.', $result['response']);
    }

    public function test_find_order_by_id_returns_exact_record_without_list_expansion(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();

        AcceptanceOrder::query()->create([
            'id' => 123,
            'user_id' => $user->id,
            'number' => 'ORD-123',
            'total' => 310.50,
            'status' => 'overdue',
            'created_at' => '2025-10-10 10:00:00',
            'updated_at' => '2025-10-10 10:00:00',
        ]);
        AcceptanceOrder::query()->create([
            'id' => 124,
            'user_id' => $user->id,
            'number' => 'ORD-124',
            'total' => 99.99,
            'status' => 'paid',
            'created_at' => '2025-10-11 10:00:00',
            'updated_at' => '2025-10-11 10:00:00',
        ]);
        AcceptanceOrder::query()->create([
            'id' => 125,
            'user_id' => $otherUser->id,
            'number' => 'ORD-125',
            'total' => 999.99,
            'status' => 'paid',
            'created_at' => '2025-10-12 10:00:00',
            'updated_at' => '2025-10-12 10:00:00',
        ]);

        $result = $this->structuredDataService()->query([
            'model' => 'order',
            'filters' => ['id' => 123],
        ], $user->id, ['session_id' => 'acceptance-order-lookup'], $this->orderDependencies());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['count']);
        $this->assertStringContainsString('Order ORD-123', $result['response']);
        $this->assertStringContainsString('overdue', $result['response']);
        $this->assertStringNotContainsString('**orders**', $result['response']);
        $this->assertStringNotContainsString('ORD-124', $result['response']);
    }

    public function test_refund_policy_lookup_uses_vector_search_path(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generateText')->once()->andReturn(
            AIResponse::success(
                '{"tool":"vector_search","reasoning":"Policy questions should use RAG","parameters":{"model":"policy_document","query":"refund policy","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $decisionService = new AutonomousRAGDecisionService($ai, new AutonomousRAGPolicy());
        $decision = $decisionService->decide('what is the refund policy?', [
            'conversation' => 'Customer asked about refund terms',
            'models' => [
                ['name' => 'policy_document', 'description' => 'Policy knowledge base', 'schema' => [], 'tools' => []],
            ],
            'nodes' => [],
            'last_entity_list' => null,
            'selected_entity' => null,
        ], 'gpt-4o-mini');

        $executed = (new AutonomousRAGExecutionService())->execute($decision, [
            'vector_search' => fn (array $plan) => [
                'success' => true,
                'tool' => $plan['tool'],
                'response' => 'Refunds are allowed within 30 days for unused items with approval.',
                'sources' => [
                    ['content' => 'Refund Policy: Refunds are allowed within 30 days for unused items with approval.'],
                ],
            ],
        ]);

        $this->assertSame('vector_search', $decision['tool']);
        $this->assertSame('refund policy', $decision['parameters']['query']);
        $this->assertSame('vector_search', $executed['tool']);
        $this->assertNotEmpty($executed['sources']);
        $this->assertStringContainsString('30 days', $executed['response']);
    }

    public function test_cross_app_ownership_routing_is_deterministic_and_workers_do_not_reroute(): void
    {
        $billing = new AINode();
        $billing->slug = 'billing';
        $payments = new AINode();
        $payments->slug = 'payments';

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('findNodeForCollection')
            ->willReturnCallback(function (string $candidate) use ($billing, $payments) {
                return match ($candidate) {
                    'invoice', 'invoices' => $billing,
                    'payment', 'payments' => $payments,
                    default => null,
                };
            });

        $resolver = new NodeOwnershipResolver($registry);

        $this->assertSame($billing, $resolver->resolveForCollection('invoice'));
        $this->assertSame($payments, $resolver->resolveForCollection('payment'));

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(
                "ACTION: route_to_node\nRESOURCE: payments\nREASON: payment data belongs there",
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            ));

        $forwardedRegistry = Mockery::mock(NodeRegistryService::class);
        $forwardedRegistry->shouldReceive('getActiveNodes')->never();

        $decision = (new IntentRouter($ai, $forwardedRegistry, new SelectedEntityContextService()))->route(
            'was it paid?',
            new UnifiedActionContext('acceptance-forwarded', null),
            ['is_forwarded' => true]
        );

        $this->assertSame('search_rag', $decision['action']);
        $this->assertNull($decision['resource_name']);
        $this->assertStringContainsString('forwarded request cannot re-route nodes', $decision['reasoning']);
    }

    public function test_follow_up_without_relisting_uses_existing_visible_context(): void
    {
        Cache::forget('agent_context:acceptance-follow-up');

        $context = new UnifiedActionContext(
            sessionId: 'acceptance-follow-up',
            userId: null,
            conversationHistory: [
                [
                    'role' => 'assistant',
                    'content' => "1. Invoice INV-42\n2. Invoice INV-43",
                    'metadata' => [
                        'entity_ids' => [42, 43],
                        'entity_type' => 'invoice',
                    ],
                ],
            ],
            metadata: [
                'selected_entity_context' => [
                    'entity_id' => 42,
                    'entity_type' => 'invoice',
                    'model_class' => 'App\\Models\\Invoice',
                ],
            ]
        );
        $context->persist();

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldReceive('route')->once()->andReturn([
            'action' => 'search_rag',
            'resource_name' => null,
            'reasoning' => 'Follow-up about selected invoice',
        ]);

        $ragAgent = Mockery::mock(AutonomousRAGAgent::class);
        $ragAgent->shouldReceive('process')
            ->once()
            ->withArgs(function (string $message, string $sessionId, $userId, array $history, array $options) {
                return $message === 'which one is overdue?'
                    && $sessionId === 'acceptance-follow-up'
                    && ($options['selected_entity']['entity_id'] ?? null) === 42;
            })
            ->andReturn([
                'success' => true,
                'response' => 'Invoice INV-42 is overdue by 3 days.',
                'metadata' => [],
                'tool' => 'invoice_lookup',
                'fast_path' => true,
            ]);
        $this->app->instance(AutonomousRAGAgent::class, $ragAgent);

        $execution = new AgentExecutionFacade(
            $this->app->make(AgentActionExecutionService::class),
            $this->app->make(AgentConversationService::class),
            Mockery::mock(NodeSessionManager::class),
            Mockery::mock(AutonomousCollectorRegistry::class),
            Mockery::mock(AutonomousCollectorHandler::class)
        );

        $orchestrator = new AgentOrchestrator(
            new ContextManager(),
            $intentRouter,
            new AgentPlanner(),
            new AgentResponseFinalizer(new ContextManager()),
            new AgentSelectionService(new AgentResponseFinalizer(new ContextManager())),
            $execution
        );

        $response = $orchestrator->process('which one is overdue?', 'acceptance-follow-up', null);

        $this->assertSame('Invoice INV-42 is overdue by 3 days.', $response->message);
    }

    protected function structuredDataService(): AutonomousRAGStructuredDataService
    {
        return new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy()
        );
    }

    protected function createUser(): User
    {
        $user = new User();
        $user->fill([
            'name' => 'Acceptance User',
            'email' => 'acceptance-' . uniqid('', true) . '@example.com',
            'password' => bcrypt('password'),
            'entity_credits' => json_encode([]),
        ]);
        $user->save();

        return $user;
    }

    protected function orderDependencies(): array
    {
        return [
            'findModelClass' => fn (string $modelName, array $options) => $modelName === 'order' ? AcceptanceOrder::class : null,
            'getFilterConfigForModel' => fn (string $modelClass) => [
                'user_field' => 'user_id',
                'date_field' => 'created_at',
                'amount_field' => 'total',
            ],
            'applyFilters' => function (Builder $query, array $filters) {
                foreach ($filters as $field => $value) {
                    if (is_array($value)) {
                        if (array_key_exists('gte', $value)) {
                            $query->where($field, '>=', $value['gte']);
                        }
                        if (array_key_exists('lte', $value)) {
                            $query->where($field, '<=', $value['lte']);
                        }
                        continue;
                    }

                    $query->where($field, $value);
                }

                return $query;
            },
            'findModelConfigClass' => fn (string $modelClass) => null,
        ];
    }
}

class AcceptanceOrder extends Model
{
    protected $table = 'acceptance_orders';

    public $timestamps = false;

    protected $guarded = [];

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function toRAGContent(): string
    {
        return sprintf(
            'Order %s (#%d) is %s with total $%0.2f.',
            $this->number,
            $this->id,
            $this->status,
            $this->total,
        );
    }

    public function toRAGSummary(): string
    {
        return sprintf('%s (%s)', $this->number, $this->status);
    }
}
