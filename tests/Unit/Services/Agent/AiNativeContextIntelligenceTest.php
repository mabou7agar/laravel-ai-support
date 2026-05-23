<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AgentContextSnapshotBuilder;
use LaravelAIEngine\Services\Agent\AiNative\AgentTaskStateService;
use LaravelAIEngine\Services\Agent\AiNative\ToolOutcomeNormalizer;
use LaravelAIEngine\Tests\UnitTestCase;

class AiNativeContextIntelligenceTest extends UnitTestCase
{
    public function test_tool_outcome_normalizer_infers_created_entity_without_app_specific_code(): void
    {
        $outcome = (new ToolOutcomeNormalizer())->normalize(
            'create_invoice',
            ['customer_name' => 'Ahmed'],
            ActionResult::success('Invoice created.', [
                'invoice' => [
                    'id' => 77,
                    'invoice_number' => 'INV-77',
                    'customer_name' => 'Ahmed',
                ],
            ])
        );

        $this->assertSame('create_invoice', $outcome['tool']);
        $this->assertSame('created', $outcome['outcome']);
        $this->assertSame('invoice', $outcome['entity_type']);
        $this->assertSame(77, $outcome['entity_id']);
        $this->assertSame('INV-77', $outcome['label']);
        $this->assertFalse($outcome['visible_to_user']);
    }

    public function test_tool_outcome_normalizer_uses_generic_suffix_fields_for_labels(): void
    {
        $outcome = (new ToolOutcomeNormalizer())->normalize(
            'lookup_vendor',
            ['query' => 'Northwind'],
            ActionResult::success('Vendor found.', [
                'found' => true,
                'id' => 701,
                'vendor_name' => 'Northwind',
                'vendor_number' => 'VEN-701',
            ])
        );

        $this->assertSame('vendor', $outcome['entity_type']);
        $this->assertSame(701, $outcome['entity_id']);
        $this->assertSame('Northwind', $outcome['label']);
    }

    public function test_task_state_tracks_pending_confirmation_and_completed_write_signature(): void
    {
        $state = [];
        $service = new AgentTaskStateService(new ToolOutcomeNormalizer());

        $service->markPendingConfirmation($state, 'create_invoice', [
            'customer_id' => 77,
            'customer_name' => 'Ahmed',
            'confirmed' => true,
        ]);

        $this->assertSame('confirming', $state['task_frame']['status']);
        $this->assertSame('create_invoice', $state['task_frame']['pending_tool']['name']);

        $service->recordToolResult($state, 'create_invoice', [
            'customer_id' => 77,
            'customer_name' => 'Ahmed',
            'confirmed' => true,
        ], ActionResult::success('Invoice created.', ['id' => 9001, 'name' => 'INV-9001']), true);

        $this->assertSame('completed', $state['task_frame']['status']);
        $this->assertNull($state['task_frame']['pending_tool']);
        $this->assertCount(1, $state['task_frame']['completed_writes']);
        $this->assertTrue($service->hasCompletedWrite($state, 'create_invoice', [
            'customer_name' => 'Ahmed',
            'customer_id' => 77,
        ]));
    }

    public function test_current_payload_updates_preserve_existing_associative_fields_and_replace_lists(): void
    {
        $state = [];
        $service = new AgentTaskStateService(new ToolOutcomeNormalizer());

        $service->rememberCurrentPayload($state, [
            'customer_name' => 'Ahmed',
            'customer_email' => 'ahmed@example.com',
            'items' => [
                ['product_name' => 'Laptop', 'quantity' => 1],
            ],
        ], 'ai_plan');

        $service->rememberCurrentPayload($state, [
            'items' => [
                ['product_name' => 'Laptop', 'quantity' => 2],
                ['product_name' => 'Phone', 'quantity' => 1],
            ],
        ], 'ai_plan');

        $this->assertSame('Ahmed', $state['task_frame']['current_payload']['customer_name']);
        $this->assertSame('ahmed@example.com', $state['task_frame']['current_payload']['customer_email']);
        $this->assertCount(2, $state['task_frame']['current_payload']['items']);
        $this->assertSame(2, $state['task_frame']['current_payload']['items'][0]['quantity']);
    }

