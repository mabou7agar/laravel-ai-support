<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Services\RAG\AutonomousRAGModelMetadataService;
use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;
use LaravelAIEngine\Services\RAG\AutonomousRAGStateService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AutonomousRAGModelMetadataServiceTest extends UnitTestCase
{
    public function test_find_model_class_can_infer_local_app_model_by_name(): void
    {
        if (!class_exists('App\\Models\\Shipment')) {
            eval(<<<'PHP'
namespace App\Models;

class Shipment extends \Illuminate\Database\Eloquent\Model
{
}
PHP);
        }

        $service = new AutonomousRAGModelMetadataService(
            null,
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            null
        );

        $this->assertSame('App\\Models\\Shipment', $service->findModelClass('shipments', []));
    }

    public function test_find_model_class_can_resolve_from_collector_model_class(): void
    {
        if (!class_exists('App\\Models\\BillingRecord')) {
            eval(<<<'PHP'
namespace App\Models;

class BillingRecord extends \Illuminate\Database\Eloquent\Model
{
}
PHP);
        }

        $collectorDiscovery = $this->createMock(AutonomousCollectorDiscoveryService::class);
        $collectorDiscovery
            ->method('discoverCollectors')
            ->willReturn([
                'invoice' => [
                    'model_class' => 'App\\Models\\BillingRecord',
                    'source' => 'local',
                ],
            ]);

        $service = new AutonomousRAGModelMetadataService(
            null,
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            $collectorDiscovery
        );

        $this->assertSame('App\\Models\\BillingRecord', $service->findModelClass('invoice', []));
    }

    public function test_apply_filters_supports_relation_foreign_key_field(): void
    {
        if (!class_exists('LaravelAIEngine\\Tests\\Fakes\\MetadataOrder')) {
            eval(<<<'PHP'
namespace LaravelAIEngine\Tests\Fakes;

class MetadataOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'metadata_orders';
}
PHP);
        }

        Schema::shouldReceive('hasColumn')
            ->with('metadata_orders', 'user_id')
            ->once()
            ->andReturn(true);

        $query = Mockery::mock();
        $query->shouldReceive('where')->once()->with('user_id', 22)->andReturnSelf();

        $service = new AutonomousRAGModelMetadataService(
            null,
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            null
        );

        $result = $service->applyFilters($query, ['user_id' => 22], 'LaravelAIEngine\\Tests\\Fakes\\MetadataOrder');

        $this->assertSame($query, $result);
    }

    public function test_apply_filters_supports_array_values_via_where_in(): void
    {
        if (!class_exists('LaravelAIEngine\\Tests\\Fakes\\MetadataOrder')) {
            eval(<<<'PHP'
namespace LaravelAIEngine\Tests\Fakes;

class MetadataOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'metadata_orders';
}
PHP);
        }

        Schema::shouldReceive('hasColumn')
            ->with('metadata_orders', 'status')
            ->once()
            ->andReturn(true);

        $query = Mockery::mock();
        $query->shouldReceive('whereIn')->once()->with('status', ['open', 'overdue'])->andReturnSelf();

        $service = new AutonomousRAGModelMetadataService(
            null,
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            null
        );

        $result = $service->applyFilters($query, ['status' => ['open', 'overdue']], 'LaravelAIEngine\\Tests\\Fakes\\MetadataOrder');

        $this->assertSame($query, $result);
    }
}
