<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Contracts\ActionFlowHandler;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionDraftService;
use LaravelAIEngine\Services\Memory\CacheConversationMemory;
use LaravelAIEngine\Tests\TestCase;

class ActionDraftServiceTest extends TestCase
{
    public function test_patch_prepare_stores_draft_and_normalizes_relation_approval(): void
    {
        $handler = new class implements ActionFlowHandler {
            public function action(string $actionId, ?UnifiedActionContext $context = null): ?array
            {
                return ['id' => $actionId, 'initial_payload' => ['status' => 'draft']];
            }

            public function catalog(?UnifiedActionContext $context = null, ?string $module = null): array
            {
                return ['success' => true, 'actions' => []];
            }

            public function prepare(string $actionId, array $payload, ?UnifiedActionContext $context = null): array
            {
                return [
                    'success' => true,
                    'message' => 'Ready.',
                    'requires_confirmation' => true,
                    'next_options' => empty($payload['approved_missing_relations'])
                        ? [[
                            'type' => 'relation_create_confirmation',
                            'approval_key' => 'related_id',
                        ]]
                        : [],
                    'draft' => ['payload' => $payload],
                ];
            }

            public function execute(string $actionId, array $payload, bool $confirmed, ?UnifiedActionContext $context = null): ActionResult|array
            {
                return ['success' => true, 'message' => 'Executed.', 'data' => $payload];
            }

            public function suggest(array $contextData = [], ?UnifiedActionContext $context = null): array
            {
                return ['success' => true, 'suggestions' => []];
            }
        };

        $service = new ActionDraftService($handler, new CacheConversationMemory());
        $context = new UnifiedActionContext('draft-unit-' . uniqid(), 42);

        $service->patchAndPrepare($context, 'create_record', ['name' => 'Demo']);
        $context->metadata['latest_user_message'] = 'yes create it';
        $result = $service->patchAndPrepare($context, 'create_record', ['approved_missing_relations' => true]);

        $this->assertSame(['related_id'], $result['current_payload']['approved_missing_relations']);
        $this->assertSame('draft', $result['current_payload']['status']);
    }

    public function test_patch_prepare_ignores_relation_approval_without_approval_message(): void
    {
        $handler = new class implements ActionFlowHandler {
            public function action(string $actionId, ?UnifiedActionContext $context = null): ?array
            {
                return ['id' => $actionId, 'initial_payload' => []];
            }

            public function catalog(?UnifiedActionContext $context = null, ?string $module = null): array
            {
                return ['success' => true, 'actions' => []];
            }

            public function prepare(string $actionId, array $payload, ?UnifiedActionContext $context = null): array
            {
                return [
                    'success' => true,
                    'next_options' => empty($payload['approved_missing_relations'])
                        ? [['type' => 'relation_create_confirmation', 'approval_key' => 'related_id']]
                        : [],
                    'draft' => ['payload' => $payload],
                ];
            }

            public function execute(string $actionId, array $payload, bool $confirmed, ?UnifiedActionContext $context = null): ActionResult|array
            {
                return ['success' => true];
            }

            public function suggest(array $contextData = [], ?UnifiedActionContext $context = null): array
            {
                return ['success' => true, 'suggestions' => []];
            }
        };

        $service = new ActionDraftService($handler, new CacheConversationMemory());
        $context = new UnifiedActionContext('draft-guard-' . uniqid(), 42);
        $context->metadata['latest_user_message'] = 'mohamed@example.test';

        $result = $service->patchAndPrepare($context, 'create_record', [
            'email' => 'mohamed@example.test',
            'approved_missing_relations' => ['related_id'],
        ]);

        $this->assertArrayNotHasKey('approved_missing_relations', $result['current_payload']);
        $this->assertSame('mohamed@example.test', $result['current_payload']['email']);
    }

