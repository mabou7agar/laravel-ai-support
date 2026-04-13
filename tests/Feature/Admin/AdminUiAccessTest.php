<?php

namespace LaravelAIEngine\Tests\Feature\Admin;

use LaravelAIEngine\Tests\Models\User;
use LaravelAIEngine\Tests\UnitTestCase;

class AdminUiAccessTest extends UnitTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('ai-engine.admin_ui.enabled', true);
        $app['config']->set('ai-engine.admin_ui.route_prefix', 'ai-engine/admin');
        $app['config']->set('ai-engine.admin_ui.middleware', ['web']);
        $app['config']->set('ai-engine.admin_ui.access.allow_localhost', false);
    }

    public function test_denies_access_when_user_and_ip_are_not_allowed(): void
    {
        config()->set('ai-engine.admin_ui.access.allowed_user_ids', ['10']);
        config()->set('ai-engine.admin_ui.access.allowed_emails', []);
        config()->set('ai-engine.admin_ui.access.allowed_ips', ['203.0.113.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->get('/ai-engine/admin')
            ->assertForbidden();
    }

    public function test_allows_access_for_allowed_ip_without_authentication(): void
    {
        config()->set('ai-engine.admin_ui.access.allowed_user_ids', ['10']);
        config()->set('ai-engine.admin_ui.access.allowed_emails', []);
        config()->set('ai-engine.admin_ui.access.allowed_ips', ['203.0.113.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/ai-engine/admin')
            ->assertOk()
            ->assertSee('Laravel Admin');
    }

    public function test_allows_access_to_nodes_health_and_policies_pages_for_allowed_ip(): void
    {
        config()->set('ai-engine.admin_ui.access.allowed_user_ids', []);
        config()->set('ai-engine.admin_ui.access.allowed_emails', []);
        config()->set('ai-engine.admin_ui.access.allowed_ips', ['203.0.113.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/ai-engine/admin/nodes')
            ->assertOk()
            ->assertSee('Nodes');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/ai-engine/admin/health')
            ->assertOk()
            ->assertSee('Infrastructure Health');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/ai-engine/admin/policies')
            ->assertOk()
            ->assertSee('Prompt Policies');
    }

    public function test_allows_access_for_allowed_authenticated_user(): void
    {
        config()->set('ai-engine.admin_ui.access.allowed_user_ids', ['42']);
        config()->set('ai-engine.admin_ui.access.allowed_emails', []);
        config()->set('ai-engine.admin_ui.access.allowed_ips', []);

        $user = new User();
        $user->id = 42;
        $user->email = 'allowed@example.test';

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->get('/ai-engine/admin')
            ->assertOk()
            ->assertSee('Laravel Admin');
    }

    public function test_denies_authenticated_user_when_not_in_allowlist(): void
    {
        config()->set('ai-engine.admin_ui.access.allowed_user_ids', ['77']);
        config()->set('ai-engine.admin_ui.access.allowed_emails', ['admin@example.test']);
        config()->set('ai-engine.admin_ui.access.allowed_ips', []);

        $user = new User();
        $user->id = 42;
        $user->email = 'user@example.test';

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->get('/ai-engine/admin')
            ->assertForbidden();
    }
}
