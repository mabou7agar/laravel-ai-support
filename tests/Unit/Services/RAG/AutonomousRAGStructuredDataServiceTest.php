<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;
use LaravelAIEngine\Services\RAG\AutonomousRAGStateService;
use LaravelAIEngine\Services\RAG\AutonomousRAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AutonomousRAGStructuredDataServiceTest extends UnitTestCase
{
    public function test_query_returns_route_signal_when_model_cannot_be_resolved(): void
    {
        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy()
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
        $stateService = $this->createMock(AutonomousRAGStateService::class);
        $stateService->method('getQueryState')->with('session-1')->willReturn([
            'page' => 1,
            'total_pages' => 3,
            'total_count' => 12,
            'model' => 'invoice',
            'filters' => ['status' => 'paid'],
            'user_id' => 15,
            'options' => ['session_id' => 'session-1'],
        ]);

        $service = new AutonomousRAGStructuredDataService($stateService, new AutonomousRAGPolicy());

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

        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy()
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
        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy()
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

        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy()
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
        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy()
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

        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy()
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

        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy()
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

        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy()
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

        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy(),
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
