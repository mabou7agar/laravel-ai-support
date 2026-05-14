<?php

namespace LaravelAIEngine\Tests\Unit\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Http\Controllers\Concerns\ExtractsConversationContextPayload;
use LaravelAIEngine\Tests\UnitTestCase;

class ExtractsConversationContextPayloadTest extends UnitTestCase
{
    public function test_it_exposes_focused_entity_and_conversation_about_from_selected_entity_context(): void
    {
        $extractor = new class {
            use ExtractsConversationContextPayload;

            public function extract(array $metadata): array
            {
                return $this->extractConversationContextPayload($metadata);
            }
        };

        $invoice = new class extends Model {
            protected $guarded = [];

            public function toArray(): array
            {
                return [
                    'id' => 15,
                    'invoice_number' => 'INV-15',
                    'customer_name' => 'Sample Customer',
                ];
            }
        };

        $payload = $extractor->extract([
            'metadata' => [
                'selected_entity_context' => [
                    'entity_id' => 15,
                    'entity_type' => 'invoice',
                    'entity_data' => $invoice,
                    'source_node' => 'inbusiness',
                    'selected_via' => 'numbered_option',
                ],
            ],
        ]);

        $this->assertSame(15, $payload['focused_entity_id']);
        $this->assertSame('invoice', $payload['focused_entity_type']);
        $this->assertSame('INV-15', $payload['focused_entity']['invoice_number']);
        $this->assertSame('invoice', $payload['conversation_about']['type']);
        $this->assertSame(15, $payload['conversation_about']['id']);
    }

    public function test_it_falls_back_to_last_entity_list_when_no_focused_entity_exists(): void
    {
        $extractor = new class {
            use ExtractsConversationContextPayload;

            public function extract(array $metadata): array
            {
                return $this->extractConversationContextPayload($metadata);
            }
        };

        $payload = $extractor->extract([
            'metadata' => [
                'last_entity_list' => [
                    'entity_type' => 'email',
                    'entity_ids' => [4, 8, 15],
                    'start_position' => 1,
                    'end_position' => 3,
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('focused_entity', $payload);
        $this->assertSame('email', $payload['conversation_about']['type']);
        $this->assertSame([4, 8, 15], $payload['conversation_about']['entity_ids']);
    }

    public function test_it_exposes_pre_create_summary_entity_without_ids(): void
    {
        $extractor = new class {
            use ExtractsConversationContextPayload;

            public function extract(array $metadata): array
            {
                return $this->extractConversationContextPayload($metadata);
            }
        };

        $payload = $extractor->extract([
            'flow_name' => 'CreateInvoiceFlow',
            'runtime_data' => [
                'collected_data' => [
                    'id' => 999,
                    'invoice_number' => 'DRAFT-1',
                    'customer_id' => 44,
                    'customer_name' => 'Sample Customer',
                    'items' => [
                        [
                            'id' => 10,
                            'product_id' => 77,
                            'name' => 'Alpha Laptop 15',
                            'quantity' => 10,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('invoice', $payload['focused_entity_type']);
        $this->assertArrayNotHasKey('id', $payload['focused_entity']);
        $this->assertArrayNotHasKey('customer_id', $payload['focused_entity']);
        $this->assertSame('DRAFT-1', $payload['focused_entity']['invoice_number']);
        $this->assertArrayNotHasKey('id', $payload['focused_entity']['items'][0]);
        $this->assertArrayNotHasKey('product_id', $payload['focused_entity']['items'][0]);
        $this->assertSame('draft_summary', $payload['conversation_about']['selected_via']);
    }
}
