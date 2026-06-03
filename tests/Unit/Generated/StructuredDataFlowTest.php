<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\DataQueryTool;
use LaravelAIEngine\Services\RAG\RAGAggregateService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Services\RAG\RAGDecisionStateService;
use LaravelAIEngine\Services\RAG\RAGModelScopeGuard;
use LaravelAIEngine\Services\RAG\RAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;

/**
 * Generated coverage for the "Structured data / data_query" surface:
 *  - RAGModelScopeGuard (scope-method path, where-fallback, block branches, public aliases, filter_config fields)
 *  - RAGStructuredDataService::query / queryNext (pagination, terminal branches, single-record, missing-table routing, executeModelTool guards)
 *  - DataQueryTool (input guards, clamp, schema-aware fallbacks, multi-dimension scope, discovery)
 *  - RAGAggregateService standalone edge cases
 *  - Cross-surface count/list/queryNext parity
 *
 * Self-contained: every collaborator is real or closure-injected; no LLM / network calls.
 */
class StructuredDataFlowTest extends UnitTestCase
{
    private function deps(string $modelClass, array $filterConfig = [], ?string $configClass = null): array
    {
        return [
            'findModelClass' => fn (string $modelName, array $options) => $modelClass,
            'getFilterConfigForModel' => fn (string $mc) => $filterConfig,
            'applyFilters' => fn ($query, array $filters, string $mc, array $options) => $query,
            'findModelConfigClass' => fn (string $mc) => $configClass,
        ];
    }

    private function service(?RAGDecisionStateService $state = null): RAGStructuredDataService
    {
        return new RAGStructuredDataService(
            $state ?? new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );
    }

    // ---------------------------------------------------------------------
    // Scenario: RAGModelScopeGuard scope-method path vs where-fallback path
    // ---------------------------------------------------------------------

    public function test_scope_guard_uses_scope_methods_when_present(): void
    {
        Schema::dropIfExists('sdf_scope_method');
        Schema::create('sdf_scope_method', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('tenant_id')->nullable();
            $t->string('workspace_id')->nullable();
            $t->timestamps();
        });

        SdfScopeMethodModel::create(['user_id' => 9, 'tenant_id' => 't-a', 'workspace_id' => 'w-a']);
        SdfScopeMethodModel::create(['user_id' => 9, 'tenant_id' => 't-b', 'workspace_id' => 'w-a']);

        $guard = new RAGModelScopeGuard();
        $result = $guard->apply(
            SdfScopeMethodModel::query(),
            SdfScopeMethodModel::class,
            9,
            [],
            ['tenant_id' => 't-a', 'workspace_id' => 'w-a']
        );

