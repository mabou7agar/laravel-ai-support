<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AggregateQueryTool;
use LaravelAIEngine\Tests\TestCase;

/**
 * aggregate_data computes exact SUM/AVG/MIN/MAX, ranked records, and GROUP BY breakdowns —
 * the analytics questions data_query (count/list) answers incorrectly by sampling a list.
 */
class AggregateQueryToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('aq_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('customer');
            $table->string('status')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('user_id')->nullable();
            $table->timestamps();
        });

        config()->set('ai-engine.data_query.use_discovery', false);
        config()->set('ai-engine.data_query.models', [
            'order' => [
                'class' => AqOrder::class,
                'public' => true,
                'aliases' => ['order', 'orders'],
                'list' => ['id', 'customer', 'amount', 'status'],
                'aggregatable' => ['amount'],
                'groupable' => ['customer', 'status'],
                'metric_aliases' => ['revenue' => 'amount', 'spent' => 'amount'],
            ],
        ]);

        AqOrder::create(['customer' => 'Apollo', 'status' => 'paid', 'amount' => 100]);
        AqOrder::create(['customer' => 'Apollo', 'status' => 'paid', 'amount' => 300]);
        AqOrder::create(['customer' => 'Mohamed', 'status' => 'draft', 'amount' => 50]);
        AqOrder::create(['customer' => 'Mohamed', 'status' => 'paid', 'amount' => 250]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('aq_orders');
        parent::tearDown();
    }

    private function tool(): AggregateQueryTool
    {
        return new AggregateQueryTool();
    }

    private function aggregate(array $params): \LaravelAIEngine\DTOs\ActionResult
    {
        return $this->tool()->execute($params, new UnifiedActionContext('aq', null));
    }

    public function test_sum_over_all_rows(): void
    {
        $r = $this->aggregate(['query' => 'what is the total revenue for orders']);

        $this->assertTrue($r->success);
        $this->assertSame('sum', $r->data['operation']);
        $this->assertSame('amount', $r->data['metric']);
        $this->assertEqualsWithDelta(700.0, $r->data['value'], 0.001);
    }

    public function test_average(): void
    {
        $r = $this->aggregate(['query' => 'average order amount']);

        $this->assertSame('avg', $r->data['operation']);
        $this->assertEqualsWithDelta(175.0, $r->data['value'], 0.001);
    }

    public function test_top_records_for_most_expensive(): void
    {
        $r = $this->aggregate(['query' => 'which order is the most expensive', 'limit' => 2]);

        $this->assertSame('top', $r->data['operation']);
        $this->assertSame('desc', $r->data['direction']);
        $this->assertSame(300.0, (float) $r->data['rows'][0]['amount']);
        $this->assertCount(2, $r->data['rows']);
    }

    public function test_bottom_records_for_cheapest(): void
    {
        $r = $this->aggregate(['query' => 'what is the cheapest order']);

        $this->assertSame('bottom', $r->data['operation']);
        $this->assertSame('asc', $r->data['direction']);
        $this->assertSame(50.0, (float) $r->data['rows'][0]['amount']);
    }

    public function test_group_by_customer_ranks_top_spender(): void
    {
        $r = $this->aggregate(['query' => 'which customer has spent the most']);

        $this->assertTrue($r->success);
        $this->assertSame('customer', $r->data['group_by']);
        $this->assertSame('Apollo', $r->data['groups'][0]['key']);
        $this->assertEqualsWithDelta(400.0, $r->data['groups'][0]['value'], 0.001);
        $this->assertSame(2, $r->data['groups'][0]['count']);
        // Mohamed second at 300.
        $this->assertSame('Mohamed', $r->data['groups'][1]['key']);
    }

    public function test_status_filter_narrows_the_aggregate(): void
    {
        $r = $this->aggregate(['query' => 'total revenue for paid orders']);

        $this->assertSame('paid', $r->data['status']);
        $this->assertEqualsWithDelta(650.0, $r->data['value'], 0.001); // 100+300+250
    }

    public function test_explicit_structured_params_win(): void
    {
        $r = $this->aggregate(['query' => 'orders', 'operation' => 'max', 'metric' => 'amount']);

        $this->assertSame('max', $r->data['operation']);
        $this->assertEqualsWithDelta(300.0, $r->data['value'], 0.001);
    }

    public function test_count_does_not_need_a_metric(): void
    {
        $r = $this->aggregate(['query' => 'how many orders are there']);

        $this->assertSame('count', $r->data['operation']);
        $this->assertSame(4, $r->data['value']);
    }

    public function test_fails_closed_without_scope_on_non_public_model(): void
    {
        config()->set('ai-engine.data_query.models.order.public', false);

        $r = $this->aggregate(['query' => 'total revenue for orders']);

        $this->assertFalse($r->success);
        $this->assertStringContainsString('blocked', strtolower((string) ($r->error ?? $r->message)));
    }

    public function test_non_aggregatable_column_is_refused(): void
    {
        // 'status' is groupable but not aggregatable; an explicit metric not in the allowlist
        // is ignored and we fall back to the allowlisted column — never an arbitrary column.
        $r = $this->aggregate(['query' => 'sum of orders', 'metric' => 'user_id']);

        $this->assertTrue($r->success);
        $this->assertSame('amount', $r->data['metric'], 'a non-allowlisted metric must not be used.');
    }
}

class AqOrder extends Model
{
    protected $table = 'aq_orders';

    protected $fillable = ['customer', 'status', 'amount', 'user_id'];
}
