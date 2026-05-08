<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionIntakeFlowService;
use LaravelAIEngine\Tests\TestCase;

class ActionIntakeFlowServiceTest extends TestCase
{
    public function test_handles_existing_relation_decision_and_continues_with_updated_payload(): void
    {
        $flow = app(ActionIntakeFlowService::class);
        $context = new UnifiedActionContext('test-session', 12);

        $flow->putPendingRelation($context, 12, 'invoice', [
            'kind' => 'existing_relation',
            'payload' => ['customer_name' => 'Mohamed Abou Hagar'],
            'candidate' => ['id' => 44],
        ]);

        $response = $flow->handlePendingRelationDecision('use this customer', $context, 12, 'invoice', [
            'apply_existing' => function (array $payload, array $pending): array {
                $payload['customer_id'] = $pending['candidate']['id'];

                return $payload;
            },
            'continue' => fn (array $payload): AgentResponse => AgentResponse::success(
                message: 'continued',
                context: $context,
                data: $payload
            ),
        ]);

        $this->assertInstanceOf(AgentResponse::class, $response);
        $this->assertSame(44, $response->data['customer_id']);
        $this->assertNull($flow->pendingRelation($context, 12, 'invoice'));
    }

    public function test_handles_collection_review_with_existing_and_new_records(): void
    {
        $flow = app(ActionIntakeFlowService::class);
        $context = new UnifiedActionContext('test-session-2', 12);

        $flow->putPendingRelation($context, 12, 'invoice', [
            'kind' => 'relation_collection_review',
            'payload' => ['items' => [['product_name' => 'Macbook'], ['product_name' => 'iPhone']]],
            'existing' => [['index' => 0, 'id' => 77]],
            'missing' => [['index' => 1, 'name' => 'iPhone']],
        ]);

        $response = $flow->handlePendingRelationDecision('use existing Macbook and create new iPhone', $context, 12, 'invoice', [
            'apply_existing' => function (array $payload, array $pending): array {
                $payload['items'][0]['product_id'] = $pending['existing'][0]['id'];

                return $payload;
            },
            'mark_create_new' => function (array $payload): array {
                $payload['_create_missing_products'] = true;

                return $payload;
            },
            'continue' => fn (array $payload): AgentResponse => AgentResponse::success(
                message: 'continued',
                context: $context,
                data: $payload
            ),
        ]);

        $this->assertSame(77, $response->data['items'][0]['product_id']);
        $this->assertTrue($response->data['_create_missing_products']);
        $this->assertNull($flow->pendingRelation($context, 12, 'invoice'));
    }
}
