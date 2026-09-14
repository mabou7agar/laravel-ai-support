<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\TestCase;

/**
 * The package API is authenticated by default, and request identity comes from the
 * authenticated user rather than from the request body.
 */
class ApiAuthenticationDefaultsTest extends TestCase
{
    public function test_anonymous_requests_to_package_api_groups_are_rejected_with_json_401(): void
    {
        $this->registerContextEchoTool();

        $this->getJson('/api/v1/ai/mcp/tools')->assertUnauthorized()->assertJsonPath('success', false);
        $this->postJson('/api/v1/ai/mcp/tools/context_echo/call', ['user_id' => '1'])->assertUnauthorized();
        $this->postJson('/api/v1/ai/realtime/tools/dispatch', [
            'event' => ['id' => 'c1', 'name' => 'context_echo', 'arguments' => []],
            'user_id' => '1',
        ])->assertUnauthorized();
        $this->postJson('/api/v1/agent/chat', ['message' => 'hi', 'session_id' => 's', 'user_id' => '1'])->assertUnauthorized();
        $this->getJson('/api/v1/ai/agent-runs')->assertUnauthorized();
        $this->postJson('/api/v1/ai/realtime/sessions', ['provider' => 'openai'])->assertUnauthorized();
    }

    public function test_anonymous_non_json_request_gets_401_instead_of_a_login_redirect_error(): void
    {
        config()->set('ai-agent.event_stream.sse.enabled', true);
        $run = app(AgentRunRepository::class)->create(['session_id' => 'anon-sse', 'status' => AIAgentRun::STATUS_COMPLETED]);

        $this->get("/api/v1/ai/agent-runs/{$run->uuid}/stream")->assertUnauthorized();
    }

    public function test_health_and_provider_webhooks_stay_reachable_without_a_user(): void
    {
        $this->getJson('/api/v1/ai/health')->assertStatus(200);

        // Reaches the controller (validation), not the auth layer.
        $status = $this->postJson('/api/v1/ai/provider-tools/fal/catalog/webhook', [])->status();
        $this->assertNotSame(401, $status);
    }

    public function test_authenticated_user_is_the_tool_identity_even_when_body_names_another_user(): void
    {
        $this->registerContextEchoTool();

        $this->authenticateApi('alice')
            ->postJson('/api/v1/ai/mcp/tools/context_echo/call', [
                'user_id' => 'bob',
                'metadata' => ['workspace_id' => 'victim-workspace', 'tenant_id' => 'victim', 'locale' => 'ar'],
            ])
            ->assertOk()
            ->assertJsonPath('data.result.data.user_id', 'alice')
            ->assertJsonPath('data.result.data.metadata.locale', 'ar')
            ->assertJsonMissingPath('data.result.data.metadata.workspace_id')
            ->assertJsonMissingPath('data.result.data.metadata.tenant_id');
    }

    public function test_mcp_tool_requiring_confirmation_needs_explicit_approval(): void
    {
        $registry = app(ToolRegistry::class);
        $registry->register('dangerous_write', new class extends SimpleAgentTool {
            public string $name = 'dangerous_write';
            public string $description = 'Writes data.';
            public array $parameters = [];
            public bool $executed = false;

            public function requiresConfirmation(): bool
            {
                return true;
            }

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->executed = true;

                return ActionResult::success('Written.');
            }
        });

        $this->authenticateApi('alice')
            ->postJson('/api/v1/ai/mcp/tools/dangerous_write/call', ['arguments' => []])
            ->assertStatus(202)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.result.status', 'approval_required');
        $this->assertFalse($registry->get('dangerous_write')->executed);

        $this->authenticateApi('alice')
            ->postJson('/api/v1/ai/mcp/tools/dangerous_write/call', ['arguments' => [], 'approved' => true])
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertTrue($registry->get('dangerous_write')->executed);
    }

    public function test_mcp_tool_denied_by_execution_policy_is_not_executed(): void
    {
        $this->registerContextEchoTool();
        config()->set('ai-agent.execution_policy.tool_deny', ['context_echo']);

        $this->authenticateApi('alice')
            ->postJson('/api/v1/ai/mcp/tools/context_echo/call', ['arguments' => []])
            ->assertStatus(422)
            ->assertJsonPath('data.result.status', 'policy_blocked');
    }

    protected function registerContextEchoTool(): void
    {
        app(ToolRegistry::class)->register('context_echo', new class extends SimpleAgentTool {
            public string $name = 'context_echo';
            public string $description = 'Echo the resolved execution context.';
            public array $parameters = [];

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Echoed.', [
                    'user_id' => $context->userId,
                    'metadata' => $context->metadata,
                ]);
            }
        });
    }
}
