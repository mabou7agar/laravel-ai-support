<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Tests\UnitTestCase;

class ValidateAgentRuntimeConfigCommandTest extends UnitTestCase
{
    public function test_command_succeeds_for_valid_runtime_config(): void
    {
        $exitCode = Artisan::call('ai:validate-runtime-config', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['passed']);
        $this->assertSame([], $payload['issues']);
    }

    public function test_command_fails_for_invalid_runtime_config(): void
    {
        config()->set('ai-agent.runtime.default', 'invalid');

        $exitCode = Artisan::call('ai:validate-runtime-config', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['passed']);
        $this->assertSame('invalid_runtime', $payload['issues'][0]['code']);
    }
}
