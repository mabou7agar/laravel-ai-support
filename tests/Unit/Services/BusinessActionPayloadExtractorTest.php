<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\BusinessActions\BusinessActionPayloadExtractor;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class BusinessActionPayloadExtractorTest extends TestCase
{
    public function test_extracts_structured_payload_patch_from_business_action_schema(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->withArgs(fn ($request): bool => str_contains($request->getPrompt(), 'BUSINESS_ACTION_PAYLOAD_EXTRACTOR')
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

        $extractor = new BusinessActionPayloadExtractor($ai);

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
        config()->set('ai-agent.business_action_payload_extraction.enabled', false);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldNotReceive('generate');

        $extractor = new BusinessActionPayloadExtractor($ai);

        $this->assertNull($extractor->extract($this->invoiceAction(), 'create invoice'));
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
}
