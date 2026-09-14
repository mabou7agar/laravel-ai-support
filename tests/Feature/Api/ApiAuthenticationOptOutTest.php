<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\TestCase;

/**
 * Hosts that protect the routes themselves can disable package auth, and trusted
 * server-to-server callers can opt in to request-supplied identity.
 */
class ApiAuthenticationOptOutTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Route middleware is resolved when routes load, so set this before boot.
        $app['config']->set('ai-engine.api.auth.middleware', 'none');
    }

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_routes_are_reachable_without_a_user_when_auth_is_disabled(): void
    {
        $this->getJson('/api/v1/ai/mcp/tools')->assertOk();
    }

    public function test_body_identity_and_scope_metadata_are_ignored_unless_trusted(): void
    {
        $this->postJson('/api/v1/ai/mcp/tools/context_echo/call', [
            'user_id' => 'bob',
            'metadata' => ['workspace_id' => 'ws-9', 'locale' => 'en'],
        ])
            ->assertOk()
            ->assertJsonPath('data.result.data.user_id', null)
            ->assertJsonMissingPath('data.result.data.metadata.workspace_id')
            ->assertJsonPath('data.result.data.metadata.locale', 'en');
    }

    public function test_trusted_request_identity_passes_body_identity_and_scope_metadata_through(): void
    {
        config()->set('ai-engine.api.identity.trust_request_identity', true);

        $this->postJson('/api/v1/ai/mcp/tools/context_echo/call', [
            'user_id' => 'bob',
            'metadata' => ['workspace_id' => 'ws-9'],
        ])
            ->assertOk()
            ->assertJsonPath('data.result.data.user_id', 'bob')
            ->assertJsonPath('data.result.data.metadata.workspace_id', 'ws-9');
    }
}
