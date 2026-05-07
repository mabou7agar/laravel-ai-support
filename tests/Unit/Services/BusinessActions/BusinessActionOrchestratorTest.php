<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\BusinessActions;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\BusinessActions\BusinessActionOrchestrator;
use LaravelAIEngine\Services\BusinessActions\BusinessActionRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class BusinessActionOrchestratorTest extends UnitTestCase
{
    public function test_prepare_reports_missing_required_fields(): void
    {
        $orchestrator = $this->orchestrator();

        $result = $orchestrator->prepare('create_invoice', ['customer_id' => 10]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['needs_user_input']);
        $this->assertSame(['items'], $result['missing_fields']);
    }

    public function test_prepare_returns_confirmation_draft(): void
    {
        $orchestrator = $this->orchestrator();

        $result = $orchestrator->prepare('create_invoice', [
            'customer_id' => 10,
            'items' => [['name' => 'Service']],
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['requires_confirmation']);
        $this->assertSame('create_invoice', $result['draft']['action_id']);
        $this->assertSame(['customer_id' => 10], $result['draft']['summary']);
    }

    public function test_execute_requires_confirmation(): void
    {
        $result = $this->orchestrator()->execute('create_invoice', [
            'customer_id' => 10,
            'items' => [['name' => 'Service']],
        ]);

        $this->assertFalse($result->success);
        $this->assertTrue($result->requiresUserInput());
        $this->assertTrue($result->metadata['requires_confirmation']);
    }

    public function test_execute_calls_handler_when_confirmed(): void
    {
        $result = $this->orchestrator()->execute('create_invoice', [
            'customer_id' => 10,
            'items' => [['name' => 'Service']],
        ], confirmed: true);

        $this->assertTrue($result->success);
        $this->assertSame('Created invoice.', $result->message);
        $this->assertSame(123, $result->data['id']);
        $this->assertSame('create_invoice', $result->actionId);
    }

    public function test_suggest_uses_registered_suggesters(): void
    {
        $result = $this->orchestrator()->suggest(['customer_id' => 10]);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame('create_invoice', $result['suggestions'][0]['action_id']);
    }

    protected function orchestrator(): BusinessActionOrchestrator
    {
        $registry = new BusinessActionRegistry();
        $registry->register([
            'id' => 'create_invoice',
            'module' => 'sales',
            'label' => 'Create invoice',
            'operation' => 'create',
            'required' => ['customer_id', 'items'],
            'summary_fields' => ['customer_id'],
            'handler' => fn (array $payload, ?UnifiedActionContext $context, array $action): ActionResult => ActionResult::success(
                'Created invoice.',
                ['id' => 123, 'payload' => $payload]
            ),
            'suggest' => fn (array $context): array => empty($context['customer_id']) ? [] : [
                ['reason' => 'Customer exists in context.'],
            ],
        ]);

        return new BusinessActionOrchestrator($registry);
    }
}
