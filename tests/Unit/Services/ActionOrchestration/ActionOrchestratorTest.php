<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Actions;

use LaravelAIEngine\Contracts\ActionDefinitionProvider;
use LaravelAIEngine\Contracts\ActionAuditLogger;
use LaravelAIEngine\Contracts\ActionExecutor;
use LaravelAIEngine\Contracts\ActionRelationResolver;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\ActionOperation;
use LaravelAIEngine\Services\Actions\ActionOrchestrator;
use LaravelAIEngine\Services\Actions\ActionRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class ActionOrchestratorTest extends UnitTestCase
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
        $registry = new ActionRegistry();
        $registry->registerProviders([new TestActionDefinitionProvider()]);

        $this->assertTrue($registry->has('create_ticket'));
        $this->assertSame('support', $registry->get('create_ticket')['module']);
    }

    public function test_registry_normalizes_schema_operation_risk_and_confirmation_policy(): void
    {
        $registry = new ActionRegistry();
        $registry->register([
            'id' => 'preview_report',
            'operation' => 'unsupported',
            'risk' => 'low',
        ]);

        $action = $registry->get('preview_report');

        $this->assertSame(ActionOperation::CUSTOM->value, $action['operation']);
        $this->assertSame('low', $action['risk']);
        $this->assertFalse($action['confirmation_required']);
    }

    public function test_orchestrator_uses_executor_and_relation_resolver_contracts(): void
    {
        $registry = new ActionRegistry();
        $registry->register([
            'id' => 'create_ticket',
            'module' => 'support',
            'operation' => 'create',
            'required' => ['category_id', 'title'],
            'summary_fields' => ['category_id', 'title'],
            'executor' => new TestActionExecutor(),
        ]);

        $orchestrator = new ActionOrchestrator($registry, [new TestActionRelationResolver()]);
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

    public function test_execute_replays_successful_result_for_same_idempotency_key(): void
    {
        $calls = 0;
        $registry = new ActionRegistry();
        $registry->register([
            'id' => 'create_invoice',
            'module' => 'sales',
            'operation' => 'create',
            'required' => ['customer_id', '_idempotency_key'],
            'handler' => function () use (&$calls): ActionResult {
                $calls++;

                return ActionResult::success('Created invoice.', ['id' => $calls]);
            },
        ]);

        $orchestrator = new ActionOrchestrator($registry);
        $payload = ['customer_id' => 10, '_idempotency_key' => 'same-request'];

        $first = $orchestrator->execute('create_invoice', $payload, confirmed: true);
        $second = $orchestrator->execute('create_invoice', $payload, confirmed: true);

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $second->data['id']);
        $this->assertTrue($second->metadata['idempotent_replay']);
    }

    public function test_orchestrator_exposes_execution_helpers_for_agent_steps(): void
    {
        $orchestrator = $this->orchestrator();
        $payload = [
            'customer_id' => 10,
            'items' => [['name' => 'Service']],
            '_idempotency_key' => 'agent-step-1',
        ];
        $result = ActionResult::success('Ready.', ['preview' => true])
            ->withActionInfo('create_invoice', 'create');

        $this->assertTrue($orchestrator->canExecute('create_invoice'));
        $this->assertFalse($orchestrator->canExecute('missing_action'));
        $this->assertTrue($orchestrator->requiresConfirmation('create_invoice', $payload));

        $idempotency = $orchestrator->idempotencyMetadata('create_invoice', $payload);
        $this->assertSame('action:idempotency', $idempotency['cache_namespace']);
        $this->assertIsString($idempotency['key']);
        $this->assertFalse($idempotency['has_cached_result']);

        $metadata = $orchestrator->executionStepMetadata('create_invoice', $result, $payload);
        $this->assertSame('create_invoice', $metadata['action_id']);
        $this->assertSame('create', $metadata['operation']);
        $this->assertTrue($metadata['success']);
        $this->assertTrue($metadata['requires_confirmation']);
        $this->assertSame($idempotency['key'], $metadata['idempotency']['key']);
    }

    public function test_orchestrator_writes_prepare_and_execute_audit_events(): void
    {
        $registry = new ActionRegistry();
        $registry->register([
            'id' => 'create_note',
            'operation' => 'create',
            'required' => ['title'],
            'handler' => fn (array $payload): ActionResult => ActionResult::success('Created note.', $payload),
        ]);
        $audit = new TestActionAuditLogger();
        $orchestrator = new ActionOrchestrator($registry, [], null, $audit);

        $prepared = $orchestrator->prepare('create_note', ['title' => 'Follow up']);
        $executed = $orchestrator->execute('create_note', ['title' => 'Follow up'], confirmed: true);

        $this->assertTrue($prepared['success']);
        $this->assertTrue($executed->success);
        $this->assertSame(['create_note', 'create_note'], $audit->prepared);
        $this->assertSame(['create_note'], $audit->executed);
    }

    protected function orchestrator(): ActionOrchestrator
    {
        $registry = new ActionRegistry();
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

        return new ActionOrchestrator($registry);
    }
}

class TestActionDefinitionProvider implements ActionDefinitionProvider
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

class TestActionExecutor implements ActionExecutor
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

class TestActionRelationResolver implements ActionRelationResolver
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

class TestActionAuditLogger implements ActionAuditLogger
{
    public array $prepared = [];

    public array $executed = [];

    public function prepared(string $actionId, array $action, array $payload, array $result, ?UnifiedActionContext $context): void
    {
        $this->prepared[] = $actionId;
    }

    public function executed(string $actionId, array $action, array $payload, ActionResult $result, ?UnifiedActionContext $context): void
    {
        $this->executed[] = $actionId;
    }
}
