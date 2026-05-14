<?php

namespace LaravelAIEngine\Tests\Feature\Policies;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Models\AIPromptFeedbackEvent;
use LaravelAIEngine\Models\AIPromptPolicyVersion;
use LaravelAIEngine\Services\RAG\RAGPromptPolicyService;
use LaravelAIEngine\Tests\TestCase;

class DecisionPolicyEvaluateCommandTest extends TestCase
{
    public function test_it_promotes_best_canary_when_metrics_improve(): void
    {
        config()->set('ai-engine.rag.decision.policy_store.enabled', true);
        config()->set('ai-engine.rag.decision.policy_store.auto_seed_default', false);
        config()->set('ai-engine.rag.decision.adaptive_feedback.persistence.enabled', true);
        config()->set('ai-engine.rag.decision.policy_store.evaluation.min_score_delta', 0.1);

        /** @var RAGPromptPolicyService $service */
        $service = $this->app->make(RAGPromptPolicyService::class);

        $active = $service->createVersion('ACTIVE V1', [
            'policy_key' => 'decision',
            'status' => 'active',
        ]);

        $canary = $service->createVersion('CANARY V2', [
            'policy_key' => 'decision',
            'status' => 'canary',
            'rollout_percentage' => 50,
        ]);

        $this->seedPolicyEvents($active, false);
        $this->seedPolicyEvents($canary, true);

        Artisan::call('ai-engine:decision-policy:evaluate', [
            '--policy' => 'decision',
            '--promote' => true,
            '--min-samples' => 2,
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true);

        $this->assertIsArray($output);
        $this->assertTrue(isset($output['promotion']['promoted']));

        $freshCanary = AIPromptPolicyVersion::query()->find($canary->id);
        $this->assertSame('active', $freshCanary?->status);
    }

    protected function seedPolicyEvents(?AIPromptPolicyVersion $policy, bool $good): void
    {
        if (!$policy) {
            return;
        }

        for ($i = 0; $i < 3; $i++) {
            AIPromptFeedbackEvent::query()->create([
                'channel' => 'rag_decision',
                'event_type' => 'decision_parsed',
                'policy_key' => $policy->policy_key,
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
                'policy_status' => $policy->status,
                'session_id' => 'sess-' . $policy->version . '-' . $i,
                'decision_tool' => 'db_query',
                'decision_source' => 'ai',
                'latency_ms' => $good ? 35 : 90,
                'relist_risk' => false,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinutes(2),
            ]);

            AIPromptFeedbackEvent::query()->create([
                'channel' => 'rag_decision',
                'event_type' => 'execution_outcome',
                'policy_key' => $policy->policy_key,
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
                'policy_status' => $policy->status,
                'session_id' => 'sess-' . $policy->version . '-' . $i,
                'decision_tool' => 'db_query',
                'success' => $good,
                'outcome' => $good ? 'success' : 'failure',
                'created_at' => now()->subMinutes(1),
                'updated_at' => now()->subMinutes(1),
            ]);
        }

        if (!$good) {
            AIPromptFeedbackEvent::query()->create([
                'channel' => 'rag_decision',
                'event_type' => 'decision_parse_failure',
                'policy_key' => $policy->policy_key,
                'policy_id' => $policy->id,
                'policy_version' => $policy->version,
                'policy_status' => $policy->status,
                'session_id' => 'sess-' . $policy->version . '-fail',
                'decision_source' => 'fallback',
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ]);
        }
    }
}
