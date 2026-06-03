<?php

namespace LaravelAIEngine\Tests\Unit\Services\Structured;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\DataQueryTool;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Services\RAG\RAGDecisionStateService;
use LaravelAIEngine\Services\RAG\RAGStructuredDataService;
use LaravelAIEngine\Tests\TestCase;

/**
 * Anti-divergence tripwire for the two intentionally-separate structured-query engines
 * (see docs/decisions/0001-classic-routing-path.md "Structured-query boundary").
 *
 * DataQueryTool (the local, fail-open, AI-free AiNative arm) and RAGStructuredDataService
 * (the federation-aware, fail-closed, paginated RAG engine) are NOT consolidated by design.
 * This test pins that, GIVEN identical scoped data + equivalent filters, both engines agree
 * on the numeric count and list size — so they cannot silently drift apart. It asserts ONLY
 * numeric/semantic fields, never the (legitimately divergent) localized/markdown messages,
 * and is deliberately federation-free (local model, existing table) so it produces identical
 * results under the core and federation-installed suites and never trips should_route_to_node.
 */
class StructuredQueryParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sq_parity_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->string('user_id')->nullable();
            $table->timestamps();
        });

        // DataQueryTool side: config-driven local resolution, scoped by user_id.
        config()->set('ai-engine.data_query.models', [
            'widget' => [
                'class' => SqParityWidget::class,
                'aliases' => ['widget', 'widgets'],
                'list' => ['id', 'name', 'status'],
                'statuses' => ['active', 'archived'],
            ],
        ]);
        config()->set('ai-engine.data_query.use_discovery', false);

        // Identical scoped dataset for user 'u1' (small — under both engines' page limits),
        // plus 'u2' rows to prove user-scoping excludes them on BOTH engines.
        foreach (['active', 'active', 'active', 'archived', 'archived'] as $i => $status) {
            SqParityWidget::create(['name' => "w{$i}", 'status' => $status, 'user_id' => 'u1']);
        }
        SqParityWidget::create(['name' => 'other-a', 'status' => 'active', 'user_id' => 'u2']);
        SqParityWidget::create(['name' => 'other-b', 'status' => 'archived', 'user_id' => 'u2']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sq_parity_widgets');
        parent::tearDown();
    }

    private function ragService(): RAGStructuredDataService
    {
        return new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy()
        );
    }

    /**
     * Dependency map equivalent to what RAGDecisionEngine supplies, wired so the
     * fail-closed RAGModelScopeGuard ALLOWS via the user-scope field (matching
     * DataQueryTool's user_id scoping) and applies the same status filter.
     *
     * @return array<string, callable>
     */
    private function ragDependencies(): array
    {
        return [
            'findModelClass' => fn (string $modelName, array $options) => SqParityWidget::class,
            'getFilterConfigForModel' => fn (string $modelClass) => ['user_field' => 'user_id'],
            'applyFilters' => function ($query, array $filters, string $modelClass, array $options) {
                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }

                return $query;
            },
            'findModelConfigClass' => fn (string $modelClass) => null,
        ];
    }

    public function test_count_parity_unfiltered_and_user_scoped(): void
    {
        $context = new UnifiedActionContext('sq-parity', 'u1');

        $tool = (new DataQueryTool())->execute(['query' => 'how many widgets'], $context);
        $rag = $this->ragService()->count(['model' => 'widget', 'filters' => []], 'u1', ['session_id' => null], $this->ragDependencies());

        $this->assertTrue($tool->success);
        $this->assertTrue($rag['success'] ?? false, 'RAG count must not be scope-blocked or route-signalled for a local user-scoped model.');
        $this->assertSame(5, $tool->data['count'], 'DataQueryTool must count only the u1-scoped rows.');
        $this->assertSame($tool->data['count'], $rag['count'], 'Both structured-query engines must agree on the unfiltered scoped count.');
    }

    public function test_count_parity_with_status_filter(): void
    {
        $context = new UnifiedActionContext('sq-parity', 'u1');

        $tool = (new DataQueryTool())->execute(['query' => 'how many archived widgets'], $context);
        $rag = $this->ragService()->count(['model' => 'widget', 'filters' => ['status' => 'archived']], 'u1', ['session_id' => null], $this->ragDependencies());

        $this->assertSame(2, $tool->data['count']);
        $this->assertSame('archived', $tool->data['status']);
        $this->assertSame($tool->data['count'], $rag['count'], 'Both engines must agree on the status-filtered scoped count.');
    }

    public function test_list_size_parity_user_scoped(): void
    {
        $context = new UnifiedActionContext('sq-parity', 'u1');

        $tool = (new DataQueryTool())->execute(['query' => 'list widgets'], $context);
        $rag = $this->ragService()->query(['model' => 'widget', 'filters' => []], 'u1', ['session_id' => null], $this->ragDependencies());

        $this->assertSame('list', $tool->data['operation']);
        $this->assertTrue($rag['success'] ?? false);
        // Dataset (5) is under both engines' page limits, so DataQueryTool's returned rows
        // equal RAGStructuredDataService's total scoped count.
        $this->assertCount(5, $tool->data['rows']);
        $this->assertSame(count($tool->data['rows']), $rag['total_count'], 'Both engines must agree on the scoped list size.');
    }
}

class SqParityWidget extends Model
{
    protected $table = 'sq_parity_widgets';

    protected $fillable = ['name', 'status', 'user_id'];
}
