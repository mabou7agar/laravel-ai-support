<?php

namespace LaravelAIEngine\Tests\Feature\Policies;

use LaravelAIEngine\Services\RAG\RAGPromptPolicyService;
use LaravelAIEngine\Tests\TestCase;

class DecisionPromptPolicyServiceTest extends TestCase
{
    public function test_it_creates_and_resolves_canary_policy_by_runtime_context(): void
    {
        config()->set('ai-engine.rag.decision.policy_store.enabled', true);
        config()->set('ai-engine.rag.decision.policy_store.auto_seed_default', false);

        /** @var RAGPromptPolicyService $service */
        $service = $this->app->make(RAGPromptPolicyService::class);

        $active = $service->createVersion('ACTIVE TEMPLATE', [
            'policy_key' => 'decision',
            'status' => 'active',
        ]);

        $canary = $service->createVersion('CANARY TEMPLATE', [
            'policy_key' => 'decision',
            'status' => 'canary',
            'rollout_percentage' => 100,
        ]);

        $this->assertNotNull($active);
        $this->assertNotNull($canary);

        $resolved = $service->resolveForRuntime([
            'session_id' => 'session-100',
            'user_id' => '5',
        ]);

        $this->assertNotNull($resolved['selected']);
        $this->assertSame($canary->id, $resolved['selected']->id);
        $this->assertSame('canary', $resolved['selection']);
    }

    public function test_it_matches_scoped_policy_over_global_policy(): void
    {
        config()->set('ai-engine.rag.decision.policy_store.enabled', true);
        config()->set('ai-engine.rag.decision.policy_store.auto_seed_default', false);

        /** @var RAGPromptPolicyService $service */
        $service = $this->app->make(RAGPromptPolicyService::class);

        $global = $service->createVersion('GLOBAL', [
            'policy_key' => 'decision',
            'status' => 'active',
        ]);

        $scoped = $service->createVersion('TENANT_SCOPE', [
            'policy_key' => 'decision',
            'status' => 'active',
            'target_context' => ['tenant_id' => 'tenant-7'],
        ]);

        $resolved = $service->resolveForRuntime([
            'session_id' => 'session-tenant-7',
            'tenant_id' => 'tenant-7',
        ]);

        $this->assertNotNull($global);
        $this->assertNotNull($scoped);
        $this->assertNotNull($resolved['selected']);
        $this->assertSame($scoped->id, $resolved['selected']->id);
    }
}
