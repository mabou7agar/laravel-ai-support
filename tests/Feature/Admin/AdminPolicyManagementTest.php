<?php

namespace LaravelAIEngine\Tests\Feature\Admin;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Models\AIPromptPolicyVersion;
use LaravelAIEngine\Tests\UnitTestCase;

class AdminPolicyManagementTest extends UnitTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('ai-engine.admin_ui.enabled', true);
        $app['config']->set('ai-engine.admin_ui.route_prefix', 'ai-engine/admin');
        $app['config']->set('ai-engine.admin_ui.middleware', ['web']);
        $app['config']->set('ai-engine.admin_ui.access.allowed_ips', ['203.0.113.10']);
        $app['config']->set('ai-engine.admin_ui.access.allow_localhost', false);
        $app['config']->set('ai-engine.rag.decision.policy_store.enabled', true);
        $app['config']->set('ai-engine.rag.decision.policy_store.table', 'ai_prompt_policy_versions');
        $app['config']->set('ai-engine.rag.decision.policy_store.default_key', 'decision');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ai_prompt_policy_versions');
        Schema::create('ai_prompt_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('policy_key')->default('decision')->index();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft')->index();
            $table->string('scope_key')->default('global')->index();
            $table->string('name')->nullable();
            $table->longText('template');
            $table->json('rules')->nullable();
            $table->json('target_context')->nullable();
            $table->unsignedTinyInteger('rollout_percentage')->default(0);
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('promoted_from_id')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['policy_key', 'version']);
            $table->index(['policy_key', 'status', 'scope_key']);
        });
    }

    public function test_can_create_policy_from_admin_ui(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/policies/create', [
                'policy_key' => 'decision',
                'name' => 'Admin Created Policy',
                'template' => 'You are a strict decision policy template for routing.',
                'status' => 'active',
                'rollout_percentage' => 0,
            ])
            ->assertRedirect();

        $policy = AIPromptPolicyVersion::query()->where('name', 'Admin Created Policy')->first();

        $this->assertNotNull($policy);
        $this->assertSame('decision', $policy->policy_key);
        $this->assertSame('active', $policy->status);
        $this->assertSame(1, (int) $policy->version);
    }

    public function test_can_activate_existing_policy_from_admin_ui(): void
    {
        $policy = AIPromptPolicyVersion::query()->create([
            'policy_key' => 'decision',
            'version' => 1,
            'status' => 'draft',
            'scope_key' => 'global',
            'name' => 'Draft Policy',
            'template' => 'Draft template',
            'rollout_percentage' => 0,
            'rules' => [],
            'target_context' => [],
            'metrics' => [],
            'metadata' => [],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/policies/activate', [
                'policy_id' => $policy->id,
                'status' => 'canary',
            ])
            ->assertRedirect();

        $policy->refresh();

        $this->assertSame('canary', $policy->status);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_prompt_policy_versions');

        parent::tearDown();
    }
}
