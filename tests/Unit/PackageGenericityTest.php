<?php

namespace LaravelAIEngine\Tests\Unit;

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Tests\UnitTestCase;

class PackageGenericityTest extends UnitTestCase
{
    public function test_demo_and_legacy_routes_are_not_loaded_by_default(): void
    {
        $this->assertFalse(Route::has('ai-chat.send'));
        $this->assertFalse(Route::has('ai-engine.auth.token'));
        $this->assertFalse(Route::has('ai-engine.api.chat.send'));
    }

    public function test_runtime_code_does_not_use_demo_user_fallbacks(): void
    {
        $files = [
            __DIR__ . '/../../src/Http/Requests/SendMessageRequest.php',
            __DIR__ . '/../../src/Services/Vector/VectorAccessControl.php',
            __DIR__ . '/../../src/Http/Controllers/Api/AgentChatApiController.php',
            __DIR__ . '/../../src/Http/Controllers/Api/AgentConversationApiController.php',
            __DIR__ . '/../../src/Http/Controllers/Api/FileAnalysisApiController.php',
            __DIR__ . '/../../src/Http/Controllers/Api/HealthApiController.php',
            __DIR__ . '/../../src/Http/Controllers/Api/VectorStoreApiController.php',
            __DIR__ . '/../../src/Services/Fal/FalMediaWorkflowService.php',
            __DIR__ . '/../../src/Services/Fal/FalReferencePackGenerationService.php',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);

            $this->assertStringNotContainsString("config('ai-engine.demo_user_id'", $contents, $file);
            $this->assertStringNotContainsString('config("ai-engine.demo_user_id"', $contents, $file);
        }
    }

    public function test_runtime_code_does_not_use_project_workspace_helpers_or_user_one_fallbacks(): void
    {
        $contents = implode("\n", array_map(
            static fn (string $file): string => file_get_contents($file),
            glob(__DIR__ . '/../../src/{Http,Services}/**/*.php', GLOB_BRACE) ?: []
        ));

        $this->assertStringNotContainsString('getActiveWorkSpace()', $contents);
        $this->assertStringNotContainsString('creatorId()', $contents);
        $this->assertStringNotContainsString('auth()->id() ?? 1', $contents);
    }

    public function test_real_agent_test_command_has_no_invoice_specific_builtin_flow(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../src/Console/Commands/TestRealAgentFlowCommand.php');

        $this->assertStringNotContainsString('invoice' . '-create', $contents);
        $this->assertStringNotContainsString(implode(' ', ['Mac', 'book', 'Pro']), $contents);
        $this->assertStringNotContainsString(implode(' ', ['Moha', 'med', 'Abou', 'Hagar']), $contents);
    }

    public function test_skill_matching_has_no_domain_specific_alias_fallback(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../src/Services/Agent/AgentSkillMatcher.php');

        $this->assertStringNotContainsString("'invoice' =>", $contents);
        $this->assertSame([], config('ai-agent.skills.intent_aliases'));
    }
}
