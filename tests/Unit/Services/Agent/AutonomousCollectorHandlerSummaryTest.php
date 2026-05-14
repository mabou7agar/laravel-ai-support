<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorService;
use LaravelAIEngine\Tests\UnitTestCase;

class AutonomousCollectorHandlerSummaryTest extends UnitTestCase
{
    public function test_generate_summary_hides_empty_fields_and_formats_user_friendly_items(): void
    {
        $handler = new AutonomousCollectorHandler(
            $this->createMock(AutonomousCollectorService::class)
        );

        $config = new AutonomousCollectorConfig(
            goal: 'Create invoice',
            context: [
                'collector_display' => [
                    'decimal_fields' => ['subtotal', 'total', 'unit_price'],
                    'item_summaries' => [
                        'items' => [
                            'label_fields' => ['name'],
                            'quantity_field' => 'quantity',
                            'unit_value_field' => 'unit_price',
                            'total_field' => 'total',
                        ],
                    ],
                ],
            ],
            entityResolvers: [
                'customer_id' => fn (int $id): array => [
                    'Name' => 'Mohamed Abou Hagar',
                    'Email' => 'm.abou7agarx@gmail.com',
                ],
            ]
        );

        $data = [
            'customer_id' => 2,
            'customer_user_id' => 905,
            'account_id' => null,
            'subtotal' => 700,
            'tax' => null,
            'total' => 700,
            'notes' => '',
            'items' => [
                [
                    'product_id' => 2,
                    'name' => 'Macbook Pro M1',
                    'quantity' => 2,
                    'unit_price' => 300,
                    'total' => 600,
                ],
                [
                    'product_id' => 3,
                    'name' => 'Glass',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'total' => 100,
                ],
            ],
        ];

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('generateSummary');
        $method->setAccessible(true);

        $summary = (string) $method->invoke($handler, $data, 0, $config, [
            [
                'tool' => 'create_customer',
                'success' => true,
                'result' => [
                    'id' => 2,
                    'user_id' => 905,
                    'name' => 'Mohamed Abou Hagar',
                    'email' => 'm.abou7agarx@gmail.com',
                ],
            ],
        ]);

        $this->assertStringContainsString('Customer', $summary);
        $this->assertStringContainsString('Mohamed Abou Hagar', $summary);
        $this->assertStringContainsString('Macbook Pro M1 × 2 @ 300.00 = 600.00', $summary);
        $this->assertStringNotContainsString('Customer User', $summary);
        $this->assertStringNotContainsString('Account', $summary);
        $this->assertStringNotContainsString('Tax', $summary);
        $this->assertStringNotContainsString('Notes', $summary);
    }

    public function test_build_success_message_uses_product_name_for_line_items(): void
    {
        $handler = new AutonomousCollectorHandler(
            $this->createMock(AutonomousCollectorService::class)
        );

        $config = new AutonomousCollectorConfig(goal: 'Create invoice');

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('buildSuccessMessage');
        $method->setAccessible(true);

        $message = (string) $method->invoke($handler, [
            'invoice_number' => 'INV-1',
            'total' => 6595,
        ], [
            'items' => [
                [
                    'product_name' => 'Macbook Pro',
                    'quantity' => 2,
                    'unit_price' => 1999,
                ],
                [
                    'product_name' => 'iPhone',
                    'quantity' => 2,
                    'unit_price' => 999,
                ],
            ],
        ], $config);

        $this->assertStringContainsString('Macbook Pro', $message);
        $this->assertStringContainsString('iPhone', $message);
        $this->assertStringNotContainsString('Unknown', $message);
    }

    public function test_write_tools_require_explicit_confirmation_before_execution(): void
    {
        $handler = new AutonomousCollectorHandler(
            $this->createMock(AutonomousCollectorService::class)
        );

        $config = new AutonomousCollectorConfig(
            goal: 'Create customer',
            tools: [
                'create_customer' => [
                    'description' => 'Create a customer',
                    'requires_confirmation' => true,
                    'parameters' => ['name' => 'required|string', 'email' => 'required|string'],
                    'handler' => fn (array $arguments): array => $arguments,
                ],
            ]
        );

        $reflection = new \ReflectionClass($handler);
        $requiresConfirmation = $reflection->getMethod('requiresToolConfirmation');
        $requiresConfirmation->setAccessible(true);
        $isConfirmedToolCall = $reflection->getMethod('isConfirmedToolCall');
        $isConfirmedToolCall->setAccessible(true);
        $buildMessage = $reflection->getMethod('buildToolConfirmationMessage');
        $buildMessage->setAccessible(true);

        $this->assertTrue($requiresConfirmation->invoke($handler, $config, 'create_customer'));
        $this->assertFalse($isConfirmedToolCall->invoke($handler, 'create_customer', [
            ['role' => 'user', 'content' => 'create invoice'],
        ]));
        $this->assertTrue($isConfirmedToolCall->invoke($handler, 'create_customer', [
            ['role' => 'user', 'content' => 'yes create the customer'],
        ]));

        $message = (string) $buildMessage->invoke($handler, 'create_customer', [
            'name' => 'Mohamed Hagar',
            'email' => 'mohamed@example.test',
        ], $config);

        $this->assertStringContainsString('Please confirm', $message);
        $this->assertStringContainsString('Mohamed Hagar', $message);
        $this->assertStringContainsString('mohamed@example.test', $message);
    }
}
