<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Diagnostics;

use LaravelAIEngine\Services\Diagnostics\TestEverythingRunner;
use LaravelAIEngine\Tests\UnitTestCase;

class TestEverythingRunnerTest extends UnitTestCase
{
    public function test_runner_executes_stage_and_captures_output(): void
    {
        $results = app(TestEverythingRunner::class)->runStages([
            [
                'name' => 'demo',
                'command' => "php -r 'fwrite(STDOUT, \"ok\\n\");'",
                'workdir' => dirname(__DIR__, 4),
            ],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('demo', $results[0]['name']);
        $this->assertSame('passed', $results[0]['status']);
        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertStringContainsString('ok', $results[0]['output']);
    }

    public function test_runner_stops_when_requested_after_failure(): void
    {
        $results = app(TestEverythingRunner::class)->runStages([
            [
                'name' => 'failing',
                'command' => "php -r 'fwrite(STDERR, \"bad\\n\"); exit(1);'",
                'workdir' => dirname(__DIR__, 4),
            ],
            [
                'name' => 'skipped',
                'command' => "php -r 'echo \"skip\\n\";'",
                'workdir' => dirname(__DIR__, 4),
            ],
        ], true);

        $this->assertCount(1, $results);
        $this->assertSame('failing', $results[0]['name']);
        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame(1, $results[0]['exit_code']);
        $this->assertStringContainsString('bad', $results[0]['output']);
    }
}
