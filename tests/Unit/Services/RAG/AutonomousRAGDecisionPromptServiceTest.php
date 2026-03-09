<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionFeedbackService;
use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionPromptService;
use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;
use LaravelAIEngine\Tests\UnitTestCase;

class AutonomousRAGDecisionPromptServiceTest extends UnitTestCase
{
    public function test_prompt_builder_includes_business_and_adaptive_sections(): void
    {
        config()->set('ai-engine.intelligent_rag.decision.business_context', [
            'domain' => 'ecommerce',
            'priorities' => ['avoid duplicate lists'],
            'known_issues' => ['follow-up re-listing'],
        ]);

        $policy = new AutonomousRAGPolicy();
        $feedback = new AutonomousRAGDecisionFeedbackService($policy);
        $feedback->recordParseFailure('what is the status?', 'tool=db_query');

        $promptService = new AutonomousRAGDecisionPromptService($policy, $feedback);
        $prompt = $promptService->build('what is the status?', [
            'conversation' => 'user asked follow-up',
            'models' => [
                [
                    'name' => 'invoice',
                    'description' => 'Invoice model',
                    'schema' => ['id' => 'int', 'amount' => 'float'],
                    'tools' => ['mark_as_paid' => []],
                ],
            ],
            'nodes' => [],
            'last_entity_list' => [
                'entity_type' => 'invoice',
                'entity_ids' => [10, 12],
                'entity_data' => [['id' => 10], ['id' => 12]],
                'start_position' => 1,
                'end_position' => 2,
            ],
            'selected_entity' => ['entity_id' => 10, 'entity_type' => 'invoice'],
        ]);

        $this->assertStringContainsString('Business domain: ecommerce', $prompt);
        $this->assertStringContainsString('Known issue to avoid: follow-up re-listing', $prompt);
        $this->assertStringContainsString('strict JSON object only', $prompt);
        $this->assertStringNotContainsString('{{USER_REQUEST}}', $prompt);
        $this->assertStringContainsString('what is the status?', $prompt);
    }

    public function test_prompt_builder_applies_prompt_limits_to_models_and_nodes(): void
    {
        config()->set('ai-engine.intelligent_rag.decision.prompt_limits.models', 2);
        config()->set('ai-engine.intelligent_rag.decision.prompt_limits.nodes', 2);
        config()->set('ai-engine.intelligent_rag.decision.prompt_limits.model_fields', 1);

        $policy = new AutonomousRAGPolicy();
        $feedback = new AutonomousRAGDecisionFeedbackService($policy);
        $promptService = new AutonomousRAGDecisionPromptService($policy, $feedback);

        $prompt = $promptService->build('list data', [
            'conversation' => 'none',
            'models' => [
                ['name' => 'invoice', 'schema' => ['id' => 'int', 'status' => 'string']],
                ['name' => 'customer', 'schema' => ['id' => 'int', 'name' => 'string']],
                ['name' => 'payment', 'schema' => ['id' => 'int', 'amount' => 'float']],
            ],
            'nodes' => [
                ['slug' => 'billing', 'name' => 'Billing', 'collections' => ['invoice']],
                ['slug' => 'crm', 'name' => 'CRM', 'collections' => ['customer']],
                ['slug' => 'finance', 'name' => 'Finance', 'collections' => ['payment']],
            ],
            'last_entity_list' => null,
            'selected_entity' => null,
        ]);

        $this->assertStringContainsString('"name": "invoice"', $prompt);
        $this->assertStringContainsString('"name": "customer"', $prompt);
        $this->assertStringNotContainsString('"name": "payment"', $prompt);

        $this->assertStringContainsString('"slug": "billing"', $prompt);
        $this->assertStringContainsString('"slug": "crm"', $prompt);
        $this->assertStringNotContainsString('"slug": "finance"', $prompt);
    }
}