    public function test_not_found_lookup_preserves_missing_entity_label_in_current_payload(): void
    {
        $state = [
            'task_frame' => [
                'active_objective' => 'create_invoice',
                'status' => 'working',
                'current_payload' => [
                    'customer_email' => 'missing@example.com',
                    'items' => [
                        ['product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                    ],
                ],
            ],
        ];
        $service = new AgentTaskStateService(new ToolOutcomeNormalizer());

        $service->recordToolResult($state, 'find_customer', ['query' => 'Missing Corp'], ActionResult::failure('Record was not found.', [
            'found' => false,
            'message' => 'Record was not found.',
            'required_fields' => ['name', 'email'],
        ]));

        $this->assertSame('Missing Corp', $state['task_frame']['current_payload']['customer_name']);
        $this->assertSame('missing@example.com', $state['task_frame']['current_payload']['customer_email']);
        $this->assertSame('Laptop', $state['task_frame']['current_payload']['items'][0]['product_name']);
    }

    public function test_not_found_lookup_does_not_pollute_top_level_payload_for_list_items(): void
    {
        $state = [
            'task_frame' => [
                'active_objective' => 'create_invoice',
                'status' => 'working',
                'current_payload' => [
                    'customer_name' => 'Missing Corp',
                    'items' => [
                        ['product_name' => 'Missing Widget', 'quantity' => 2, 'unit_price' => 100],
                    ],
                ],
            ],
        ];
        $service = new AgentTaskStateService(new ToolOutcomeNormalizer());

        $service->recordToolResult($state, 'find_product', ['query' => 'Missing Widget'], ActionResult::success('Record was not found.', [
            'found' => false,
            'message' => 'Record was not found.',
            'required_fields' => ['name', 'price'],
        ]));

        $this->assertArrayNotHasKey('product_name', $state['task_frame']['current_payload']);
        $this->assertSame('Missing Widget', $state['task_frame']['current_payload']['items'][0]['product_name']);
    }

    public function test_completed_write_signature_tolerates_resolved_relation_ids_when_names_are_present(): void
    {
        $state = [];
        $service = new AgentTaskStateService(new ToolOutcomeNormalizer());

        $service->recordToolResult($state, 'create_invoice', [
            'customer_name' => 'Ahmed',
            'items' => [
                ['product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
            ],
        ], ActionResult::success('Invoice created.', ['id' => 9001, 'name' => 'INV-9001']), true);

        $this->assertTrue($service->hasCompletedWrite($state, 'create_invoice', [
            'customer_id' => 77,
            'customer_name' => 'Ahmed',
            'items' => [
                ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
            ],
        ]));
    }

    public function test_completed_write_signature_tolerates_generic_number_fields(): void
    {
        $state = [];
        $service = new AgentTaskStateService(new ToolOutcomeNormalizer());

        $service->recordToolResult($state, 'create_ticket', [
            'ticket_number' => 'TCK-9',
            'title' => 'Broken access',
        ], ActionResult::success('Ticket created.', ['id' => 9, 'ticket_number' => 'TCK-9']), true);

        $this->assertTrue($service->hasCompletedWrite($state, 'create_ticket', [
            'ticket_id' => 9,
            'ticket_number' => 'TCK-9',
            'title' => 'Broken access',
        ]));
    }

    public function test_completed_write_signature_keeps_distinct_relation_ids(): void
    {
        $state = [];
        $service = new AgentTaskStateService(new ToolOutcomeNormalizer());

        $service->recordToolResult($state, 'create_contract', [
            'vendor_id' => 1,
            'vendor_name' => 'Acme',
            'title' => 'Support',
        ], ActionResult::success('Contract created.', ['id' => 9001, 'name' => 'Support']), true);

        $this->assertFalse($service->hasCompletedWrite($state, 'create_contract', [
            'vendor_id' => 2,
            'vendor_name' => 'Acme',
            'title' => 'Support',
        ]));
    }

    public function test_context_snapshot_exposes_compact_task_state_for_ai_planning(): void
    {
        $state = [];
        $taskState = new AgentTaskStateService(new ToolOutcomeNormalizer());
        $taskState->markPendingConfirmation($state, 'create_invoice', [
            'customer_id' => 77,
            'customer_name' => 'Ahmed',
            'items' => [
                ['product_id' => 10, 'product_name' => 'Macbook Pro', 'quantity' => 2],
            ],
        ]);
        $taskState->rememberCurrentPayload($state, [
            'customer_id' => 77,
            'customer_name' => 'Ahmed',
            'items' => [
                ['product_id' => 10, 'product_name' => 'Macbook Pro', 'quantity' => 2],
            ],
        ], 'test');
        $taskState->recordToolResult($state, 'find_customer', ['query' => 'Ahmed'], ActionResult::success('Customer found.', [
            'found' => true,
            'id' => 77,
            'name' => 'Ahmed',
        ]));

        $snapshot = (new AgentContextSnapshotBuilder())->build(
            new UnifiedActionContext('context-snapshot', 1),
            $state
        );

        $this->assertSame('confirming', $snapshot['active_task']['status']);
        $this->assertSame('create_invoice', $snapshot['pending_confirmation']['tool']);
        $this->assertSame('Ahmed', $snapshot['pending_confirmation']['summary']['Customer']);
        $this->assertSame('Ahmed', $snapshot['current_payload']['customer_name']);
        $this->assertSame(2, $snapshot['current_payload']['items'][0]['quantity']);
        $this->assertSame('customer', $snapshot['resolved_entities'][0]['type']);
        $this->assertSame(77, $snapshot['resolved_entities'][0]['internal_id']);
        $this->assertFalse($snapshot['resolved_entities'][0]['visible_to_user']);
        $this->assertSame('found', $snapshot['recent_outcomes'][0]['outcome']);
    }
}
