<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AutonomousRAGDecisionServiceTest extends UnitTestCase
{
    public function test_decide_parses_json_response(): void
    {
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"List invoices","parameters":{"model":"invoice","limit":10}}'
        ));

        $decision = $service->decide('list invoices', $this->sampleContext(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_decide_falls_back_to_aggregate_when_response_is_unstructured(): void
    {
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            'Use db_aggregate for this total request'
        ));

        $decision = $service->decide('what is the total invoice amount', $this->sampleContext(), 'gpt-4o-mini');

        $this->assertSame('db_aggregate', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_decide_fallback_targets_selected_entity_before_relisting(): void
    {
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            'Use the currently selected record context.'
        ));

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
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            'I cannot format this as JSON right now.'
        ));

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

    public function test_decide_fallback_uses_vector_search_for_semantic_question_when_json_parsing_fails(): void
    {
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            'This should search semantically but I am not returning JSON.'
        ));

        $decision = $service->decide(
            'What changed on Friday for Apollo and who is it related to?',
            $this->sampleContext([
                'models' => [
                    [
                        'name' => 'project',
                        'class' => 'App\\Models\\Project',
                        'description' => 'Project records',
                    ],
                    [
                        'name' => 'mail',
                        'class' => 'App\\Models\\Mail',
                        'description' => 'Mail records',
                    ],
                ],
            ]),
            'gpt-4o-mini'
        );

        $this->assertSame('vector_search', $decision['tool']);
        $this->assertSame('What changed on Friday for Apollo and who is it related to?', $decision['parameters']['query']);
    }

    public function test_decide_normalizes_table_parameter_to_model_name(): void
    {
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"List invoices","parameters":{"table":"invoices","limit":10}}'
        ));

        $decision = $service->decide('list invoices', $this->sampleContext(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
        $this->assertArrayNotHasKey('table', $decision['parameters']);
    }

    public function test_decide_prefers_explicit_arabic_invoice_alias_over_ai_selected_bill(): void
    {
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"List financial docs","parameters":{"model":"bill","limit":10}}'
        ));

        $decision = $service->decide('هل يوجد لدي فواتير؟', $this->sampleContextWithBillAndInvoice(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_decide_fallback_infers_invoice_from_arabic_alias_when_models_are_ambiguous(): void
    {
        $service = new AutonomousRAGDecisionService($this->mockAiResponse('not valid json'));

        $decision = $service->decide('هل يوجد لدي فواتير؟', $this->sampleContextWithBillAndInvoice(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_decide_ai_first_mode_does_not_force_lexicon_model_override(): void
    {
        config()->set('ai-engine.intelligent_rag.decision.language_mode', 'ai_first');

        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"List docs","parameters":{"model":"bill","limit":10}}'
        ));

        $decision = $service->decide('هل يوجد لدي فواتير؟', $this->sampleContextWithBillAndInvoice(), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('bill', $decision['parameters']['model']);
    }

    public function test_decide_injects_user_scope_filter_for_possessive_list_request(): void
    {
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"List invoices","parameters":{"model":"invoice","limit":10}}'
        ));

        $decision = $service->decide('list my invoices', $this->sampleContext([
            'user_id' => 77,
        ]), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame(77, $decision['parameters']['filters']['user_id']);
    }

    public function test_decide_replaces_current_user_placeholder_with_configured_user_field(): void
    {
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"List invoices","parameters":{"model":"invoice","filters":{"user_id":"current_user_id"},"limit":10}}'
        ));

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
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"List invoices for selected user","parameters":{"model":"invoice","limit":10}}'
        ));

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
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"Fetch invoices","parameters":{"model":"invoice","limit":10}}'
        ));

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
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"Fetch invoices","parameters":{"model":"invoice","limit":10}}'
        ));

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
        $service = new AutonomousRAGDecisionService($this->mockAiResponse(
            '{"tool":"db_query","reasoning":"List invoices","parameters":{"model":"invoice","limit":10}}'
        ));

        $decision = $service->decide('show invoices', $this->sampleContext([
            'selected_entity' => [
                'entity_id' => 42,
                'entity_type' => 'invoice',
            ],
        ]), 'gpt-4o-mini');

        $this->assertSame('db_query', $decision['tool']);
        $this->assertArrayNotHasKey('id', $decision['parameters']['filters'] ?? []);
    }

    protected function mockAiResponse(string $content): AIEngineService
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generateText')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame(EngineEnum::OPENAI, $request->getEngine()->value);
                $this->assertSame(EntityEnum::GPT_4O_MINI, $request->getModel()->value);
                $this->assertSame(0.1, $request->getTemperature());
                $this->assertSame(1000, $request->getMaxTokens());

                return true;
            })
            ->andReturn(AIResponse::success(
                $content,
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            ));

        return $ai;
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
