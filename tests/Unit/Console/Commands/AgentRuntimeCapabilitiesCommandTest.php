<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentRuntimeCapabilitiesCommandTest extends UnitTestCase
{
    public function test_command_prints_runtime_capability_report_as_json(): void
    {
        $exitCode = Artisan::call('ai:runtime-capabilities', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('laravel', $payload['current']['runtime']);
        $this->assertArrayHasKey('laravel', $payload['available']);
        $this->assertArrayHasKey('langgraph', $payload['available']);
    }
}
