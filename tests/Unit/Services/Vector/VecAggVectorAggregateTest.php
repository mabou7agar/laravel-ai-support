<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Services\Vector\ChunkingService;
use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vector\VectorAccessControl;
use LaravelAIEngine\Services\Vector\VectorDriverManager;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use LaravelAIEngine\Tests\TestCase;

/**
 * Covers the hybrid aggregate API in VectorSearchService:
 * aggregate/sum/avg/min/max/countWithFilters/getMatchingIds.
 *
 * The vector driver is faked so getMatchingIds() / count() return controlled
 * values; the SQL aggregation runs against a real (sqlite) test table.
 */
class VecAggVectorAggregateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('vecagg_invoices');
        Schema::create('vecagg_invoices', function (Blueprint $table): void {
            $table->id();
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });

        VecAggFakeDriver::$matchingIds = [];
        VecAggFakeDriver::$count = 0;
        VecAggFakeDriver::$lastCollection = null;
        VecAggFakeDriver::$lastFilters = null;
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('vecagg_invoices');
        parent::tearDown();
    }

    private function makeService(): VectorSearchService
    {
        $driver = new VecAggFakeDriver();

        $driverManager = $this->createMock(VectorDriverManager::class);
        $driverManager->method('driver')->willReturn($driver);

        return new VectorSearchService(
            $driverManager,
            $this->createMock(EmbeddingService::class),
            $this->createMock(VectorAccessControl::class),
            app(SearchDocumentBuilder::class),
            app(ChunkingService::class)
        );
    }

    private function seedInvoices(): void
    {
        // id => total
        VecAggInvoice::query()->create(['total' => 100.00]); // 1
        VecAggInvoice::query()->create(['total' => 200.00]); // 2
        VecAggInvoice::query()->create(['total' => 50.00]);  // 3 (NOT matched)
        VecAggInvoice::query()->create(['total' => 30.00]);  // 4
    }

    public function test_aggregate_sum_avg_min_max_over_matched_ids(): void
    {
        $this->seedInvoices();
        VecAggFakeDriver::$matchingIds = [1, 2, 4]; // excludes id 3 (50)

        $service = $this->makeService();
        $model = VecAggInvoice::class;
        $filters = ['user_id' => 7];

        // Matched totals: 100, 200, 30 => sum 330, avg 110, min 30, max 200
        $this->assertSame(330.0, $service->aggregate($model, 'sum', 'total', $filters));
        $this->assertSame(110.0, $service->aggregate($model, 'avg', 'total', $filters));
        $this->assertSame(30.0, $service->aggregate($model, 'min', 'total', $filters));
        $this->assertSame(200.0, $service->aggregate($model, 'max', 'total', $filters));
        $this->assertSame(3, $service->aggregate($model, 'count', 'total', $filters));

        // Driver received the resolved collection name + filters.
        $this->assertSame('vec_vecagg_invoices', VecAggFakeDriver::$lastCollection);
        $this->assertSame($filters, VecAggFakeDriver::$lastFilters);
    }

    public function test_sum_avg_min_max_helpers_delegate_to_aggregate(): void
    {
        $this->seedInvoices();
        VecAggFakeDriver::$matchingIds = [1, 2, 4];

        $service = $this->makeService();
        $model = VecAggInvoice::class;

        $this->assertSame(330.0, $service->sum($model, 'total'));
        $this->assertSame(110.0, $service->avg($model, 'total'));
        $this->assertSame(30.0, $service->min($model, 'total'));
        $this->assertSame(200.0, $service->max($model, 'total'));
    }

    public function test_aggregate_returns_zero_when_no_ids_match(): void
    {
        $this->seedInvoices();
        VecAggFakeDriver::$matchingIds = [];

        $service = $this->makeService();

        $this->assertSame(0, $service->aggregate(VecAggInvoice::class, 'sum', 'total'));
        $this->assertSame(0.0, $service->sum(VecAggInvoice::class, 'total'));
    }

    public function test_aggregate_returns_zero_on_unknown_operation(): void
    {
        $this->seedInvoices();
        VecAggFakeDriver::$matchingIds = [1, 2];

        $service = $this->makeService();

        // Unknown operation throws inside the try/catch and is swallowed to 0.
        $this->assertSame(0, $service->aggregate(VecAggInvoice::class, 'median', 'total'));
    }

    public function test_count_with_filters_uses_driver_count_not_sql(): void
    {
        $this->seedInvoices();
        VecAggFakeDriver::$count = 42;       // driver-reported count
        VecAggFakeDriver::$matchingIds = [1]; // would-be SQL count is different

        $service = $this->makeService();

        $this->assertSame(42, $service->countWithFilters(VecAggInvoice::class, ['user_id' => 7]));
        $this->assertSame('vec_vecagg_invoices', VecAggFakeDriver::$lastCollection);
        $this->assertSame(['user_id' => 7], VecAggFakeDriver::$lastFilters);
    }

    public function test_get_matching_ids_returns_deduped_driver_ids(): void
    {
        $service = $this->makeService();
        VecAggFakeDriver::$matchingIds = [10, 20, 30];

        $ids = $service->getMatchingIds(VecAggInvoice::class, ['user_id' => 7]);

        $this->assertSame([10, 20, 30], $ids);
        $this->assertSame('vec_vecagg_invoices', VecAggFakeDriver::$lastCollection);
    }
}

/**
 * Eloquent test model backing the SQL aggregation.
 */
class VecAggInvoice extends Model
{
    protected $table = 'vecagg_invoices';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = ['total' => 'float'];
}

/**
 * Fake vector driver. Returns controlled matching ids / counts and records the
 * last collection + filters it was asked about so callers can be asserted.
 */
class VecAggFakeDriver implements VectorDriverInterface
{
    /** @var array<int|string> */
    public static array $matchingIds = [];

    public static int $count = 0;

    public static ?string $lastCollection = null;

    /** @var array<string,mixed>|null */
    public static ?array $lastFilters = null;

    public function getMatchingIds(string $collection, array $filters = []): array
    {
        self::$lastCollection = $collection;
        self::$lastFilters = $filters;

        return self::$matchingIds;
    }

    public function count(string $collection, array $filters = []): int
    {
        self::$lastCollection = $collection;
        self::$lastFilters = $filters;

        return self::$count;
    }

    public function createCollection(string $name, int $dimensions, array $config = []): bool
    {
        return true;
    }

    public function deleteCollection(string $name): bool
    {
        return true;
    }

    public function collectionExists(string $name): bool
    {
        return true;
    }

    public function upsert(string $collection, array $vectors): bool
    {
        return true;
    }

    public function search(string $collection, array $vector, int $limit = 10, float $threshold = 0.0, array $filters = []): array
    {
        return [];
    }

    public function delete(string $collection, array $ids): bool
    {
        return true;
    }

    public function getCollectionInfo(string $collection): array
    {
        return [];
    }

    public function get(string $collection, string $id): ?array
    {
        return null;
    }

    public function updateMetadata(string $collection, string $id, array $metadata): bool
    {
        return true;
    }

    public function scroll(string $collection, int $limit = 100, ?string $offset = null): array
    {
        return [];
    }
}
