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
}

