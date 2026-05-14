<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Services\RAG\RAGDecisionStateService;
use LaravelAIEngine\Services\RAG\RAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RAGStructuredDataServiceTest extends UnitTestCase
{
    public function test_query_returns_route_signal_when_model_cannot_be_resolved(): void
    {
        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->query([
            'model' => 'invoice',
        ], 9, [], [
            'findModelClass' => fn (string $modelName, array $options) => null,
            'getFilterConfigForModel' => fn (string $modelClass) => [],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['should_route_to_node']);
    }

    public function test_query_next_uses_cached_state_to_request_next_page(): void
    {
        $stateService = $this->createMock(RAGDecisionStateService::class);
        $stateService->method('getQueryState')->with('session-1')->willReturn([
            'page' => 1,
            'total_pages' => 3,
            'total_count' => 12,
            'model' => 'invoice',
            'filters' => ['status' => 'paid'],
            'user_id' => 15,
            'options' => ['session_id' => 'session-1'],
        ]);

        $service = new RAGStructuredDataService($stateService, new RAGDecisionPolicy());

        $result = $service->queryNext([], 15, ['session_id' => 'session-1'], function (
            array $params,
            $userId,
            array $options,
            int $page
        ) {
            return [
                'params' => $params,
                'user_id' => $userId,
                'options' => $options,
                'page' => $page,
            ];
        });

        $this->assertSame('invoice', $result['params']['model']);
        $this->assertSame(['status' => 'paid'], $result['params']['filters']);
        $this->assertSame(15, $result['user_id']);
        $this->assertSame(2, $result['page']);
    }

    public function test_query_uses_localized_no_results_message(): void
    {
        app()->setLocale('ar');

        Schema::dropIfExists('rag_empty_models');
        Schema::create('rag_empty_models', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->query([
            'model' => 'invoice',
        ], 3, [], [
            'findModelClass' => fn (string $modelName, array $options) => LocalizedEmptyModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['user_field' => 'created_by'],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('لم يتم العثور على نتائج.', $result['response']);
    }

    public function test_count_returns_route_signal_for_remote_only_models(): void
    {
        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->count([
            'model' => 'invoice',
        ], 9, [], [
            'findModelClass' => fn (string $modelName, array $options) => 'App\\Remote\\Invoice',
            'getFilterConfigForModel' => fn (string $modelClass) => [],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['should_route_to_node']);
    }

    public function test_query_blocks_unscoped_structured_model_by_default(): void
    {
        Schema::dropIfExists('rag_unscoped_models');
        Schema::create('rag_unscoped_models', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        UnscopedRAGModel::create(['name' => 'Private record']);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->query([
            'model' => 'invoice',
        ], 9, [], [
            'findModelClass' => fn (string $modelName, array $options) => UnscopedRAGModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => [],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['scope_blocked']);
        $this->assertStringContainsString('no usable scope', $result['error']);
    }

    public function test_query_allows_explicit_public_structured_model(): void
    {
        Schema::dropIfExists('rag_unscoped_models');
        Schema::create('rag_unscoped_models', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        UnscopedRAGModel::create(['name' => 'Public record']);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->query([
            'model' => 'invoice',
        ], null, [], [
            'findModelClass' => fn (string $modelName, array $options) => UnscopedRAGModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['public_access' => true],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total_count']);
    }

    public function test_query_applies_user_tenant_and_workspace_scope_filters(): void
    {
        Schema::dropIfExists('rag_scoped_models');
        Schema::create('rag_scoped_models', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('workspace_id')->nullable();
            $table->string('name')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });

        ScopedRAGModel::create(['user_id' => 9, 'tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a', 'name' => 'Visible']);
        ScopedRAGModel::create(['user_id' => 9, 'tenant_id' => 'tenant-b', 'workspace_id' => 'workspace-a', 'name' => 'Wrong tenant']);
        ScopedRAGModel::create(['user_id' => 9, 'tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-b', 'name' => 'Wrong workspace']);
        ScopedRAGModel::create(['user_id' => 10, 'tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a', 'name' => 'Wrong user']);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->query([
            'model' => 'invoice',
        ], 9, ['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a'], [
            'findModelClass' => fn (string $modelName, array $options) => ScopedRAGModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['user_field' => 'user_id'],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['total_count']);
        $this->assertSame('Visible', $result['items'][0]['name']);
    }

    public function test_count_allows_tenant_and_workspace_scope_without_user_id(): void
    {
        Schema::dropIfExists('rag_scoped_models');
        Schema::create('rag_scoped_models', function ($table) {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->string('workspace_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        ScopedRAGModel::create(['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a', 'name' => 'Visible']);
        ScopedRAGModel::create(['tenant_id' => 'tenant-b', 'workspace_id' => 'workspace-a', 'name' => 'Wrong tenant']);
        ScopedRAGModel::create(['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-b', 'name' => 'Wrong workspace']);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->count([
            'model' => 'invoice',
        ], null, ['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a'], [
            'findModelClass' => fn (string $modelName, array $options) => ScopedRAGModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => [],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['count']);
    }

    public function test_aggregate_blocks_unscoped_structured_model_by_default(): void
    {
        Schema::dropIfExists('rag_unscoped_models');
        Schema::create('rag_unscoped_models', function ($table) {
            $table->id();
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });

        UnscopedRAGModel::create(['total' => 100]);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->aggregate([
            'model' => 'invoice',
            'aggregate' => ['operation' => 'sum', 'field' => 'total'],
        ], 9, [], [
            'findModelClass' => fn (string $modelName, array $options) => UnscopedRAGModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => [],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['scope_blocked']);
    }

    public function test_aggregate_applies_tenant_and_workspace_scope_filters(): void
    {
        Schema::dropIfExists('rag_scoped_models');
        Schema::create('rag_scoped_models', function ($table) {
            $table->id();
            $table->string('tenant_id')->nullable();
            $table->string('workspace_id')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });

        ScopedRAGModel::create(['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a', 'total' => 100]);
        ScopedRAGModel::create(['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a', 'total' => 200]);
        ScopedRAGModel::create(['tenant_id' => 'tenant-b', 'workspace_id' => 'workspace-a', 'total' => 999]);
        ScopedRAGModel::create(['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-b', 'total' => 888]);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->aggregate([
            'model' => 'invoice',
            'aggregate' => ['operation' => 'sum', 'field' => 'total'],
        ], null, ['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a'], [
            'findModelClass' => fn (string $modelName, array $options) => ScopedRAGModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => [],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(300.0, (float) $result['result']);
        $this->assertSame(2, $result['count']);
    }

    public function test_aggregate_defaults_to_total_field_and_supports_avg_min_max(): void
    {
        Schema::dropIfExists('rag_aggregate_invoices');
        Schema::create('rag_aggregate_invoices', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->timestamps();
        });

        AggregateInvoiceModel::create(['user_id' => 9, 'status' => 'paid', 'total' => 100, 'tax' => 10]);
        AggregateInvoiceModel::create(['user_id' => 9, 'status' => 'sent', 'total' => 200, 'tax' => 20]);
        AggregateInvoiceModel::create(['user_id' => 9, 'status' => 'sent', 'total' => 300, 'tax' => 30]);
        AggregateInvoiceModel::create(['user_id' => 10, 'status' => 'sent', 'total' => 999, 'tax' => 99]);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );
        $dependencies = [
            'findModelClass' => fn (string $modelName, array $options) => AggregateInvoiceModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['user_field' => 'user_id'],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ];

        $sum = $service->aggregate(['model' => 'invoice', 'aggregate' => ['operation' => 'sum']], 9, [], $dependencies);
        $avg = $service->aggregate(['model' => 'invoice', 'aggregate' => ['operation' => 'avg']], 9, [], $dependencies);
        $min = $service->aggregate(['model' => 'invoice', 'aggregate' => ['operation' => 'min']], 9, [], $dependencies);
        $max = $service->aggregate(['model' => 'invoice', 'aggregate' => ['operation' => 'max']], 9, [], $dependencies);
        $summary = $service->aggregate(['model' => 'invoice', 'aggregate' => ['operation' => 'summary']], 9, [], $dependencies);

        $this->assertSame(600.0, (float) $sum['result']);
        $this->assertSame('total', $sum['field']);
        $this->assertSame(200.0, (float) $avg['result']);
        $this->assertSame(100.0, (float) $min['result']);
        $this->assertSame(300.0, (float) $max['result']);
        $this->assertSame([
            'count' => 3,
            'sum' => 600.0,
            'avg' => 200.0,
            'min' => 100.0,
            'max' => 300.0,
        ], $summary['result']);
    }

    public function test_aggregate_groups_sum_and_count_by_status(): void
    {
        Schema::dropIfExists('rag_aggregate_invoices');
        Schema::create('rag_aggregate_invoices', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });

        AggregateInvoiceModel::create(['user_id' => 9, 'status' => 'paid', 'total' => 100]);
        AggregateInvoiceModel::create(['user_id' => 9, 'status' => 'sent', 'total' => 200]);
        AggregateInvoiceModel::create(['user_id' => 9, 'status' => 'sent', 'total' => 300]);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );
        $dependencies = [
            'findModelClass' => fn (string $modelName, array $options) => AggregateInvoiceModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['user_field' => 'user_id'],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ];

        $sum = $service->aggregate([
            'model' => 'invoice',
            'aggregate' => ['operation' => 'sum', 'field' => 'total', 'group_by' => 'status'],
        ], 9, [], $dependencies);
        $count = $service->aggregate([
            'model' => 'invoice',
            'aggregate' => ['operation' => 'count', 'group_by' => 'status'],
        ], 9, [], $dependencies);

        $this->assertTrue($sum['success']);
        $this->assertSame('status', $sum['group_by']);
        $this->assertSame([
            ['bucket' => 'paid', 'value' => 100.0],
            ['bucket' => 'sent', 'value' => 500.0],
        ], $sum['groups']);
        $this->assertSame([
            ['bucket' => 'paid', 'value' => 1.0],
            ['bucket' => 'sent', 'value' => 2.0],
        ], $count['groups']);
    }

    public function test_execute_model_tool_uses_selected_entity_without_context_variable(): void
    {
        if (!class_exists('App\\AI\\Configs\\InvoiceModelConfig')) {
            eval(<<<'PHP'
namespace App\AI\Configs;

class InvoiceModelConfig extends \LaravelAIEngine\Contracts\AutonomousModelConfig
{
    public static function getName(): string { return 'invoice'; }
    public static function getDescription(): string { return 'Invoice'; }
    public static function getModelClass(): string { return \LaravelAIEngine\Tests\Unit\Services\RAG\FakeInvoiceModel::class; }
    public static function getSearchableFields(): array { return []; }
    public static function getFilterableFields(): array { return []; }
    public static function getDateFields(): array { return []; }
    public static function getRelationships(): array { return []; }
    public static function getAllowedOperations($user = null): array { return ['update']; }
    public static function getTools(): array
    {
        return [
            'update_status' => [
                'handler' => fn (array $params) => ['success' => true, 'message' => $params['entity_data']['status'] ?? 'missing'],
                'parameters' => [],
            ],
        ];
    }
    public static function getExamples(): array { return []; }
    public static function shouldUseDataCollector(): bool { return false; }
    public static function getDataCollectorFields(): array { return []; }
    public static function getValidationRules(): array { return []; }
    public static function getFilterConfig(): array { return []; }
}
PHP);
        }

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->executeModelTool([
            'model' => 'invoice',
            'tool_name' => 'update_status',
            'message' => 'update it',
            'conversation_history' => [],
        ], 9, [
            'selected_entity' => [
                'entity_id' => 22,
                'entity_type' => 'invoice',
                'entity_data' => ['status' => 'paid'],
            ],
        ], [
            'findModelClass' => fn (string $modelName, array $options) => FakeInvoiceModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => [],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => 'App\\AI\\Configs\\InvoiceModelConfig',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('paid', $result['response']);
    }

    public function test_execute_model_tool_returns_route_signal_when_model_cannot_be_resolved(): void
    {
        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->executeModelTool([
            'model' => 'invoice',
            'tool_name' => 'update_status',
        ], 9, [], [
            'findModelClass' => fn (string $modelName, array $options) => null,
            'getFilterConfigForModel' => fn (string $modelClass) => [],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['should_route_to_node']);
    }

    public function test_query_list_uses_persisted_entity_summaries_when_enabled(): void
    {
        config()->set('ai-engine.entity_summaries.enabled', true);
        config()->set('ai-engine.entity_summaries.use_in_list_responses', true);

        Schema::dropIfExists('rag_summary_models');
        Schema::create('rag_summary_models', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('ai_entity_summaries');
        Schema::create('ai_entity_summaries', function ($table) {
            $table->id();
            $table->string('summaryable_type');
            $table->string('summaryable_id', 191);
            $table->string('locale', 16)->default('en');
            $table->text('summary');
            $table->string('source_hash', 64)->nullable();
            $table->string('policy_version', 32)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['summaryable_type', 'summaryable_id', 'locale'], 'ai_entity_summaries_entity_locale_unique');
        });

        SummaryListModel::create([
            'created_by' => 9,
            'name' => 'INV-1',
            'details' => 'VERBOSE_DETAILS_TOKEN one',
        ]);
        SummaryListModel::create([
            'created_by' => 9,
            'name' => 'INV-2',
            'details' => 'VERBOSE_DETAILS_TOKEN two',
        ]);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->query([
            'model' => 'invoice',
        ], 9, ['session_id' => 'summary-session'], [
            'findModelClass' => fn (string $modelName, array $options) => SummaryListModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['user_field' => 'created_by'],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Summary invoice INV-1', $result['response']);
        $this->assertStringNotContainsString('VERBOSE_DETAILS_TOKEN', $result['response']);
        $this->assertDatabaseCount('ai_entity_summaries', 2);
    }

    public function test_query_list_prefers_model_preview_when_available(): void
    {
        config()->set('ai-engine.entity_summaries.enabled', true);
        config()->set('ai-engine.entity_summaries.use_in_list_responses', true);

        Schema::dropIfExists('rag_preview_models');
        Schema::create('rag_preview_models', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        SummaryPreviewListModel::create(['created_by' => 9, 'name' => 'INV-1']);
        SummaryPreviewListModel::create(['created_by' => 9, 'name' => 'INV-2']);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->query([
            'model' => 'invoice',
        ], 9, ['session_id' => 'preview-session'], [
            'findModelClass' => fn (string $modelName, array $options) => SummaryPreviewListModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['user_field' => 'created_by'],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('1. **Card INV-1**', $result['response']);
        $this->assertStringContainsString("\n   Customer line", $result['response']);
        $this->assertStringNotContainsString('SUMMARY_TOKEN', $result['response']);
    }

    public function test_query_list_uses_model_display_name_for_heading(): void
    {
        Schema::dropIfExists('rag_preview_models');
        Schema::create('rag_preview_models', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        SummaryPreviewListModel::create(['created_by' => 9, 'name' => 'INV-1']);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );

        $result = $service->query([
            'model' => 'summarypreviewlistmodel',
        ], 9, ['session_id' => 'preview-heading-session'], [
            'findModelClass' => fn (string $modelName, array $options) => SummaryPreviewListModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['user_field' => 'created_by'],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('**Invoice Cards**', $result['response']);
    }

    public function test_query_can_enrich_structured_results_with_graph_context(): void
    {
        Schema::dropIfExists('rag_preview_models');
        Schema::create('rag_preview_models', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        SummaryPreviewListModel::create(['created_by' => 9, 'name' => 'INV-1']);

        $graphRetrieval = Mockery::mock(Neo4jRetrievalService::class);
        $graphRetrieval->shouldReceive('enabled')->andReturn(true);
        $graphRetrieval->shouldReceive('retrieveRelevantContext')->once()->andReturn(new Collection([
            (object) [
                'id' => 901,
                'title' => 'Graph Owner',
                'matched_chunk_text' => "Graph owner summary",
                'vector_metadata' => [
                    'entity_ref' => ['model_id' => 901, 'model_class' => 'App\\Models\\User'],
                    'object' => ['id' => 901, 'title' => 'Graph Owner'],
                    'graph_planned' => true,
                    'planner_strategy' => 'semantic_graph_planner',
                    'planner_query_kind' => 'ownership',
                    'relation_path' => ['OWNED_BY'],
                    'path_length' => 1,
                ],
            ],
        ]));

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy(),
            null,
            null,
            null,
            $graphRetrieval
        );

        $result = $service->query([
            'model' => 'invoice',
            'query' => 'list invoice cards and who owns them',
        ], 9, ['session_id' => 'preview-heading-session'], [
            'findModelClass' => fn (string $modelName, array $options) => SummaryPreviewListModel::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['user_field' => 'created_by'],
            'applyFilters' => fn ($query, array $filters, string $modelClass, array $options) => $query,
            'findModelConfigClass' => fn (string $modelClass) => null,
        ], 1, 'list invoice cards and who owns them');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Related graph context', $result['response']);
        $this->assertTrue((bool) ($result['metadata']['graph_planned'] ?? false));
        $this->assertSame('ownership', $result['metadata']['planner_query_kind'] ?? null);
        $this->assertCount(1, $result['metadata']['sources'] ?? []);
    }
}

class FakeInvoiceModel
{
}

class AggregateInvoiceModel extends Model
{
    protected $table = 'rag_aggregate_invoices';

    protected $fillable = ['user_id', 'status', 'total', 'tax'];
}

class UnscopedRAGModel extends Model
{
    protected $table = 'rag_unscoped_models';

    protected $guarded = [];
}

class ScopedRAGModel extends Model
{
    protected $table = 'rag_scoped_models';

    protected $guarded = [];
}

class LocalizedEmptyModel extends Model
{
    protected $table = 'rag_empty_models';
}

class SummaryListModel extends Model
{
    protected $table = 'rag_summary_models';

    protected $fillable = ['created_by', 'name', 'details'];

    public function toRAGSummary(): string
    {
        return "Summary invoice {$this->name}";
    }

    public function toRAGContent(): string
    {
        return "VERBOSE_DETAILS_TOKEN {$this->details}";
    }
}

class SummaryPreviewListModel extends Model
{
    protected $table = 'rag_preview_models';

    protected $fillable = ['created_by', 'name'];

    public function toRAGListPreview(?string $locale = null): string
    {
        return "**Card {$this->name}**\nCustomer line\nDue line";
    }

    public function toRAGSummary(): string
    {
        return "SUMMARY_TOKEN {$this->name}";
    }

    public function getRAGDisplayName(): string
    {
        return 'Invoice Card';
    }
}
