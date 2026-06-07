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
 * Cross-entity routing: "best selling product" must aggregate the SALES (line-item) records
 * grouped by product_name, while "average product price" stays on the catalog — the resolver
 * picks the entity that owns the referenced metric, then the one grouping by the named
 * dimension.
 */
class AggregateQueryRelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('aq_catalog', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('aq_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('product_name');
            $table->decimal('qty', 10, 2)->default(0);
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamps();
        });

        config()->set('ai-engine.data_query.use_discovery', false);
        config()->set('ai-engine.data_query.models', [
            'product' => [
                'class' => AqCatalog::class,
                'public' => true,
                'label' => 'product',
                'aliases' => ['product', 'products', 'catalog'],
                'aggregatable' => ['price'],
            ],
            'sale' => [
                'class' => AqSale::class,
                'public' => true,
                'label' => 'sale',
                'aliases' => ['sale', 'sales', 'sold', 'selling', 'line item'],
                'list' => ['id', 'product_name', 'qty', 'amount'],
                'aggregatable' => ['qty', 'amount'],
                'groupable' => ['product_name'],
                'metric_aliases' => ['sold' => 'qty', 'units' => 'qty', 'revenue' => 'amount'],
            ],
        ]);

        AqCatalog::create(['name' => 'A', 'price' => 100]);
        AqCatalog::create(['name' => 'B', 'price' => 300]);
        AqSale::create(['product_name' => 'A', 'qty' => 5, 'amount' => 500]);
        AqSale::create(['product_name' => 'A', 'qty' => 5, 'amount' => 500]);
        AqSale::create(['product_name' => 'B', 'qty' => 1, 'amount' => 300]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('aq_catalog');
        Schema::dropIfExists('aq_sales');
        parent::tearDown();
    }

    private function ask(string $query): \LaravelAIEngine\DTOs\ActionResult
    {
        return (new AggregateQueryTool())->execute(['query' => $query], new UnifiedActionContext('aq', null));
    }

    public function test_best_selling_product_aggregates_sales_by_product_name(): void
    {
        $r = $this->ask('what is the best selling product');

        $this->assertTrue($r->success);
        $this->assertSame('sale', $r->data['entity'], 'units-sold lives on the sales records, not the catalog.');
        $this->assertSame('qty', $r->data['metric']);
        $this->assertSame('product_name', $r->data['group_by']);
        $this->assertSame('A', $r->data['groups'][0]['key']);   // 10 units vs 1
        $this->assertEqualsWithDelta(10.0, $r->data['groups'][0]['value'], 0.001);
    }

    public function test_top_products_by_revenue_uses_sales_amount(): void
    {
        $r = $this->ask('top products by revenue');

        $this->assertSame('sale', $r->data['entity']);
        $this->assertSame('amount', $r->data['metric']);
        $this->assertSame('product_name', $r->data['group_by']);
        $this->assertSame('A', $r->data['groups'][0]['key']);   // 1000 vs 300
        $this->assertEqualsWithDelta(1000.0, $r->data['groups'][0]['value'], 0.001);
    }

    public function test_average_product_price_stays_on_the_catalog(): void
    {
        $r = $this->ask('what is the average product price');

        $this->assertSame('product', $r->data['entity'], 'price is a catalog metric, not a sales metric.');
        $this->assertSame('price', $r->data['metric']);
        $this->assertNull($r->data['group_by'] ?? null);
        $this->assertEqualsWithDelta(200.0, $r->data['value'], 0.001);
    }

    public function test_how_many_products_counts_the_catalog(): void
    {
        $r = $this->ask('how many products are there');

        $this->assertSame('product', $r->data['entity']);
        $this->assertSame('count', $r->data['operation']);
        $this->assertSame(2, $r->data['value']);
    }

    public function test_count_with_group_by_is_a_distinct_dimension_count(): void
    {
        // Two distinct products (A, B) across three sales rows.
        $r = (new AggregateQueryTool())->execute(
            ['entity' => 'sale', 'group_by' => 'product_name', 'operation' => 'count'],
            new UnifiedActionContext('aq', null)
        );

        $this->assertSame('count_distinct', $r->data['operation']);
        $this->assertSame(2, $r->data['value'], 'count + group_by = number of distinct dimension values.');
    }

    public function test_units_sold_is_a_sum_not_a_row_count(): void
    {
        $r = $this->ask('how many units sold per product');

        $this->assertSame('sale', $r->data['entity']);
        $this->assertSame('sum', $r->data['operation']);
        $this->assertSame('qty', $r->data['metric']);
        $this->assertSame('product_name', $r->data['group_by']);
        $this->assertEqualsWithDelta(10.0, $r->data['groups'][0]['value'], 0.001);
    }
}

class AqCatalog extends Model
{
    protected $table = 'aq_catalog';

    protected $fillable = ['name', 'price'];
}

class AqSale extends Model
{
    protected $table = 'aq_sales';

    protected $fillable = ['product_name', 'qty', 'amount'];
}
