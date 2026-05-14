<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class TestAgentToolCommandTest extends UnitTestCase
{
    public function test_tool_test_command_executes_registered_tool_with_json_payload(): void
    {
        config()->set('ai-agent.tools', [
            'echo_tool' => EchoCommandTool::class,
        ]);
        $this->app->forgetInstance(ToolRegistry::class);

        $exitCode = Artisan::call('ai-engine:tools:test', [
            'tool' => 'echo_tool',
            '--payload' => '{"text":"hello"}',
            '--user' => '42',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['success']);
        $this->assertSame('Echoed.', $payload['message']);
        $this->assertSame('hello', $payload['data']['text']);
        $this->assertSame('42', $payload['data']['user_id']);
        $this->assertSame('echo_tool', $payload['tool']['name']);
    }

    public function test_tool_test_command_reports_invalid_json_payload(): void
    {
        $exitCode = Artisan::call('ai-engine:tools:test', [
            'tool' => 'echo_tool',
            '--payload' => '{bad',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['success']);
        $this->assertSame('Payload must be a valid JSON object.', $payload['error']);
    }
}

class EchoCommandTool extends SimpleAgentTool
{
    public string $name = 'echo_tool';

    public string $description = 'Echo text.';

    public array $parameters = [
        'text' => ['type' => 'string', 'required' => true],
    ];

    protected function handle(array $parameters, UnifiedActionContext $context): array
    {
        return [
            'message' => 'Echoed.',
            'data' => [
                'text' => $parameters['text'],
                'user_id' => $context->userId,
            ],
        ];
    }
}
