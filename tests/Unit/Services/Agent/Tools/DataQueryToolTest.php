<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\DataQueryTool;
use LaravelAIEngine\Tests\TestCase;

class DataQueryToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dq_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->nullable();
            $table->string('user_id')->nullable();
            $table->timestamps();
        });

        config()->set('ai-engine.data_query.models', [
            'widget' => [
                'class' => DqWidget::class,
                'aliases' => ['widget', 'widgets'],
                'list' => ['id', 'name', 'status'],
                'statuses' => ['active', 'archived'],
            ],
        ]);
        config()->set('ai-engine.data_query.use_discovery', false);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('dq_widgets');
        parent::tearDown();
    }

    private function tool(): DataQueryTool
    {
        return new DataQueryTool();
    }

    public function test_counts_records_scoped_to_user(): void
    {
        DqWidget::create(['name' => 'a', 'status' => 'active', 'user_id' => 'u1']);
        DqWidget::create(['name' => 'b', 'status' => 'archived', 'user_id' => 'u1']);
        DqWidget::create(['name' => 'c', 'status' => 'active', 'user_id' => 'u2']);

        $r = $this->tool()->execute(['query' => 'how many widgets'], new UnifiedActionContext('dq', 'u1'));

        $this->assertTrue($r->success);
        $this->assertSame('count', $r->data['operation']);
        $this->assertSame(2, $r->data['count']);
    }

    public function test_count_applies_status_filter(): void
    {
        DqWidget::create(['name' => 'a', 'status' => 'active', 'user_id' => 'u1']);
        DqWidget::create(['name' => 'b', 'status' => 'archived', 'user_id' => 'u1']);

        $r = $this->tool()->execute(['query' => 'how many archived widgets'], new UnifiedActionContext('dq', 'u1'));

        $this->assertSame(1, $r->data['count']);
        $this->assertSame('archived', $r->data['status']);
    }

    public function test_lists_records(): void
    {
        DqWidget::create(['name' => 'a', 'status' => 'active', 'user_id' => 'u1']);
        DqWidget::create(['name' => 'b', 'status' => 'active', 'user_id' => 'u1']);

        $r = $this->tool()->execute(['query' => 'list widgets'], new UnifiedActionContext('dq', 'u1'));

        $this->assertSame('list', $r->data['operation']);
        $this->assertCount(2, $r->data['rows']);
        $this->assertArrayHasKey('name', $r->data['rows'][0]);
    }

    public function test_unknown_entity_asks_for_clarification(): void
    {
        $r = $this->tool()->execute(['query' => 'what is the weather'], new UnifiedActionContext('dq', 'u1'));

        $this->assertFalse($r->success);
        $this->assertTrue($r->metadata['needs_user_input'] ?? false);
    }

    // ------------------------------------------------------------------
    // Fail-closed scoping: a query with no applicable access scope must be
    // refused by default, so a model can't leak every row to any caller.
    // ------------------------------------------------------------------
    public function test_blocks_query_when_no_access_scope_applies(): void
    {
        DqWidget::create(['name' => 'a', 'status' => 'active', 'user_id' => 'u1']);

        // No auth and no userId on the context -> no user/workspace/tenant scope applies.
        $r = $this->tool()->execute(['query' => 'how many widgets'], new UnifiedActionContext('dq', null));

        $this->assertFalse($r->success);
        $this->assertStringContainsString('blocked', strtolower((string) ($r->error ?? $r->message)));
    }

    public function test_public_model_is_queryable_without_scope(): void
    {
        config()->set('ai-engine.data_query.models.widget.public', true);
        DqWidget::create(['name' => 'a', 'status' => 'active', 'user_id' => 'u1']);
        DqWidget::create(['name' => 'b', 'status' => 'active', 'user_id' => 'u2']);

        $r = $this->tool()->execute(['query' => 'how many widgets'], new UnifiedActionContext('dq', null));

        $this->assertTrue($r->success);
        $this->assertSame(2, $r->data['count'], 'a public model returns all rows regardless of caller scope.');
    }

    public function test_require_scope_disabled_allows_unscoped_query(): void
    {
        config()->set('ai-engine.data_query.require_scope', false);
        DqWidget::create(['name' => 'a', 'status' => 'active', 'user_id' => 'u1']);
        DqWidget::create(['name' => 'b', 'status' => 'active', 'user_id' => 'u2']);

        $r = $this->tool()->execute(['query' => 'how many widgets'], new UnifiedActionContext('dq', null));

        $this->assertTrue($r->success);
        $this->assertSame(2, $r->data['count']);
    }
}

class DqWidget extends Model
{
    protected $table = 'dq_widgets';
    protected $fillable = ['name', 'status', 'user_id'];
}
