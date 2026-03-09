<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\Services\EngineBuilder;
use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AutonomousRAGDecisionServiceTest extends UnitTestCase
{
    public function test_decide_parses_json_response(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"List invoices","parameters":{"model":"invoice","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('list invoices', $this->sampleContext(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_decide_falls_back_to_aggregate_when_response_is_unstructured(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                'Use db_aggregate for this total request',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('what is the total invoice amount', $this->sampleContext(), 'gpt-4o-mini');

        $this->assertSame('db_aggregate', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_decide_fallback_targets_selected_entity_before_relisting(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                'Use the currently selected record context.',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide(
            'what is its status?',
            $this->sampleContext([
                'selected_entity' => [
                    'entity_id' => 42,
                    'entity_type' => 'invoice',
                ],
            ]),
            'gpt-4o-mini'
        );

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
        $this->assertSame(42, $decision['parameters']['filters']['id']);
    }

    public function test_decide_fallback_uses_vector_search_for_follow_up_on_visible_list(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                'I cannot format this as JSON right now.',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide(
            'should I reply to this?',
            $this->sampleContext([
                'last_entity_list' => [
                    'entity_type' => 'emailcache',
                    'entity_ids' => [11, 12],
                    'entity_data' => [['id' => 11], ['id' => 12]],
                    'start_position' => 1,
                    'end_position' => 2,
                ],
            ]),
            'gpt-4o-mini'
        );

        $this->assertSame('vector_search', $decision['tool']);
        $this->assertSame('emailcache', $decision['parameters']['model']);
    }

    public function test_decide_normalizes_table_parameter_to_model_name(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"List invoices","parameters":{"table":"invoices","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('list invoices', $this->sampleContext(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
        $this->assertArrayNotHasKey('table', $decision['parameters']);
    }

    public function test_decide_prefers_explicit_arabic_invoice_alias_over_ai_selected_bill(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"List financial docs","parameters":{"model":"bill","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('هل يوجد لدي فواتير؟', $this->sampleContextWithBillAndInvoice(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_decide_fallback_infers_invoice_from_arabic_alias_when_models_are_ambiguous(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                'not valid json',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('هل يوجد لدي فواتير؟', $this->sampleContextWithBillAndInvoice(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_decide_ai_first_mode_does_not_force_lexicon_model_override(): void
    {
        config()->set('ai-engine.intelligent_rag.decision.language_mode', 'ai_first');

        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"List docs","parameters":{"model":"bill","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('هل يوجد لدي فواتير؟', $this->sampleContextWithBillAndInvoice(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('bill', $decision['parameters']['model']);
    }

    public function test_decide_injects_user_scope_filter_for_possessive_list_request(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"List invoices","parameters":{"model":"invoice","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('list my invoices', $this->sampleContext([
            'user_id' => 77,
        ]), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame(77, $decision['parameters']['filters']['user_id']);
    }

    public function test_decide_replaces_current_user_placeholder_with_configured_user_field(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"List invoices","parameters":{"model":"invoice","filters":{"user_id":"current_user_id"},"limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('هل يوجد لدي فواتير؟', $this->sampleContext([
            'user_id' => 903,
            'models' => [
                [
                    'name' => 'invoice',
                    'description' => 'Invoice model',
                    'table' => 'invoices',
                    'schema' => ['id' => 'int', 'user_id' => 'int', 'created_by' => 'int'],
                    'filter_config' => ['user_field' => 'created_by'],
                ],
            ],
        ]), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame(903, $decision['parameters']['filters']['created_by']);
        $this->assertArrayNotHasKey('user_id', $decision['parameters']['filters']);
    }

    public function test_decide_injects_relation_filter_for_selected_related_entity(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"List invoices for selected user","parameters":{"model":"invoice","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('list invoices for this user', $this->sampleContext([
            'selected_entity' => [
                'entity_id' => 9,
                'entity_type' => 'User',
            ],
        ]), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame(9, $decision['parameters']['filters']['user_id']);
    }

    public function test_decide_resolves_visible_list_ordinal_follow_up_to_single_record(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"Fetch invoices","parameters":{"model":"invoice","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('show second invoice status', $this->sampleContext([
            'last_entity_list' => [
                'entity_type' => 'invoice',
                'entity_ids' => [501, 502, 503],
                'start_position' => 1,
            ],
        ]), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame(502, $decision['parameters']['filters']['id']);
        $this->assertSame(1, $decision['parameters']['limit']);
    }

    public function test_decide_follow_up_without_selection_uses_vector_search_instead_of_relisting(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"Fetch invoices","parameters":{"model":"invoice","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('which one is overdue?', $this->sampleContext([
            'last_entity_list' => [
                'entity_type' => 'invoice',
                'entity_ids' => [501, 502, 503],
                'start_position' => 1,
            ],
        ]), 'gpt-4o-mini');

        $this->assertSame('vector_search', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
        $this->assertSame('which one is overdue?', $decision['parameters']['query']);
    }

    public function test_decide_preserves_list_request_for_show_plural_without_forcing_selected_id(): void
    {
        $builder = Mockery::mock(EngineBuilder::class);
        $builder->shouldReceive('withTemperature')->once()->with(0.1)->andReturnSelf();
        $builder->shouldReceive('withMaxTokens')->once()->with(1000)->andReturnSelf();
        $builder->shouldReceive('generate')->once()->andReturn(
            AIResponse::success(
                '{"tool":"db_query","reasoning":"List invoices","parameters":{"model":"invoice","limit":10}}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            )
        );

        $ai = Mockery::mock(AIEngineManager::class);
        $ai->shouldReceive('model')->once()->with('gpt-4o-mini')->andReturn($builder);

        $service = new AutonomousRAGDecisionService($ai);
        $decision = $service->decide('show invoices', $this->sampleContext([
            'selected_entity' => [
                'entity_id' => 42,
                'entity_type' => 'invoice',
            ],
        ]), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertArrayNotHasKey('id', $decision['parameters']['filters'] ?? []);
    }

    protected function sampleContext(array $overrides = []): array
    {
        return array_merge([
            'conversation' => 'Recent conversation',
            'models' => [
                [
                    'name' => 'invoice',
                    'description' => 'Invoice model',
                    'table' => 'invoices',
                    'schema' => ['id' => 'int', 'amount' => 'float', 'user_id' => 'int'],
                    'tools' => ['mark_as_paid' => []],
                ],
            ],
            'nodes' => [],
            'last_entity_list' => null,
            'selected_entity' => null,
        ], $overrides);
    }

    protected function sampleContextWithBillAndInvoice(array $overrides = []): array
    {
        return array_merge([
            'conversation' => 'Recent conversation',
            'models' => [
                [
                    'name' => 'bill',
                    'description' => 'Bill model',
                    'table' => 'bills',
                    'schema' => ['id' => 'int', 'status' => 'int', 'created_by' => 'int'],
                    'tools' => [],
                ],
                [
                    'name' => 'invoice',
                    'description' => 'Invoice model',
                    'table' => 'invoices',
                    'schema' => ['id' => 'int', 'status' => 'int', 'created_by' => 'int'],
                    'tools' => [],
                ],
            ],
            'nodes' => [],
            'last_entity_list' => null,
            'selected_entity' => null,
        ], $overrides);
    }
}
