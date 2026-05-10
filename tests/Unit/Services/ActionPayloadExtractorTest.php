<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\Actions\ActionPayloadExtractor;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class ActionPayloadExtractorTest extends TestCase
{
    public function test_extracts_structured_payload_patch_from_action_schema(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->withArgs(fn ($request): bool => str_contains($request->getPrompt(), 'ACTION_PAYLOAD_EXTRACTOR')
                && str_contains($request->getPrompt(), 'create_sales_invoice')
                && str_contains($request->getPrompt(), '5 Macbook Pro and 4 iPhone'))
            ->andReturn(AIResponse::success(json_encode([
                'payload_patch' => [
                    'customer_name' => 'Mohamed Abou Hagar',
                    'ignored_field' => 'remove me',
                    'items' => [
                        [
                            'product_name' => 'Macbook Pro',
                            'quantity' => 5,
                            'unit_price' => 500,
                            'ignored_item_field' => 'remove me',
                        ],
                        [
                            'product_name' => 'iPhone',
                            'quantity' => 4,
                            'unit_price' => 200,
                        ],
                    ],
                ],
                'confidence' => 0.95,
            ]), 'openai', 'gpt-4o'));

        $extractor = new ActionPayloadExtractor($ai);

        $payload = $extractor->extract($this->invoiceAction(), '5 Macbook Pro and 4 iPhone', [
            'customer_name' => 'Mohamed Abou Hagar',
        ]);

        $this->assertSame([
            'customer_name' => 'Mohamed Abou Hagar',
            'items' => [
                [
                    'product_name' => 'Macbook Pro',
                    'quantity' => 5,
                    'unit_price' => 500,
                ],
                [
                    'product_name' => 'iPhone',
                    'quantity' => 4,
                    'unit_price' => 200,
                ],
            ],
        ], $payload);
    }

    public function test_returns_null_when_extraction_is_disabled(): void
    {
        config()->set('ai-agent.action_payload_extraction.enabled', false);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldNotReceive('generate');

        $extractor = new ActionPayloadExtractor($ai);

        $this->assertNull($extractor->extract($this->invoiceAction(), 'create invoice'));
    }

    public function test_does_not_accept_relation_approval_from_non_approval_message(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(json_encode([
                'payload_patch' => [
                    'customer_email' => 'mohamed@example.test',
                    'approved_missing_relations' => ['customer_id'],
                ],
            ]), 'openai', 'gpt-4o'));

        $extractor = new ActionPayloadExtractor($ai);

        $payload = $extractor->extract($this->invoiceActionWithRelationApproval(), 'mohamed@example.test', [
            'customer_name' => 'Mohamed Abou Hagar',
        ]);

        $this->assertSame([
            'customer_email' => 'mohamed@example.test',
        ], $payload);
    }

    public function test_accepts_relation_approval_from_approval_message(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(json_encode([
                'payload_patch' => [
                    'approved_missing_relations' => ['customer_id'],
                ],
            ]), 'openai', 'gpt-4o'));

        $extractor = new ActionPayloadExtractor($ai);

        $payload = $extractor->extract($this->invoiceActionWithRelationApproval(), 'yes create customer', [
            'customer_name' => 'Mohamed Abou Hagar',
            'customer_email' => 'mohamed@example.test',
        ]);

        $this->assertSame([
            'approved_missing_relations' => ['customer_id'],
        ], $payload);
    }

    public function test_normalizes_numeric_dates_using_configured_order(): void
    {
        config()->set('ai-agent.action_payload_extraction.numeric_date_order', 'dmy');

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(json_encode([
                'payload_patch' => [
                    'invoice_date' => '2026-08-05',
                ],
            ]), 'openai', 'gpt-4o'));

        $extractor = new ActionPayloadExtractor($ai);

        $payload = $extractor->extract($this->invoiceActionWithDates(), 'change date to 08-05-2026');

        $this->assertSame(['invoice_date' => '2026-05-08'], $payload);
    }

    public function test_preserves_sanitized_array_operations_for_append_messages(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->withArgs(fn ($request): bool => str_contains($request->getPrompt(), '_array_ops')
                && str_contains($request->getPrompt(), 'add iPhone 13 Pro Max'))
            ->andReturn(AIResponse::success(json_encode([
                'payload_patch' => [
                    '_array_ops' => [
                        [
                            'op' => 'append',
                            'path' => 'items',
                            'value' => [
                                'product_name' => 'iPhone 13 Pro Max',
                                'quantity' => 1,
                                'ignored_item_field' => 'remove me',
                            ],
                        ],
                        [
                            'op' => 'append',
                            'path' => 'unknown_items',
                            'value' => ['name' => 'unsafe'],
                        ],
                    ],
                    'ignored_field' => 'remove me',
                ],
            ]), 'openai', 'gpt-4o'));

        $extractor = new ActionPayloadExtractor($ai);

        $payload = $extractor->extract($this->invoiceAction(), 'add iPhone 13 Pro Max', [
            'items' => [
                ['product_name' => 'MacBook Pro', 'quantity' => 2],
            ],
        ]);

        $this->assertSame([
            '_array_ops' => [
                [
                    'op' => 'append',
                    'path' => 'items',
                    'value' => [
                        'product_name' => 'iPhone 13 Pro Max',
                        'quantity' => 1,
                    ],
                ],
            ],
        ], $payload);
    }

    private function invoiceAction(): array
    {
        return [
            'id' => 'create_sales_invoice',
            'label' => 'Create sales invoice',
            'description' => 'Create a draft sales invoice with line items.',
            'required' => ['customer_id', 'items'],
            'parameters' => [
                'customer_id' => ['type' => 'integer', 'required' => true],
                'customer_name' => ['type' => 'string', 'required' => false],
                'items' => ['type' => 'array', 'required' => true],
                'items.*.product_name' => ['type' => 'string', 'required' => false],
                'items.*.quantity' => ['type' => 'integer', 'required' => true],
                'items.*.unit_price' => ['type' => 'number', 'required' => true],
            ],
        ];
    }

    private function invoiceActionWithRelationApproval(): array
    {
        $action = $this->invoiceAction();
        $action['parameters']['customer_email'] = ['type' => 'email', 'required' => false];
        $action['parameters']['approved_missing_relations'] = ['type' => 'array', 'required' => false];

        return $action;
    }

    private function invoiceActionWithDates(): array
    {
        $action = $this->invoiceAction();
        $action['parameters']['invoice_date'] = ['type' => 'date', 'required' => true];
        $action['parameters']['due_date'] = ['type' => 'date', 'required' => true];

        return $action;
    }
}