    public function test_array_operations_append_update_and_remove_without_replacing_existing_items(): void
    {
        $handler = new class implements ActionFlowHandler {
            public function action(string $actionId, ?UnifiedActionContext $context = null): ?array
            {
                return ['id' => $actionId];
            }

            public function catalog(?UnifiedActionContext $context = null, ?string $module = null): array
            {
                return ['success' => true, 'actions' => []];
            }

            public function prepare(string $actionId, array $payload, ?UnifiedActionContext $context = null): array
            {
                return ['success' => true, 'draft' => ['payload' => $payload]];
            }

            public function execute(string $actionId, array $payload, bool $confirmed, ?UnifiedActionContext $context = null): ActionResult|array
            {
                return ['success' => true, 'data' => $payload];
            }

            public function suggest(array $contextData = [], ?UnifiedActionContext $context = null): array
            {
                return ['success' => true, 'suggestions' => []];
            }
        };

        $service = new ActionDraftService($handler, new CacheConversationMemory());
        $context = new UnifiedActionContext('draft-array-ops-' . uniqid(), 42);

        $service->patchAndPrepare($context, 'create_record', [
            'items' => [
                ['product_name' => 'MacBook Pro', 'quantity' => 2],
            ],
        ]);

        $appended = $service->patchAndPrepare($context, 'create_record', [
            'items' => [
                ['product_name' => 'Sample Phone 13 Pro Max', 'quantity' => 1],
            ],
            '_array_ops' => [
                [
                    'op' => 'append',
                    'path' => 'items',
                    'value' => ['product_name' => 'Sample Phone 13 Pro Max', 'quantity' => 1],
                ],
            ],
        ]);

        $this->assertSame([
            ['product_name' => 'MacBook Pro', 'quantity' => 2],
            ['product_name' => 'Sample Phone 13 Pro Max', 'quantity' => 1],
        ], $appended['current_payload']['items']);

        $updated = $service->patchAndPrepare($context, 'create_record', [
            '_array_ops' => [
                [
                    'op' => 'update',
                    'path' => 'items',
                    'match' => ['product_name' => 'MacBook Pro'],
                    'value' => ['unit_price' => 400],
                ],
            ],
        ]);

        $this->assertSame(400, $updated['current_payload']['items'][0]['unit_price']);
        $this->assertSame('Sample Phone 13 Pro Max', $updated['current_payload']['items'][1]['product_name']);

        $removed = $service->patchAndPrepare($context, 'create_record', [
            '_array_ops' => [
                [
                    'op' => 'remove',
                    'path' => 'items',
                    'match' => ['product_name' => 'MacBook Pro'],
                ],
            ],
        ]);

        $this->assertSame([
            ['product_name' => 'Sample Phone 13 Pro Max', 'quantity' => 1],
        ], $removed['current_payload']['items']);
    }

    public function test_array_operations_increment_and_decrement_numeric_item_fields(): void
    {
        $handler = new class implements ActionFlowHandler {
            public function action(string $actionId, ?UnifiedActionContext $context = null): ?array
            {
                return ['id' => $actionId];
            }

            public function catalog(?UnifiedActionContext $context = null, ?string $module = null): array
            {
                return ['success' => true, 'actions' => []];
            }

            public function prepare(string $actionId, array $payload, ?UnifiedActionContext $context = null): array
            {
                return ['success' => true, 'draft' => ['payload' => $payload]];
            }

            public function execute(string $actionId, array $payload, bool $confirmed, ?UnifiedActionContext $context = null): ActionResult|array
            {
                return ['success' => true, 'data' => $payload];
            }

            public function suggest(array $contextData = [], ?UnifiedActionContext $context = null): array
            {
                return ['success' => true, 'suggestions' => []];
            }
        };

        $service = new ActionDraftService($handler, new CacheConversationMemory());
        $context = new UnifiedActionContext('draft-array-ops-numeric-' . uniqid(), 42);

        $service->patchAndPrepare($context, 'create_record', [
            'items' => [
                ['product_name' => 'Sample Phone', 'quantity' => 3],
            ],
        ]);

        $decremented = $service->patchAndPrepare($context, 'create_record', [
            '_array_ops' => [[
                'op' => 'decrement',
                'path' => 'items',
                'match' => ['product_name' => 'Sample Phone'],
                'field' => 'quantity',
                'amount' => 1,
            ]],
        ]);

        $this->assertSame(2.0, $decremented['current_payload']['items'][0]['quantity']);

        $incremented = $service->patchAndPrepare($context, 'create_record', [
            '_array_ops' => [[
                'op' => 'increment',
                'path' => 'items',
                'match' => ['product_name' => 'Sample Phone'],
                'field' => 'quantity',
                'amount' => 2,
            ]],
        ]);

        $this->assertSame(4.0, $incremented['current_payload']['items'][0]['quantity']);
    }

    public function test_execute_replays_duplicate_confirmations_without_second_write(): void
    {
        $counter = (object) ['writes' => 0];
        $handler = new class($counter) implements ActionFlowHandler {
            public function __construct(private object $counter)
            {
            }

            public function action(string $actionId, ?UnifiedActionContext $context = null): ?array
            {
                return ['id' => $actionId];
            }

            public function catalog(?UnifiedActionContext $context = null, ?string $module = null): array
            {
                return ['success' => true, 'actions' => []];
            }

            public function prepare(string $actionId, array $payload, ?UnifiedActionContext $context = null): array
            {
                return ['success' => true, 'draft' => ['payload' => $payload]];
            }

            public function execute(string $actionId, array $payload, bool $confirmed, ?UnifiedActionContext $context = null): ActionResult|array
            {
                $this->counter->writes++;

                return ['success' => true, 'message' => 'Executed.', 'data' => ['writes' => $this->counter->writes]];
            }

            public function suggest(array $contextData = [], ?UnifiedActionContext $context = null): array
            {
                return ['success' => true, 'suggestions' => []];
            }
        };

        $service = new ActionDraftService($handler, new CacheConversationMemory());
        $context = new UnifiedActionContext('draft-execute-' . uniqid(), 42);
        $service->patchAndPrepare($context, 'create_record', ['name' => 'Demo']);

        $first = $service->execute($context, 'create_record', true);
        $second = $service->execute($context, 'create_record', true, ['name' => 'Demo']);

        $this->assertSame(1, $first['data']['writes']);
        $this->assertSame(1, $second['data']['writes']);
        $this->assertTrue($second['duplicate_confirmation_replayed']);
    }
}
