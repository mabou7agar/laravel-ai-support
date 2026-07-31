<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Tests\UnitTestCase;

final class CompatibilitySnapshotCommandTest extends UnitTestCase
{
    public function test_it_prints_the_machine_readable_snapshot(): void
    {
        self::assertSame(0, Artisan::call('ai:compatibility', ['--json' => true]));

        $output = Artisan::output();
        self::assertStringContainsString('"target_version": "3.0"', $output);
        self::assertStringContainsString('"deprecated_count": 6', $output);
        self::assertStringContainsString('/api/v1/ai/realtime/tools/dispatch', $output);
    }

    public function test_ci_gate_fails_while_deprecated_surfaces_remain(): void
    {
        self::assertSame(1, Artisan::call('ai:compatibility', [
            '--fail-on-deprecated' => true,
        ]));
    }
}
