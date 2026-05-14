<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Contracts\DeterministicAgentHandler;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator;
use LaravelAIEngine\Services\Agent\Tools\RunSubAgentTool;
use LaravelAIEngine\Tests\UnitTestCase;

class ValidDeterministicHandler implements DeterministicAgentHandler
{
    public function handle(string $message, UnifiedActionContext $context, array $options = []): ?AgentResponse
    {
        return null;
    }
}

class InvalidDeterministicHandler
{
}

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

    public function test_validator_reports_invalid_routing_stage_config(): void
    {
        config()->set('ai-agent.routing_pipeline.stages', ['App\\MissingStage']);

        $report = (new AgentRuntimeConfigValidator())->validate();

        $this->assertFalse($report['passed']);
        $this->assertContains('missing_routing_stage', array_column($report['issues'], 'code'));
    }

    public function test_validator_checks_dispatcher_handlers(): void
    {
        config()->set('ai-agent.deterministic_handlers', [
            ValidDeterministicHandler::class,
            InvalidDeterministicHandler::class,
            'App\\MissingDeterministicHandler',
        ]);

        $report = (new AgentRuntimeConfigValidator())->validate();

        $this->assertFalse($report['passed']);
        $this->assertContains('invalid_dispatcher_handler_contract', array_column($report['issues'], 'code'));
        $this->assertContains('missing_dispatcher_handler', array_column($report['issues'], 'code'));
    }
}
