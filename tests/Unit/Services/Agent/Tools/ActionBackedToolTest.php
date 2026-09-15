<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionOrchestrator;
use LaravelAIEngine\Services\Actions\ActionRegistry;
use LaravelAIEngine\Services\Agent\Tools\ActionBackedTool;
use LaravelAIEngine\Tests\UnitTestCase;

class ActionBackedToolTest extends UnitTestCase
{
    public function test_action_backed_tool_exposes_action_schema_and_runs_orchestrator(): void
    {
        $tool = new CreateNoteActionTool($this->orchestrator());

        $this->assertSame('notes.create', $tool->getName());
        $this->assertSame('Create a note.', $tool->getDescription());
        $this->assertFalse($tool->requiresConfirmation());
        $this->assertArrayHasKey('title', $tool->getParameters());

        $result = $tool->execute([
            'title' => 'Follow up',
            'body' => 'Send invoice',
        ], new UnifiedActionContext('action-backed-tool', 5));

        $this->assertTrue($result->success);
        $this->assertSame('Created note.', $result->message);
        $this->assertSame('Follow up', $result->data['title']);
        $this->assertSame(5, $result->data['user_id']);
        $this->assertTrue($result->metadata['action_backed_tool']);
        $this->assertSame('notes.create', $result->metadata['tool_action_id']);
    }

    public function test_action_backed_tool_can_require_confirmation(): void
    {
        $tool = new DeleteNoteActionTool($this->orchestrator());

        $this->assertTrue($tool->requiresConfirmation());
        $this->assertArrayHasKey('confirmed', $tool->getParameters());

        $pending = $tool->execute(['id' => 9], new UnifiedActionContext('action-backed-confirm', 5));
        $this->assertFalse($pending->success);
        $this->assertTrue($pending->requiresUserInput());

        $executed = $tool->execute([
            'id' => 9,
            'confirmed' => true,
        ], new UnifiedActionContext('action-backed-confirm', 5));

        $this->assertTrue($executed->success);
        $this->assertSame(9, $executed->data['id']);
    }

    public function test_flattened_list_parameters_become_an_array_schema_and_aliases_are_normalized(): void
    {
        $registry = new ActionRegistry();
        $registry->register([
            'id' => 'orders.create',
            'label' => 'Create order',
            'description' => 'Create an order.',
            'operation' => 'create',
            'required' => [],
            'confirmation_required' => false,
            'parameters' => [
                'customer_name' => ['type' => 'string', 'required' => true],
                'items.*.product_name' => ['type' => 'string', 'required' => true],
                'items.*.quantity' => ['type' => 'number'],
            ],
            'argument_aliases' => [
                'customer' => 'customer_name',
                'line_items' => 'items',
                'items.*.description' => 'items.*.product_name',
                'items.*.qty' => 'items.*.quantity',
            ],
            'handler' => fn (array $payload): ActionResult => ActionResult::success('Created order.', $payload),
        ]);
        $tool = new CreateOrderActionTool(new ActionOrchestrator($registry));

        $parameters = $tool->getParameters();
        $this->assertArrayNotHasKey('items.*.product_name', $parameters);
        $this->assertSame('array', $parameters['items']['type']);
        $this->assertSame(['type' => 'string'], $parameters['items']['items']['properties']['product_name']);
        $this->assertSame(['product_name'], $parameters['items']['items']['required']);

        $arguments = ['customer' => 'Acme', 'line_items' => [['description' => 'Paper', 'qty' => 10]]];
        $this->assertSame([], $tool->validate($arguments));

        $result = $tool->execute($arguments, new UnifiedActionContext('action-aliases', 5));
        $this->assertTrue($result->success);
        $this->assertSame('Acme', $result->data['customer_name']);
        $this->assertSame([['product_name' => 'Paper', 'quantity' => 10]], $result->data['items']);
        $this->assertArrayNotHasKey('line_items', $result->data);
    }

    private function orchestrator(): ActionOrchestrator
    {
        $registry = new ActionRegistry();
        $registry->register([
            'id' => 'notes.create',
            'label' => 'Create Note',
            'description' => 'Create a note.',
            'operation' => 'create',
            'risk' => 'low',
            'required' => ['title'],
            'parameters' => [
                'title' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'string', 'required' => false],
            ],
            'handler' => fn (array $payload, ?UnifiedActionContext $context): ActionResult => ActionResult::success(
                'Created note.',
                $payload + ['user_id' => $context?->userId]
            ),
        ]);
        $registry->register([
            'id' => 'notes.delete',
            'label' => 'Delete Note',
            'description' => 'Delete a note.',
            'operation' => 'delete',
            'risk' => 'high',
            'required' => ['id'],
            'parameters' => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
            'handler' => fn (array $payload): ActionResult => ActionResult::success('Deleted note.', $payload),
        ]);

        return new ActionOrchestrator($registry);
    }
}

class CreateNoteActionTool extends ActionBackedTool
{
    public string $actionId = 'notes.create';
}

class DeleteNoteActionTool extends ActionBackedTool
{
    public string $actionId = 'notes.delete';
}

class CreateOrderActionTool extends ActionBackedTool
{
    public string $actionId = 'orders.create';
}
