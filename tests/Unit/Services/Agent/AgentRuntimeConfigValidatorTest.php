<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator;
use LaravelAIEngine\Services\Agent\Tools\RunSubAgentTool;
use LaravelAIEngine\Tests\Support\Stubs\RecordingAgentRuntime;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentRuntimeConfigValidatorTest extends UnitTestCase
{
    public function test_default_runtime_config_passes_validation(): void
    {
        $report = (new AgentRuntimeConfigValidator())->validate();

        $this->assertTrue($report['passed']);
        $this->assertSame([], $report['issues']);
    }

    public function test_validator_reports_invalid_runtime_and_missing_tool_class(): void
    {
        config()->set('ai-agent.runtime.default', 'unknown');
        config()->set('ai-agent.tools', ['missing' => 'App\\MissingTool']);

        $report = (new AgentRuntimeConfigValidator())->validate();

        $this->assertFalse($report['passed']);
        $this->assertContains('invalid_runtime', array_column($report['issues'], 'code'));
        $this->assertContains('missing_tool_class', array_column($report['issues'], 'code'));
    }

    public function test_validator_accepts_existing_tool_classes(): void
    {
        config()->set('ai-agent.tools', ['run_sub_agent' => RunSubAgentTool::class]);

        $report = (new AgentRuntimeConfigValidator())->validate();

        $this->assertTrue($report['passed']);
    }

    public function test_validator_accepts_registered_runtime_driver_as_default(): void
    {
        config()->set('ai-agent.runtime.drivers', ['recording' => RecordingAgentRuntime::class]);
        config()->set('ai-agent.runtime.default', 'recording');

        $report = (new AgentRuntimeConfigValidator())->validate();

        $this->assertTrue($report['passed']);
        $this->assertSame([], $report['issues']);
    }

    public function test_validator_lists_registered_drivers_in_invalid_default_message(): void
    {
        config()->set('ai-agent.runtime.drivers', ['recording' => RecordingAgentRuntime::class]);
        config()->set('ai-agent.runtime.default', 'unknown');

        $report = (new AgentRuntimeConfigValidator())->validate();

        $issue = collect($report['issues'])->firstWhere('code', 'invalid_runtime');

        $this->assertFalse($report['passed']);
        $this->assertSame('Configured default agent runtime must be one of: recording, laravel, langgraph.', $issue['message']);
    }

    public function test_validator_rejects_runtime_driver_that_does_not_implement_contract(): void
    {
        config()->set('ai-agent.runtime.drivers', ['plain' => \stdClass::class]);

        $report = (new AgentRuntimeConfigValidator())->validate();

        $issue = collect($report['issues'])->firstWhere('code', 'runtime_driver_contract_mismatch');

        $this->assertFalse($report['passed']);
        $this->assertIsArray($issue);
        $this->assertSame('error', $issue['severity']);
        $this->assertSame('ai-agent.runtime.drivers.plain', $issue['subject']);
        $this->assertSame(
            'Agent runtime driver [plain] class [stdClass] must implement ' . AgentRuntimeContract::class . '.',
            $issue['message']
        );
    }

    public function test_validator_rejects_missing_and_malformed_runtime_driver_entries(): void
    {
        config()->set('ai-agent.runtime.drivers', [
            'missing' => 'App\\AI\\MissingRuntime',
            'blank' => '',
            '' => RecordingAgentRuntime::class,
        ]);

        $report = (new AgentRuntimeConfigValidator())->validate();
        $codes = array_column($report['issues'], 'code');

        $this->assertFalse($report['passed']);
        $this->assertContains('missing_runtime_driver_class', $codes);
        $this->assertContains('invalid_runtime_driver_class', $codes);
        $this->assertContains('invalid_runtime_driver_name', $codes);
    }

    public function test_validator_rejects_non_array_runtime_drivers(): void
    {
        config()->set('ai-agent.runtime.drivers', RecordingAgentRuntime::class);

        $report = (new AgentRuntimeConfigValidator())->validate();

        $this->assertFalse($report['passed']);
        $this->assertSame('invalid_runtime_drivers', $report['issues'][0]['code']);
    }
}
