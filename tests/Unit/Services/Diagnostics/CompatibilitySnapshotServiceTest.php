<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Diagnostics;

use LaravelAIEngine\Services\Diagnostics\CompatibilitySnapshotService;
use LaravelAIEngine\Tests\UnitTestCase;
use RuntimeException;

final class CompatibilitySnapshotServiceTest extends UnitTestCase
{
    public function test_it_loads_the_versioned_compatibility_inventory(): void
    {
        $snapshot = app(CompatibilitySnapshotService::class)->snapshot('3.0');

        self::assertSame(1, $snapshot['schema_version']);
        self::assertSame('3.0', $snapshot['target_version']);
        self::assertNotEmpty($snapshot['surfaces']);
        self::assertContains(
            'LaravelAIEngine.Facades.AIEngine',
            array_column($snapshot['surfaces'], 'id'),
        );
        self::assertContains('retained', array_column($snapshot['routes'], 'status'));
    }

    public function test_it_rejects_unsafe_target_names(): void
    {
        $this->expectException(RuntimeException::class);

        app(CompatibilitySnapshotService::class)->snapshot('../secrets');
    }
}
