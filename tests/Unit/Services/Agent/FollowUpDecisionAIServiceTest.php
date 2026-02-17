<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Agent\DecisionPolicyService;
use LaravelAIEngine\Services\Agent\FollowUpDecisionAIService;
use LaravelAIEngine\Services\Agent\FollowUpStateService;
use LaravelAIEngine\Services\Agent\IntentClassifierService;
use LaravelAIEngine\Services\AIEngineService;
use Mockery;
use PHPUnit\Framework\TestCase;

class FollowUpDecisionAIServiceTest extends TestCase
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

    public function test_classify_returns_follow_up_answer_with_rule_fallback_when_disabled(): void
    {
        $service = $this->makeService(
            Mockery::mock(AIEngineService::class),
            ['enabled' => false]
        );

        $classification = $service->classify('what is the total amount?', $this->makeContextWithEntityList());

        $this->assertSame(FollowUpDecisionAIService::CLASS_FOLLOW_UP_ANSWER, $classification);
    }

    public function test_classify_returns_refresh_list_with_rule_fallback_when_disabled(): void
    {
        $service = $this->makeService(
            Mockery::mock(AIEngineService::class),
            ['enabled' => false]
        );

        $classification = $service->classify('list invoices again', $this->makeContextWithEntityList());

        $this->assertSame(FollowUpDecisionAIService::CLASS_REFRESH_LIST, $classification);
    }

    public function test_classify_uses_ai_response_when_enabled(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse("CLASSIFICATION: FOLLOW_UP_ANSWER\n"));

        $service = $this->makeService($ai, [
            'enabled' => true,
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'max_tokens' => 32,
            'temperature' => 0.0,
        ]);

        $classification = $service->classify('what about the due ones?', $this->makeContextWithEntityList());

        $this->assertSame(FollowUpDecisionAIService::CLASS_FOLLOW_UP_ANSWER, $classification);
    }

    public function test_classify_supports_configured_protocol_labels(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse("LABEL: ANSWER_CONTEXT\n"));

        $service = $this->makeService($ai, [
            'enabled' => true,
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'protocol' => [
                'response_key' => 'LABEL',
                'classes' => [
                    'follow_up_answer' => 'ANSWER_CONTEXT',
                    'refresh_list' => 'REFRESH_CONTEXT',
                    'entity_lookup' => 'ENTITY_CONTEXT',
                    'new_query' => 'NEW_CONTEXT',
                    'unknown' => 'UNKNOWN_CONTEXT',
                ],
            ],
        ]);

        $classification = $service->classify('what about item 2?', $this->makeContextWithEntityList());
        $this->assertSame('ANSWER_CONTEXT', $classification);

        $updated = $service->applyGuard(
            ['action' => 'search_rag', 'resource_name' => 'none'],
            'what about item 2?',
            $this->makeContextWithEntityList(),
            ['followup_guard_classification' => 'ANSWER_CONTEXT']
        );
        $this->assertSame('conversational', $updated['action']);
    }

    public function test_apply_guard_replaces_search_rag_with_conversational_for_follow_up(): void
    {
        $service = $this->makeService(
            Mockery::mock(AIEngineService::class),
            ['enabled' => false]
        );
        $decision = [
            'action' => 'search_rag',
            'resource_name' => 'none',
            'reasoning' => 'default',
        ];

        $updated = $service->applyGuard(
            $decision,
            'what is the total amount?',
            $this->makeContextWithEntityList()
        );

        $this->assertSame('conversational', $updated['action']);
        $this->assertNull($updated['resource_name']);
    }

    public function test_apply_guard_keeps_search_rag_for_refresh_request(): void
    {
        $service = $this->makeService(
            Mockery::mock(AIEngineService::class),
            ['enabled' => false]
        );
        $decision = [
            'action' => 'search_rag',
            'resource_name' => 'none',
            'reasoning' => 'default',
        ];

        $updated = $service->applyGuard(
            $decision,
            'list invoices again',
            $this->makeContextWithEntityList()
        );

        $this->assertSame('search_rag', $updated['action']);
    }

    public function test_entity_lookup_classification_check_uses_default_label(): void
    {
        $service = $this->makeService(
            Mockery::mock(AIEngineService::class),
            ['enabled' => false]
        );

        $this->assertTrue($service->isEntityLookupClassification('ENTITY_LOOKUP'));
        $this->assertFalse($service->isEntityLookupClassification('FOLLOW_UP_ANSWER'));
    }

    public function test_entity_lookup_classification_check_supports_custom_protocol_labels(): void
    {
        $service = $this->makeService(
            Mockery::mock(AIEngineService::class),
            [
                'enabled' => false,
                'protocol' => [
                    'classes' => [
                        'entity_lookup' => 'LOOKUP_CONTEXT',
                    ],
                ],
            ]
        );

        $this->assertTrue($service->isEntityLookupClassification('LOOKUP_CONTEXT'));
        $this->assertFalse($service->isEntityLookupClassification('ENTITY_LOOKUP'));
    }

    public function test_classify_returns_unknown_when_ai_output_unknown_and_rules_fallback_disabled(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse("CLASSIFICATION: UNKNOWN\n"));

        $service = $this->makeService($ai, [
            'enabled' => true,
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'rules_fallback_on_ai_failure' => false,
        ]);

        $classification = $service->classify('what is the total amount?', $this->makeContextWithEntityList());
        $this->assertSame(FollowUpDecisionAIService::CLASS_UNKNOWN, $classification);
    }

    public function test_classify_uses_rules_when_ai_output_unknown_and_rules_fallback_enabled(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse("CLASSIFICATION: UNKNOWN\n"));

        $service = $this->makeService($ai, [
            'enabled' => true,
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'rules_fallback_on_ai_failure' => true,
        ]);

        $classification = $service->classify('what is the total amount?', $this->makeContextWithEntityList());
        $this->assertSame(FollowUpDecisionAIService::CLASS_FOLLOW_UP_ANSWER, $classification);
    }

    protected function makeService(AIEngineService $ai, array $settings = []): FollowUpDecisionAIService
    {
        return new FollowUpDecisionAIService(
            $ai,
            new IntentClassifierService($this->intentConfig()),
            new DecisionPolicyService(),
            new FollowUpStateService(),
            $settings
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

    protected function makeAIResponse(string $content): AIResponse
    {
        return AIResponse::success(
            $content,
            EngineEnum::from('openai'),
            EntityEnum::from('gpt-4o-mini')
        );
    }

    protected function intentConfig(): array
    {
        return [
            'list_verbs' => ['list', 'show', 'display', 'search', 'find', 'fetch', 'retrieve', 'refresh', 'relist'],
            'refresh_words' => ['again', 'reload'],
            'record_terms' => ['invoices', 'emails', 'items', 'records'],
            'entity_terms' => ['invoice', 'email', 'item', 'record', 'entry', 'customer', 'product'],
            'followup_keywords' => [
                'what', 'which', 'who', 'when', 'where', 'why', 'how',
                'total', 'sum', 'count', 'average', 'status', 'due',
                'paid', 'unpaid', 'latest', 'earliest',
            ],
            'followup_pronouns' => ['it', 'its', 'them', 'those', 'these', 'that', 'this', 'ones'],
            'ordinal_words' => ['first', 'second', 'third', 'fourth', 'fifth', '1st', '2nd', '3rd', '4th', '5th'],
            'ordinal_map' => [
                'first' => 1,
                'second' => 2,
                'third' => 3,
                'fourth' => 4,
                'fifth' => 5,
                '1st' => 1,
                '2nd' => 2,
                '3rd' => 3,
                '4th' => 4,
                '5th' => 5,
            ],
            'positional_entity_words' => ['item', 'email', 'invoice', 'entry', 'record'],
            'max_positional_index' => 100,
            'max_option_selection' => 10,
        ];
    }
}