        $this->assertTrue($result['allowed']);
        $this->assertSame('tenant:forTenant,workspace:forWorkspace,user:scopeForUser', $result['scope']);
        // The returned builder is scoped: only the (t-a, w-a, 9) row matches.
        $this->assertSame(1, $result['query']->count());
    }

    public function test_scope_guard_falls_back_to_where_fields(): void
    {
        Schema::dropIfExists('sdf_scope_fields');
        Schema::create('sdf_scope_fields', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('tenant_id')->nullable();
            $t->string('workspace_id')->nullable();
            $t->timestamps();
        });

        SdfScopeFieldsModel::create(['user_id' => 9, 'tenant_id' => 't-a', 'workspace_id' => 'w-a']);
        SdfScopeFieldsModel::create(['user_id' => 10, 'tenant_id' => 't-a', 'workspace_id' => 'w-a']);

        $guard = new RAGModelScopeGuard();
        $result = $guard->apply(
            SdfScopeFieldsModel::query(),
            SdfScopeFieldsModel::class,
            9,
            [],
            ['tenant_id' => 't-a', 'workspace_id' => 'w-a']
        );

        $this->assertTrue($result['allowed']);
        $this->assertSame('tenant:tenant_id,workspace:workspace_id,user:user_id', $result['scope']);
        $this->assertSame(1, $result['query']->count());
    }

    // ---------------------------------------------------------------------
    // Scenario: RAGModelScopeGuard BLOCK branches + disabled
    // ---------------------------------------------------------------------

    public function test_scope_guard_blocks_when_tenant_value_has_no_field_or_method(): void
    {
        Schema::dropIfExists('sdf_no_tenant');
        Schema::create('sdf_no_tenant', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        $guard = new RAGModelScopeGuard();
        $result = $guard->apply(
            SdfNoTenantModel::query(),
            SdfNoTenantModel::class,
            9,
            [],
            ['tenant_id' => 't-a']
        );

        $this->assertFalse($result['allowed']);
        $this->assertNull($result['query']);
        $this->assertSame(SdfNoTenantModel::class, $result['model_class']);
        $this->assertStringContainsString(
            'tenant scope was provided but this model has no tenant scope field or scopeForTenant()',
            $result['error']
        );
    }

    public function test_scope_guard_blocks_when_no_scope_and_null_user(): void
    {
        Schema::dropIfExists('sdf_no_scope');
        Schema::create('sdf_no_scope', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        $guard = new RAGModelScopeGuard();
        $result = $guard->apply(SdfNoScopeModel::query(), SdfNoScopeModel::class, null, [], []);

        $this->assertFalse($result['allowed']);
        $this->assertSame(
            'No authenticated user id was provided for scoped structured RAG access.',
            $result['error']
        );
    }

    public function test_scope_guard_blocks_with_remediation_when_user_present_but_no_scope(): void
    {
        Schema::dropIfExists('sdf_no_scope');
        Schema::create('sdf_no_scope', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        $guard = new RAGModelScopeGuard();
        $result = $guard->apply(SdfNoScopeModel::query(), SdfNoScopeModel::class, 9, [], []);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('no usable scope', $result['error']);
        $this->assertStringContainsString('scopeForUser()', $result['error']);
        $this->assertStringContainsString('public_access=true', $result['error']);
    }

    public function test_scope_guard_disabled_when_require_structured_scope_false(): void
    {
        config()->set('ai-engine.rag.require_structured_scope', false);

        Schema::dropIfExists('sdf_no_scope');
        Schema::create('sdf_no_scope', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        $guard = new RAGModelScopeGuard();
        $result = $guard->apply(SdfNoScopeModel::query(), SdfNoScopeModel::class, null, [], []);

        $this->assertTrue($result['allowed']);
        $this->assertSame('disabled', $result['scope']);

        config()->set('ai-engine.rag.require_structured_scope', true);
    }

    // ---------------------------------------------------------------------
    // Scenario: filter_config field resolution + public-access aliases
    // ---------------------------------------------------------------------

    public function test_scope_guard_resolves_created_by_via_filter_config_and_default_fallback(): void
    {
        Schema::dropIfExists('sdf_created_by');
        Schema::create('sdf_created_by', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        $guard = new RAGModelScopeGuard();

        $explicit = $guard->apply(SdfCreatedByModel::query(), SdfCreatedByModel::class, 9, ['user_field' => 'created_by'], []);
        $this->assertSame('user:created_by', $explicit['scope']);

        $fallback = $guard->apply(SdfCreatedByModel::query(), SdfCreatedByModel::class, 9, [], []);
        $this->assertSame('user:created_by', $fallback['scope']);
    }

    public function test_scope_guard_resolves_owner_id_via_default_fallback(): void
    {
        Schema::dropIfExists('sdf_owner');
        Schema::create('sdf_owner', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->timestamps();
        });

        $guard = new RAGModelScopeGuard();
        $result = $guard->apply(SdfOwnerModel::query(), SdfOwnerModel::class, 9, [], []);

        $this->assertSame('user:owner_id', $result['scope']);
    }

    public function test_scope_guard_tenant_field_wins_then_tenant_fields_candidate(): void
    {
        Schema::dropIfExists('sdf_org');
        Schema::create('sdf_org', function (Blueprint $t): void {
            $t->id();
            $t->string('org_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });

        $guard = new RAGModelScopeGuard();
        $withOrg = $guard->apply(
            SdfOrgModel::query(),
            SdfOrgModel::class,
            9,
            ['tenant_field' => 'org_id', 'tenant_fields' => ['account_id']],
            ['tenant_id' => 't-a']
        );
        $this->assertStringContainsString('tenant:org_id', $withOrg['scope']);

        // Now a table where org_id is absent but account_id exists -> tenant_fields candidate used.
        Schema::dropIfExists('sdf_org');
        Schema::create('sdf_org', function (Blueprint $t): void {
            $t->id();
            $t->string('account_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });

        $withAccount = $guard->apply(
            SdfOrgModel::query(),
            SdfOrgModel::class,
            9,
            ['tenant_field' => 'org_id', 'tenant_fields' => ['account_id']],
            ['tenant_id' => 't-a']
        );
        $this->assertStringContainsString('tenant:account_id', $withAccount['scope']);
    }

    public function test_scope_guard_public_access_aliases(): void
    {
        Schema::dropIfExists('sdf_no_scope');
        Schema::create('sdf_no_scope', function (Blueprint $t): void {
            $t->id();
            $t->timestamps();
        });

        $guard = new RAGModelScopeGuard();

        $public = $guard->apply(SdfNoScopeModel::query(), SdfNoScopeModel::class, null, ['public' => true], []);
        $this->assertTrue($public['allowed']);
        $this->assertSame('public', $public['scope']);

        $scopeRequiredFalse = $guard->apply(SdfNoScopeModel::query(), SdfNoScopeModel::class, null, ['scope_required' => false], []);
        $this->assertTrue($scopeRequiredFalse['allowed']);
        $this->assertSame('public', $scopeRequiredFalse['scope']);
    }

    // ---------------------------------------------------------------------
    // Scenario: query multi-page pagination then queryNext continuation
    // ---------------------------------------------------------------------

    private function seedInvoices(int $count, int $userId = 9): void
    {
        Schema::dropIfExists('sdf_invoices');
        Schema::create('sdf_invoices', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        for ($i = 1; $i <= $count; $i++) {
            SdfInvoiceModel::create(['created_by' => $userId, 'name' => "INV-{$i}"]);
        }
    }

    public function test_query_first_page_paginates_and_persists_state(): void
    {
        $this->seedInvoices(23);
        $state = new RAGDecisionStateService(new RAGDecisionPolicy());
        $service = $this->service($state);

        $result = $service->query(
            ['model' => 'invoice'],
            9,
            ['session_id' => 'pg-1'],
            $this->deps(SdfInvoiceModel::class, ['user_field' => 'created_by'])
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('showing 1-10 of 23', $result['response']);
        $this->assertSame(10, $result['count']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(3, $result['total_pages']);
        $this->assertTrue($result['has_more']);
        $this->assertStringContainsString('show more', $result['response']);

        $persisted = $state->getQueryState('pg-1');
        $this->assertSame(1, $persisted['start_position']);
        $this->assertSame(10, $persisted['end_position']);
        $this->assertCount(10, $persisted['entity_refs']);
        $this->assertCount(10, $persisted['objects']);
    }

    public function test_query_next_advances_to_page_two_via_handler(): void
    {
        $stateService = $this->createMock(RAGDecisionStateService::class);
        $stateService->method('getQueryState')->with('pg-1')->willReturn([
            'page' => 1,
            'total_pages' => 3,
            'total_count' => 23,
            'model' => 'invoice',
            'filters' => [],
            'user_id' => 9,
            'options' => ['session_id' => 'pg-1'],
        ]);

        $service = new RAGStructuredDataService($stateService, new RAGDecisionPolicy());

        $captured = null;
        $service->queryNext([], 9, ['session_id' => 'pg-1'], function (array $params, $userId, array $options, int $page) use (&$captured) {
            $captured = $page;

            return ['page' => $page];
        });

        $this->assertSame(2, $captured);
    }

    public function test_query_last_page_has_no_footer(): void
    {
        $this->seedInvoices(23);
        $service = $this->service();

        $result = $service->query(
            ['model' => 'invoice'],
            9,
            ['session_id' => 'pg-1'],
            $this->deps(SdfInvoiceModel::class, ['user_field' => 'created_by']),
            3
        );

        $this->assertStringContainsString('showing 21-23 of 23', $result['response']);
        $this->assertFalse($result['has_more']);
        $this->assertStringNotContainsString('show more', $result['response']);
    }

    // ---------------------------------------------------------------------
    // Scenario: page>1 empty -> no_more_results, queryNext past-end -> reached_end
    // ---------------------------------------------------------------------

    public function test_query_page_beyond_data_returns_no_more_results(): void
    {
        $this->seedInvoices(5);
        $service = $this->service();

        $result = $service->query(
            ['model' => 'invoice'],
            9,
            ['session_id' => 'end-1'],
            $this->deps(SdfInvoiceModel::class, ['user_field' => 'created_by']),
            2
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['count']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(1, $result['total_pages']);
        $this->assertSame(5, $result['total_count']);
        $this->assertSame("No more results to show. You've seen all 5 results.", $result['response']);
    }

    public function test_query_next_past_end_returns_reached_end_without_calling_handler(): void
    {
        $stateService = $this->createMock(RAGDecisionStateService::class);
        $stateService->method('getQueryState')->with('done')->willReturn([
            'page' => 1,
            'total_pages' => 1,
            'total_count' => 5,
            'model' => 'invoice',
            'filters' => [],
            'user_id' => 9,
            'options' => ['session_id' => 'done'],
        ]);

        $service = new RAGStructuredDataService($stateService, new RAGDecisionPolicy());

        $called = false;
        $result = $service->queryNext([], 9, ['session_id' => 'done'], function () use (&$called) {
            $called = true;

            return ['called' => true];
        });

        $this->assertFalse($called);
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['count']);
        $this->assertSame("You've reached the end. All 5 results have been shown.", $result['response']);
    }

    public function test_query_next_past_end_localized_under_ar_locale(): void
    {
        app()->setLocale('ar');

        $stateService = $this->createMock(RAGDecisionStateService::class);
        $stateService->method('getQueryState')->with('done-ar')->willReturn([
            'page' => 1,
            'total_pages' => 1,
            'total_count' => 5,
            'model' => 'invoice',
            'filters' => [],
            'user_id' => 9,
            'options' => ['session_id' => 'done-ar'],
        ]);

        $service = new RAGStructuredDataService($stateService, new RAGDecisionPolicy());
        $result = $service->queryNext([], 9, ['session_id' => 'done-ar'], fn () => ['called' => true]);

        $this->assertSame('وصلت إلى نهاية النتائج. تم عرض جميع النتائج وعددها 5.', $result['response']);

        app()->setLocale('en');
    }

    // ---------------------------------------------------------------------
    // Scenario: queryNext error branches
    // ---------------------------------------------------------------------

    public function test_query_next_missing_session_id_errors(): void
    {
        $service = $this->service();
        $called = false;

        $result = $service->queryNext([], 9, [], function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($result['success']);
        $this->assertSame('No session ID provided for pagination.', $result['error']);
        $this->assertSame('db_query_next', $result['tool']);
        $this->assertFalse($called);
    }

    public function test_query_next_empty_state_errors(): void
    {
        $stateService = $this->createMock(RAGDecisionStateService::class);
        $stateService->method('getQueryState')->with('orphan')->willReturn([]);

        $service = new RAGStructuredDataService($stateService, new RAGDecisionPolicy());
        $called = false;

        $result = $service->queryNext([], 9, ['session_id' => 'orphan'], function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($result['success']);
        $this->assertSame('No previous query to continue. Please make a query first.', $result['error']);
        $this->assertSame('db_query_next', $result['tool']);
        $this->assertFalse($called);
    }

    // ---------------------------------------------------------------------
    // Scenario: single-record detail path with toRAGDetail/__toString/json fallbacks
    // ---------------------------------------------------------------------

    private function createDetailTable(string $table): void
    {
        Schema::dropIfExists($table);
        Schema::create($table, function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });
    }

    public function test_single_record_prefers_to_rag_detail(): void
    {
        $this->createDetailTable('sdf_detail');
        SdfDetailModel::create(['created_by' => 9, 'name' => 'INV-1']);

        $deps = $this->deps(SdfDetailModel::class, ['user_field' => 'created_by']);
        $deps['applyFilters'] = fn ($query, array $filters, string $mc, array $options) => $query->where('id', 1);

        $result = $this->service()->query(
            ['model' => 'invoice', 'filters' => ['id' => 1]],
            9,
            [],
            $deps
        );

        $this->assertTrue($result['success']);
        $this->assertSame('DETAIL: INV-1', $result['response']);
    }

    public function test_single_record_falls_back_to_to_string(): void
    {
        $this->createDetailTable('sdf_tostring');
        SdfToStringModel::create(['created_by' => 9, 'name' => 'INV-2']);

        $deps = $this->deps(SdfToStringModel::class, ['user_field' => 'created_by']);
        $deps['applyFilters'] = fn ($query, array $filters, string $mc, array $options) => $query->where('id', 1);

        $result = $this->service()->query(
            ['model' => 'invoice', 'filters' => ['id' => 1]],
            9,
            [],
            $deps
        );

        $this->assertSame('STRING: INV-2', $result['response']);
    }

    public function test_single_record_falls_back_to_json(): void
    {
        $this->createDetailTable('sdf_bare');
        SdfBareModel::create(['created_by' => 9, 'name' => 'INV-3']);

        $deps = $this->deps(SdfBareModel::class, ['user_field' => 'created_by']);
        $deps['applyFilters'] = fn ($query, array $filters, string $mc, array $options) => $query->where('id', 1);

        $result = $this->service()->query(
            ['model' => 'invoice', 'filters' => ['id' => 1]],
            9,
            [],
            $deps
        );

        $decoded = json_decode($result['response'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('INV-3', $decoded['name']);
    }

    // ---------------------------------------------------------------------
    // Scenario: missing-table -> route signal vs generic exception -> error
    // ---------------------------------------------------------------------

    public function test_query_missing_table_routes_to_node(): void
    {
        Schema::dropIfExists('sdf_ghost');

        $result = $this->service()->query(
            ['model' => 'ghost'],
            9,
            [],
            $this->deps(SdfGhostModel::class, ['public_access' => true])
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['should_route_to_node']);
        $this->assertSame('Model ghost table is not available locally', $result['error']);
    }

    public function test_query_generic_exception_has_no_route_signal(): void
    {
        $this->seedInvoices(3);

        $deps = $this->deps(SdfInvoiceModel::class, ['user_field' => 'created_by']);
        $deps['applyFilters'] = function () {
            throw new \RuntimeException('boom');
        };

        $result = $this->service()->query(['model' => 'invoice'], 9, [], $deps);

        $this->assertFalse($result['success']);
        $this->assertSame('boom', $result['error']);
        $this->assertArrayNotHasKey('should_route_to_node', $result);
    }

    public function test_count_missing_table_routes_to_node(): void
    {
        Schema::dropIfExists('sdf_ghost');

        $result = $this->service()->count(
            ['model' => 'ghost'],
            9,
            [],
            $this->deps(SdfGhostModel::class, ['public_access' => true])
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['should_route_to_node']);
        $this->assertSame('Model ghost table is not available locally', $result['error']);
    }

    public function test_query_nonexistent_resolved_class_routes_to_node(): void
    {
        $result = $this->service()->query(['model' => 'invoice'], 9, [], [
            'findModelClass' => fn () => 'App\\Remote\\Foo',
            'getFilterConfigForModel' => fn () => [],
            'applyFilters' => fn ($query) => $query,
            'findModelConfigClass' => fn () => null,
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['should_route_to_node']);
    }

    // ---------------------------------------------------------------------
    // Scenario: executeModelTool guards
    // ---------------------------------------------------------------------

    private function ensureToolConfig(): string
    {
        $class = 'LaravelAIEngine\\Tests\\Unit\\Generated\\SdfInvoiceToolConfig';
        if (!class_exists($class)) {
            eval(<<<'PHP'
namespace LaravelAIEngine\Tests\Unit\Generated;

class SdfInvoiceToolConfig extends \LaravelAIEngine\Contracts\ModelToolConfig
{
    public static function getName(): string { return 'invoice'; }
    public static function getDescription(): string { return 'Invoice'; }
    public static function getModelClass(): string { return SdfInvoiceModel::class; }
    public static function getSearchableFields(): array { return []; }
    public static function getFilterableFields(): array { return []; }
    public static function getDateFields(): array { return []; }
    public static function getRelationships(): array { return []; }
    public static function getAllowedOperations($user = null): array { return ['update']; }
    public static function getTools(): array
    {
        return [
            'delete_invoice' => ['handler' => fn (array $p) => ['success' => true], 'parameters' => []],
            'create_invoice' => ['handler' => fn (array $p) => ['success' => true], 'parameters' => []],
            'update_invoice' => ['handler' => fn (array $p) => ['success' => true, 'message' => 'updated'], 'parameters' => []],
            'no_handler_tool' => ['handler' => null, 'parameters' => []],
            'scalar_tool' => ['handler' => fn (array $p) => 'scalar-result', 'parameters' => []],
            'action_tool' => ['handler' => fn (array $p) => ['success' => true, 'message' => 'done', 'suggested_actions' => [['label' => 'View']]], 'parameters' => []],
        ];
    }
    public static function getExamples(): array { return []; }
    public static function getValidationRules(): array { return []; }
    public static function getFilterConfig(): array { return []; }
}
PHP);
        }

        return $class;
    }

    public function test_execute_model_tool_permission_denied_delete_and_create(): void
    {
        $this->seedInvoices(1);
        $configClass = $this->ensureToolConfig();
        $service = $this->service();
        $deps = $this->deps(SdfInvoiceModel::class, [], $configClass);

        $delete = $service->executeModelTool(['model' => 'invoice', 'tool_name' => 'delete_invoice'], 9, [], $deps);
        $this->assertFalse($delete['success']);
        $this->assertSame('Permission denied: delete', $delete['error']);

        $create = $service->executeModelTool(['model' => 'invoice', 'tool_name' => 'create_invoice'], 9, [], $deps);
        $this->assertFalse($create['success']);
        $this->assertSame('Permission denied: create', $create['error']);
    }

    public function test_execute_model_tool_missing_tool_null_handler_and_null_config(): void
    {
        $this->seedInvoices(1);
        $configClass = $this->ensureToolConfig();
        $service = $this->service();

        $nullHandler = $service->executeModelTool(
            ['model' => 'invoice', 'tool_name' => 'no_handler_tool'],
            9,
            [],
            $this->deps(SdfInvoiceModel::class, [], $configClass)
        );
        $this->assertSame('Tool no_handler_tool has no handler', $nullHandler['error']);

        $missingTool = $service->executeModelTool(
            ['model' => 'invoice', 'tool_name' => 'ghost_tool'],
            9,
            [],
            $this->deps(SdfInvoiceModel::class, [], $configClass)
        );
        $this->assertSame('Tool ghost_tool not found for invoice', $missingTool['error']);

        $nullConfig = $service->executeModelTool(
            ['model' => 'invoice', 'tool_name' => 'update_invoice'],
            9,
            [],
            $this->deps(SdfInvoiceModel::class, [], null)
        );
        $this->assertSame('No config found for invoice', $nullConfig['error']);
    }

    public function test_execute_model_tool_from_node_state_short_circuits(): void
    {
        $stateService = $this->createMock(RAGDecisionStateService::class);
        $stateService->method('getQueryState')->willReturn(['from_node' => true]);

        $service = new RAGStructuredDataService($stateService, new RAGDecisionPolicy());

        $result = $service->executeModelTool(
            ['model' => 'invoice', 'tool_name' => 'update_invoice'],
            9,
            ['session_id' => 'remote'],
            [
                'findModelClass' => fn () => SdfInvoiceModel::class,
                'getFilterConfigForModel' => fn () => [],
                'applyFilters' => fn ($q) => $q,
                'findModelConfigClass' => fn () => $this->ensureToolConfig(),
            ]
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['should_route_to_node']);
        $this->assertSame('Model invoice data is on remote node', $result['error']);
    }

    public function test_execute_model_tool_scalar_result_and_suggested_actions(): void
    {
        $this->seedInvoices(1);
        $configClass = $this->ensureToolConfig();
        $service = $this->service();

        $scalar = $service->executeModelTool(
            ['model' => 'invoice', 'tool_name' => 'scalar_tool'],
            9,
            [],
            $this->deps(SdfInvoiceModel::class, [], $configClass)
        );
        $this->assertTrue($scalar['success']);
        $this->assertSame('Tool scalar_tool executed successfully', $scalar['response']);
        $this->assertSame('scalar-result', $scalar['result']);

        $action = $service->executeModelTool(
            ['model' => 'invoice', 'tool_name' => 'action_tool'],
            9,
            [],
            $this->deps(SdfInvoiceModel::class, [], $configClass)
        );
        $this->assertTrue($action['success']);
        $this->assertSame([['label' => 'View']], $action['suggested_actions']);
    }

    // ---------------------------------------------------------------------
    // Scenario: DataQueryTool empty-query, empty-map, limit clamping
    // ---------------------------------------------------------------------

    private function createWidgetTable(bool $withTimestamps = true): void
    {
        Schema::dropIfExists('sdf_widgets');
        Schema::create('sdf_widgets', function (Blueprint $t) use ($withTimestamps): void {
            $t->id();
            $t->string('name');
            $t->string('status')->nullable();
            $t->string('user_id')->nullable();
            $t->string('workspace_id')->nullable();
            $t->string('tenant_id')->nullable();
            if ($withTimestamps) {
                $t->timestamps();
            }
        });
    }

    private function configureWidget(array $overrides = []): void
    {
        config()->set('ai-engine.data_query.models', [
            'widget' => array_merge([
                'class' => SdfWidget::class,
                'aliases' => ['widget', 'widgets'],
                'list' => ['id', 'name', 'status'],
                'statuses' => ['active', 'archived'],
            ], $overrides),
        ]);
        config()->set('ai-engine.data_query.use_discovery', false);
    }

    public function test_data_query_whitespace_query_prompts_for_input(): void
    {
        $this->createWidgetTable();
        $this->configureWidget();

        $r = (new DataQueryTool())->execute(['query' => '   '], new UnifiedActionContext('s', 'u1'));

        $this->assertFalse($r->success);
        $this->assertTrue($r->metadata['needs_user_input'] ?? false);
        $this->assertSame('What would you like to look up?', $r->message);
    }

    public function test_data_query_empty_model_map_reports_no_models(): void
    {
        config()->set('ai-engine.data_query.models', []);
        config()->set('ai-engine.data_query.use_discovery', false);

        $r = (new DataQueryTool())->execute(['query' => 'how many widgets'], new UnifiedActionContext('s', 'u1'));

        $this->assertFalse($r->success);
        $this->assertSame('No queryable data models are configured.', $r->message);
    }

    public function test_data_query_limit_clamped_to_max_and_most_recent_suffix(): void
    {
        $this->createWidgetTable();
        $this->configureWidget();
        config()->set('ai-engine.data_query.max_limit', 5);

        for ($i = 1; $i <= 15; $i++) {
            SdfWidget::create(['name' => "w{$i}", 'status' => 'active', 'user_id' => 'u1']);
        }

        $r = (new DataQueryTool())->execute(['query' => 'list widgets', 'limit' => 999], new UnifiedActionContext('s', 'u1'));

        $this->assertCount(5, $r->data['rows']);
        $this->assertStringContainsString('(most recent)', $r->message);
    }

    public function test_data_query_zero_limit_clamps_to_one(): void
    {
        $this->createWidgetTable();
        $this->configureWidget();

        for ($i = 1; $i <= 5; $i++) {
            SdfWidget::create(['name' => "w{$i}", 'status' => 'active', 'user_id' => 'u1']);
        }

        $r = (new DataQueryTool())->execute(['query' => 'list widgets', 'limit' => 0], new UnifiedActionContext('s', 'u1'));

        $this->assertCount(1, $r->data['rows']);
    }

    // ---------------------------------------------------------------------
    // Scenario: DataQueryTool auto-derived statuses, preferred-column fallback, no-created_at ordering
    // ---------------------------------------------------------------------

    public function test_data_query_derives_status_filter_without_config(): void
    {
        $this->createWidgetTable();
        $this->configureWidget(['statuses' => [], 'list' => null]);

        SdfWidget::create(['name' => 'a', 'status' => 'overdue', 'user_id' => 'u1']);
        SdfWidget::create(['name' => 'b', 'status' => 'active', 'user_id' => 'u1']);

        $r = (new DataQueryTool())->execute(['query' => 'how many overdue widgets'], new UnifiedActionContext('s', 'u1'));

        $this->assertSame('overdue', $r->data['status']);
        $this->assertSame(1, $r->data['count']);
    }

    public function test_data_query_list_columns_fall_back_to_preferred_capped_at_five(): void
    {
        $this->createWidgetTable();
        $this->configureWidget(['list' => null]);

        SdfWidget::create(['name' => 'a', 'status' => 'active', 'user_id' => 'u1']);

        $r = (new DataQueryTool())->execute(['query' => 'list widgets'], new UnifiedActionContext('s', 'u1'));

        $keys = array_keys($r->data['rows'][0]);
        $this->assertLessThanOrEqual(5, count($keys));
        $this->assertContains('id', $keys);
        $this->assertContains('name', $keys);
        $this->assertContains('status', $keys);
    }

    public function test_data_query_orders_by_primary_key_without_created_at(): void
    {
        $this->createWidgetTable(withTimestamps: false);
        $this->configureWidget(['list' => ['id', 'name']]);

        SdfWidget::create(['name' => 'first', 'user_id' => 'u1']);
        SdfWidget::create(['name' => 'second', 'user_id' => 'u1']);
        SdfWidget::create(['name' => 'third', 'user_id' => 'u1']);

        $r = (new DataQueryTool())->execute(['query' => 'list widgets'], new UnifiedActionContext('s', 'u1'));

        $this->assertSame('third', $r->data['rows'][0]['name']);
    }

    // ---------------------------------------------------------------------
    // Scenario: DataQueryTool workspace/tenant scope + auth() precedence
    // ---------------------------------------------------------------------

    public function test_data_query_applies_all_scope_columns_from_context_metadata(): void
    {
        $this->createWidgetTable();
        $this->configureWidget();

        SdfWidget::create(['name' => 'match', 'user_id' => 'u1', 'workspace_id' => 'w1', 'tenant_id' => 't1']);
        SdfWidget::create(['name' => 'wrong-ws', 'user_id' => 'u1', 'workspace_id' => 'w2', 'tenant_id' => 't1']);
        SdfWidget::create(['name' => 'wrong-tenant', 'user_id' => 'u1', 'workspace_id' => 'w1', 'tenant_id' => 't2']);

        $ctx = new UnifiedActionContext('s', 'u1', metadata: ['workspace_id' => 'w1', 'tenant_id' => 't1']);
        $r = (new DataQueryTool())->execute(['query' => 'how many widgets'], $ctx);

        $this->assertSame(1, $r->data['count']);
    }

    public function test_data_query_auth_id_takes_precedence_over_context_user(): void
    {
        $this->createWidgetTable();
        $this->configureWidget();

        SdfWidget::create(['name' => 'auth-row', 'user_id' => 'authU']);
        SdfWidget::create(['name' => 'ctx-row', 'user_id' => 'u1']);

        $authUser = new SdfAuthUser(['id' => 'authU']);
        $authUser->exists = true;
        $this->actingAs($authUser);
        $this->assertSame('authU', auth()->id());

        $ctx = new UnifiedActionContext('s', 'u1');
        $r = (new DataQueryTool())->execute(['query' => 'how many widgets'], $ctx);

        $this->assertSame(1, $r->data['count']);
        auth()->logout();
    }

    public function test_data_query_scope_columns_whitelist_disables_other_dimensions(): void
    {
        $this->createWidgetTable();
        $this->configureWidget();
        config()->set('ai-engine.data_query.scope_columns', ['user_id']);

        SdfWidget::create(['name' => 'a', 'user_id' => 'u1', 'workspace_id' => 'w1', 'tenant_id' => 't1']);
        SdfWidget::create(['name' => 'b', 'user_id' => 'u1', 'workspace_id' => 'w2', 'tenant_id' => 't2']);

        $ctx = new UnifiedActionContext('s', 'u1', metadata: ['workspace_id' => 'w1', 'tenant_id' => 't1']);
        $r = (new DataQueryTool())->execute(['query' => 'how many widgets'], $ctx);

        // workspace/tenant ignored -> both rows counted.
        $this->assertSame(2, $r->data['count']);

        config()->set('ai-engine.data_query.scope_columns', ['user_id', 'workspace_id', 'tenant_id']);
    }

    // ---------------------------------------------------------------------
    // Scenario: DataQueryTool discovery + safeDiscover swallowing
    // ---------------------------------------------------------------------

    public function test_data_query_resolves_model_via_discovery(): void
    {
        $this->createWidgetTable();
        config()->set('ai-engine.data_query.models', []);
        config()->set('ai-engine.data_query.use_discovery', true);

        SdfWidget::create(['name' => 'a', 'user_id' => 'u1']);

        $discovery = new class extends \LaravelAIEngine\Services\RAG\RAGCollectionDiscovery {
            public function __construct() {}
            public function discover(bool $useCache = true, bool $includeFederated = true): array
            {
                return [['class' => SdfWidget::class]];
            }
        };

        $tool = new DataQueryTool($discovery);
        $r = $tool->execute(['query' => 'how many sdf_widgets'], new UnifiedActionContext('s', 'u1'));

        $this->assertTrue($r->success);
        $this->assertSame('count', $r->data['operation']);
    }

    public function test_data_query_discovery_exception_is_swallowed(): void
    {
        config()->set('ai-engine.data_query.models', []);
        config()->set('ai-engine.data_query.use_discovery', true);

        $discovery = new class extends \LaravelAIEngine\Services\RAG\RAGCollectionDiscovery {
            public function __construct() {}
            public function discover(bool $useCache = true, bool $includeFederated = true): array
            {
                throw new \RuntimeException('discovery exploded');
            }
        };

        $tool = new DataQueryTool($discovery);
        $r = $tool->execute(['query' => 'how many widgets'], new UnifiedActionContext('s', 'u1'));

        $this->assertFalse($r->success);
        $this->assertSame('No queryable data models are configured.', $r->message);
    }

    public function test_data_query_discovery_filters_non_model_entries(): void
    {
        $this->createWidgetTable();
        config()->set('ai-engine.data_query.models', []);
        config()->set('ai-engine.data_query.use_discovery', true);

        SdfWidget::create(['name' => 'a', 'user_id' => 'u1']);

        $discovery = new class extends \LaravelAIEngine\Services\RAG\RAGCollectionDiscovery {
            public function __construct() {}
            public function discover(bool $useCache = true, bool $includeFederated = true): array
            {
                return [['model' => SdfWidget::class], 'NotAModelClass', SdfWidget::class];
            }
        };

        $tool = new DataQueryTool($discovery);
        $r = $tool->execute(['query' => 'how many sdf_widgets'], new UnifiedActionContext('s', 'u1'));

        $this->assertTrue($r->success);
        $this->assertSame(1, $r->data['count']);
    }

    // ---------------------------------------------------------------------
    // Scenario: RAGAggregateService standalone edge cases
    // ---------------------------------------------------------------------

    public function test_aggregate_invalid_operation_normalizes_to_sum(): void
    {
        Schema::dropIfExists('sdf_agg');
        Schema::create('sdf_agg', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->decimal('total', 12, 2)->default(0);
            $t->timestamps();
        });
        SdfAggModel::create(['user_id' => 9, 'total' => 100]);
        SdfAggModel::create(['user_id' => 9, 'total' => 200]);

        $service = new RAGAggregateService(new RAGDecisionPolicy());
        $result = $service->aggregate(
            ['model' => 'invoice', 'aggregate' => ['operation' => 'median', 'field' => 'total']],
            9,
            [],
            $this->deps(SdfAggModel::class, ['user_field' => 'user_id'])
        );

        $this->assertSame('sum', $result['operation']);
        $this->assertSame(300.0, (float) $result['result']);
    }

    public function test_aggregate_no_aggregable_field_errors(): void
    {
        Schema::dropIfExists('sdf_agg_nofield');
        Schema::create('sdf_agg_nofield', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('label')->nullable();
            $t->timestamps();
        });
        SdfAggNoFieldModel::create(['user_id' => 9, 'label' => 'x']);

        $service = new RAGAggregateService(new RAGDecisionPolicy());
        $result = $service->aggregate(
            ['model' => 'invoice', 'aggregate' => ['operation' => 'sum']],
            9,
            [],
            $this->deps(SdfAggNoFieldModel::class, ['user_field' => 'user_id'])
        );

        $this->assertFalse($result['success']);
        $this->assertSame('No field specified for aggregation', $result['error']);
    }

    public function test_aggregate_missing_group_column_errors(): void
    {
        Schema::dropIfExists('sdf_agg');
        Schema::create('sdf_agg', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->decimal('total', 12, 2)->default(0);
            $t->timestamps();
        });
        SdfAggModel::create(['user_id' => 9, 'total' => 100]);

        $service = new RAGAggregateService(new RAGDecisionPolicy());
        $result = $service->aggregate(
            ['model' => 'invoice', 'aggregate' => ['operation' => 'sum', 'field' => 'total', 'group_by' => 'nonexistent_col']],
            9,
            [],
            $this->deps(SdfAggModel::class, ['user_field' => 'user_id'])
        );

        $this->assertFalse($result['success']);
        $this->assertSame("Group field 'nonexistent_col' not found for aggregation", $result['error']);
    }

    public function test_aggregate_model_method_calculation_is_not_fast_path(): void
    {
        Schema::dropIfExists('sdf_agg_method');
        Schema::create('sdf_agg_method', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->decimal('base', 12, 2)->default(0);
            $t->timestamps();
        });
        SdfAggMethodModel::create(['user_id' => 9, 'base' => 100]);
        SdfAggMethodModel::create(['user_id' => 9, 'base' => 200]);

        $service = new RAGAggregateService(new RAGDecisionPolicy());
        $result = $service->aggregate(
            ['model' => 'invoice', 'aggregate' => ['operation' => 'sum', 'field' => 'computedTotal']],
            9,
            [],
            $this->deps(SdfAggMethodModel::class, ['user_field' => 'user_id'])
        );

        $this->assertTrue($result['success']);
        $this->assertSame('model_method', $result['calculation_method']);
        $this->assertFalse($result['fast_path']);
        $this->assertSame(300.0, (float) $result['result']);
    }

    public function test_aggregate_summary_on_non_column_errors(): void
    {
        Schema::dropIfExists('sdf_agg_method');
        Schema::create('sdf_agg_method', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->decimal('base', 12, 2)->default(0);
            $t->timestamps();
        });
        SdfAggMethodModel::create(['user_id' => 9, 'base' => 100]);

        $service = new RAGAggregateService(new RAGDecisionPolicy());
        $result = $service->aggregate(
            ['model' => 'invoice', 'aggregate' => ['operation' => 'summary', 'field' => 'computed_total']],
            9,
            [],
            $this->deps(SdfAggMethodModel::class, ['user_field' => 'user_id'])
        );

        $this->assertFalse($result['success']);
        $this->assertSame("Field 'computed_total' must be a database column for summary aggregation", $result['error']);
    }

    // ---------------------------------------------------------------------
    // Scenario: cross-surface parity DataQueryTool <-> RAGStructuredDataService
    // ---------------------------------------------------------------------

    public function test_parity_count_and_list_agree_across_engines_and_querynext_continues(): void
    {
        // Shared scoped data: user_id = u9 / created_by alignment.
        Schema::dropIfExists('sdf_parity');
        Schema::create('sdf_parity', function (Blueprint $t): void {
            $t->id();
            $t->string('user_id')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        for ($i = 1; $i <= 12; $i++) {
            SdfParityModel::create(['user_id' => 'u9', 'name' => "P-{$i}"]);
        }
        // Noise for another user that must not be counted.
        SdfParityModel::create(['user_id' => 'other', 'name' => 'noise']);

        // DataQueryTool count.
        config()->set('ai-engine.data_query.models', [
            'parity' => [
                'class' => SdfParityModel::class,
                'aliases' => ['parity', 'parities'],
                'list' => ['id', 'name'],
            ],
        ]);
        config()->set('ai-engine.data_query.use_discovery', false);

        $ctx = new UnifiedActionContext('s', 'u9');
        $dqCount = (new DataQueryTool())->execute(['query' => 'how many parity'], $ctx);

        // RAGStructuredDataService count over the same scoped data.
        $state = new RAGDecisionStateService(new RAGDecisionPolicy());
        $service = $this->service($state);
        $deps = $this->deps(SdfParityModel::class, ['user_field' => 'user_id']);

        $ragCount = $service->count(['model' => 'parity'], 'u9', [], $deps);

        $this->assertSame($dqCount->data['count'], $ragCount['count']);
        $this->assertSame(12, $ragCount['count']);

        // list size vs query count parity.
        $dqList = (new DataQueryTool())->execute(['query' => 'list parity', 'limit' => 50], $ctx);
        $ragQuery = $service->query(['model' => 'parity'], 'u9', ['session_id' => 'parity-1'], $deps);
        $this->assertSame(count($dqList->data['rows']), $ragQuery['total_count']);

        // queryNext continuation under the same scope advances to page 2.
        $captured = null;
        $service->queryNext([], 'u9', ['session_id' => 'parity-1'], function (array $params, $userId, array $options, int $page) use (&$captured) {
            $captured = ['user' => $userId, 'page' => $page, 'model' => $params['model']];

            return ['page' => $page];
        });

        $this->assertSame('u9', $captured['user']);
        $this->assertSame(2, $captured['page']);
        $this->assertSame('parity', $captured['model']);
    }
}

// =========================================================================
// Fixtures
// =========================================================================

class SdfScopeMethodModel extends Model
{
    protected $table = 'sdf_scope_method';
    protected $guarded = [];

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForTenant($query, $tenant)
    {
        return $query->where('tenant_id', $tenant);
    }

    public function scopeForWorkspace($query, $workspace)
    {
        return $query->where('workspace_id', $workspace);
    }
}

class SdfScopeFieldsModel extends Model
{
    protected $table = 'sdf_scope_fields';
    protected $guarded = [];
}

class SdfNoTenantModel extends Model
{
    protected $table = 'sdf_no_tenant';
    protected $guarded = [];
}

class SdfNoScopeModel extends Model
{
    protected $table = 'sdf_no_scope';
    protected $guarded = [];
}

class SdfCreatedByModel extends Model
{
    protected $table = 'sdf_created_by';
    protected $guarded = [];
}

class SdfOwnerModel extends Model
{
    protected $table = 'sdf_owner';
    protected $guarded = [];
}

class SdfOrgModel extends Model
{
    protected $table = 'sdf_org';
    protected $guarded = [];
}

class SdfInvoiceModel extends Model
{
    protected $table = 'sdf_invoices';
    protected $guarded = [];
}

class SdfDetailModel extends Model
{
    protected $table = 'sdf_detail';
    protected $guarded = [];

    public function toRAGDetail(): string
    {
        return "DETAIL: {$this->name}";
    }
}

class SdfToStringModel extends Model
{
    protected $table = 'sdf_tostring';
    protected $guarded = [];

    public function __toString(): string
    {
        return "STRING: {$this->name}";
    }
}

class SdfBareModel extends Model
{
    protected $table = 'sdf_bare';
    protected $guarded = [];
}

class SdfGhostModel extends Model
{
    protected $table = 'sdf_ghost';
    protected $guarded = [];
}

class SdfWidget extends Model
{
    protected $table = 'sdf_widgets';
    protected $guarded = [];
    public $timestamps = false;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        // Enable timestamps only if the column exists at runtime.
        $this->timestamps = Schema::hasColumn('sdf_widgets', 'created_at');
    }
}

class SdfAuthUser extends \Illuminate\Foundation\Auth\User
{
    protected $table = 'sdf_auth_users';
    protected $guarded = [];
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
}

class SdfAggModel extends Model
{
    protected $table = 'sdf_agg';
    protected $guarded = [];
}

class SdfAggNoFieldModel extends Model
{
    protected $table = 'sdf_agg_nofield';
    protected $guarded = [];
}

class SdfAggMethodModel extends Model
{
    protected $table = 'sdf_agg_method';
    protected $guarded = [];

    public function getComputedTotal()
    {
        return (float) $this->base;
    }
}

class SdfParityModel extends Model
{
    protected $table = 'sdf_parity';
    protected $guarded = [];
}
