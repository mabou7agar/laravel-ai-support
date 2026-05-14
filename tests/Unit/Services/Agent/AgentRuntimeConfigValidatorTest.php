<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator;
use LaravelAIEngine\Services\Agent\Tools\RunSubAgentTool;
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

    public function test_validator_reports_invalid_routing_stage_config(): void
    {
        config()->set('ai-agent.routing_pipeline.stages', ['App\\MissingStage']);

        $report = (new AgentRuntimeConfigValidator())->validate();

        $this->assertFalse($report['passed']);
        $this->assertContains('missing_routing_stage', array_column($report['issues'], 'code'));
    }

}
