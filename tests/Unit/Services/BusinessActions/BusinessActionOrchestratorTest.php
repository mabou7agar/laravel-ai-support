<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\BusinessActions;

use LaravelAIEngine\Contracts\BusinessActionDefinitionProvider;
use LaravelAIEngine\Contracts\BusinessActionExecutor;
use LaravelAIEngine\Contracts\BusinessActionRelationResolver;
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

    public function test_registry_registers_action_definition_providers(): void
    {
        $registry = new BusinessActionRegistry();
        $registry->registerProviders([new TestActionDefinitionProvider()]);

        $this->assertTrue($registry->has('create_ticket'));
        $this->assertSame('support', $registry->get('create_ticket')['module']);
    }

    public function test_orchestrator_uses_executor_and_relation_resolver_contracts(): void
    {
        $registry = new BusinessActionRegistry();
        $registry->register([
            'id' => 'create_ticket',
            'module' => 'support',
            'operation' => 'create',
            'required' => ['category_id', 'title'],
            'summary_fields' => ['category_id', 'title'],
            'executor' => new TestBusinessActionExecutor(),
        ]);

        $orchestrator = new BusinessActionOrchestrator($registry, [new TestBusinessActionRelationResolver()]);
        $prepared = $orchestrator->prepare('create_ticket', [
            'category_name' => 'Billing',
            'title' => 'Invoice question',
        ]);

        $this->assertTrue($prepared['success']);
        $this->assertSame(55, $prepared['draft']['payload']['category_id']);
        $this->assertSame('category_id', $prepared['draft']['summary']['resolved_relations'][0]['field']);

        $executed = $orchestrator->execute('create_ticket', [
            'category_name' => 'Billing',
            'title' => 'Invoice question',
        ], confirmed: true);

        $this->assertTrue($executed->success);
        $this->assertSame('Created ticket.', $executed->message);
        $this->assertSame(55, $executed->data['category_id']);
        $this->assertSame('create_ticket', $executed->actionId);
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

class TestActionDefinitionProvider implements BusinessActionDefinitionProvider
{
    public function actions(): iterable
    {
        yield 'create_ticket' => [
            'module' => 'support',
            'operation' => 'create',
            'required' => ['title'],
        ];
    }
}

class TestBusinessActionExecutor implements BusinessActionExecutor
{
    public function prepare(array $payload, ?UnifiedActionContext $context, array $action): array
    {
        return [
            'success' => true,
            'message' => 'Ticket ready.',
            'payload' => $payload,
            'summary' => [
                'category_id' => $payload['category_id'],
                'title' => $payload['title'],
            ],
        ];
    }

    public function execute(array $payload, ?UnifiedActionContext $context, array $action): mixed
    {
        return ActionResult::success('Created ticket.', [
            'id' => 987,
            'category_id' => $payload['category_id'],
            'title' => $payload['title'],
        ]);
    }
}

class TestBusinessActionRelationResolver implements BusinessActionRelationResolver
{
    public function resolveExisting(string $actionId, array $payload, ?UnifiedActionContext $context, array $action): array
    {
        if (($payload['category_name'] ?? null) !== 'Billing') {
            return ['payload' => $payload];
        }

        $payload['category_id'] = 55;

        return [
            'payload' => $payload,
            'resolved_relations' => [[
                'field' => 'category_id',
                'source' => 'category_name',
                'id' => 55,
                'label' => 'Billing',
            ]],
        ];
    }

    public function createMissing(string $actionId, array $payload, ?UnifiedActionContext $context, array $action): array
    {
        return ['payload' => $payload];
    }
}
