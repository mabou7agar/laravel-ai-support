<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\Contracts\ConversationMemory;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\Tools\RunSkillTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RunSkillToolAiNativeTest extends UnitTestCase
{
    public function test_run_skill_delegates_to_ai_native_runtime_when_enabled(): void
    {
        config()->set('ai-agent.ai_native.enabled', true);
        config()->set('ai-agent.ai_native.skills', true);

        $context = new UnifiedActionContext('run-skill-ai-native');

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->with('create invoice', $context, Mockery::on(function (array $options): bool {
                return ($options['skill_id'] ?? null) === 'create_invoice'
                    && ($options['runtime_scope'] ?? null) === 'skill';
            }))
            ->andReturn(AgentResponse::success('Handled by AI native.', context: $context));

        $tool = new RunSkillTool(
            Mockery::mock(AgentSkillRegistry::class),
            app(ConversationMemory::class),
            Mockery::mock(AIEngineService::class),
            new ToolRegistry(),
            null,
            null,
            $native
        );

        $result = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'create invoice',
        ], $context);

        $this->assertTrue($result->success);
        $this->assertSame('Handled by AI native.', $result->message);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['selected_skill_id']);
    }
}
