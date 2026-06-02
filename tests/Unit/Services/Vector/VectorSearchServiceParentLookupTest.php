<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\Vector\ChunkingService;
use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vector\VectorAccessControl;
use LaravelAIEngine\Services\Vector\VectorDriverManager;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use LaravelAIEngine\Tests\UnitTestCase;

/**
 * Covers the parent-lookup filter path in VectorSearchService::applyParentLookupFilters
 * (driven from search()) including the model hooks hasVectorParentLookup() /
 * resolveParentIdsFromQuery() and the silent exception-swallow degrade path.
 */
class VectorSearchServiceParentLookupTest extends UnitTestCase
{
    /**
     * Build a service with an access-control mock that passes filters through
     * unchanged (minus the internal model_class marker the service adds).
     */
    private function makeService(VectorDriverInterface $driver): VectorSearchService
    {
        $driverManager = $this->createMock(VectorDriverManager::class);
        $driverManager->method('driver')->willReturn($driver);

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->method('embed')->willReturn([0.1, 0.2, 0.3]);

        $accessControl = $this->createMock(VectorAccessControl::class);
        // Pass filters straight through but strip the model_class marker that
        // search() injects, mirroring real buildSearchFilters() behaviour.
        $accessControl->method('buildSearchFilters')
            ->willReturnCallback(function ($userId, array $filters): array {
                unset($filters['model_class']);
                return $filters;
            });
        $accessControl->method('getUserById')->willReturn(null);
        $accessControl->method('getAccessLevel')->willReturn('public');

        return new VectorSearchService(
            $driverManager,
            $embeddingService,
            $accessControl,
            app(SearchDocumentBuilder::class),
            app(ChunkingService::class)
        );
    }

    public function test_applies_parent_lookup_filter_when_model_resolves_parent_ids(): void
    {
        $capturedFilters = null;

        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->expects($this->once())
            ->method('search')
            ->with(
                'vec_vecpar_parent_records',
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $filters) use (&$capturedFilters): bool {
                    $capturedFilters = $filters;
                    return true;
                })
            )
            ->willReturn([]); // empty -> hydrateModels short-circuits, no DB needed

        $service = $this->makeService($driver);

        $service->search(VecParParentLookupModel::class, 'emails from john@example.com');

        $this->assertIsArray($capturedFilters);
        // Multiple parent ids -> stored as array under the resolved parent key.
        $this->assertArrayHasKey('mailbox_id', $capturedFilters);
        $this->assertSame([10, 20, 30], $capturedFilters['mailbox_id']);
    }

    public function test_applies_single_parent_id_as_scalar_filter(): void
    {
        $capturedFilters = null;

        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->method('search')
            ->willReturnCallback(function ($collection, $vector, $limit, $threshold, array $filters) use (&$capturedFilters) {
                $capturedFilters = $filters;
                return [];
            });

        $service = $this->makeService($driver);

        $service->search(VecParSingleParentModel::class, 'find one parent');

        $this->assertIsArray($capturedFilters);
        $this->assertArrayHasKey('mailbox_id', $capturedFilters);
        // Single id collapses to a scalar match rather than an array.
        $this->assertSame(42, $capturedFilters['mailbox_id']);
    }

    public function test_skips_parent_filter_when_model_has_no_parent_lookup(): void
    {
        $capturedFilters = null;

        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->method('search')
            ->willReturnCallback(function ($collection, $vector, $limit, $threshold, array $filters) use (&$capturedFilters) {
                $capturedFilters = $filters;
                return [];
            });

        $service = $this->makeService($driver);

        $service->search(VecParNoParentModel::class, 'no parent lookup here');

        $this->assertIsArray($capturedFilters);
        $this->assertArrayNotHasKey('mailbox_id', $capturedFilters);
    }

    public function test_degrades_safely_and_logs_when_resolve_parent_ids_throws(): void
    {
        // The swallow path logs a warning and proceeds without the parent filter.
        Log::spy();

        $capturedFilters = null;

        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->expects($this->once())
            ->method('search')
            ->willReturnCallback(function ($collection, $vector, $limit, $threshold, array $filters) use (&$capturedFilters) {
                $capturedFilters = $filters;
                return [];
            });

        $service = $this->makeService($driver);

        // Should NOT throw despite resolveParentIdsFromQuery() blowing up.
        $result = $service->search(VecParThrowingParentModel::class, 'boom query');

        $this->assertTrue($result->isEmpty());
        $this->assertIsArray($capturedFilters);
        // Parent filter was never applied because resolution failed.
        $this->assertArrayNotHasKey('mailbox_id', $capturedFilters);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []): bool {
                return $message === 'Failed to apply parent lookup filters'
                    && ($context['model'] ?? null) === VecParThrowingParentModel::class;
            })
            ->once();
    }
}

/**
 * Model that opts into parent lookup and resolves several parent ids.
 */
class VecParParentLookupModel extends Model
{
    protected $table = 'vecpar_parent_records';

    public function hasVectorParentLookup(): bool
    {
        return true;
    }

    public static function resolveParentIdsFromQuery(string $query): array
    {
        return [
            'parent_key' => 'mailbox_id',
            'parent_ids' => [10, 20, 30],
        ];
    }
}

/**
 * Model whose parent resolution yields a single id (scalar filter branch).
 */
class VecParSingleParentModel extends Model
{
    protected $table = 'vecpar_single_parent_records';

    public function hasVectorParentLookup(): bool
    {
        return true;
    }

    public static function resolveParentIdsFromQuery(string $query): array
    {
        return [
            'parent_key' => 'mailbox_id',
            'parent_ids' => [42],
        ];
    }
}

/**
 * Model that disables parent lookup -> filter path is skipped entirely.
 */
class VecParNoParentModel extends Model
{
    protected $table = 'vecpar_no_parent_records';

    public function hasVectorParentLookup(): bool
    {
        return false;
    }

    public static function resolveParentIdsFromQuery(string $query): array
    {
        return ['parent_key' => 'mailbox_id', 'parent_ids' => [1]];
    }
}

/**
 * Model whose resolveParentIdsFromQuery throws -> exercises the swallow path.
 */
class VecParThrowingParentModel extends Model
{
    protected $table = 'vecpar_throwing_parent_records';

    public function hasVectorParentLookup(): bool
    {
        return true;
    }

    public static function resolveParentIdsFromQuery(string $query): array
    {
        throw new \RuntimeException('parent resolution exploded');
    }
}
