<?php

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\DataCollectorConfig;
use LaravelAIEngine\Tests\UnitTestCase;

class DataCollectorConfigTest extends UnitTestCase
{
    public function test_parses_associative_fields_map(): void
    {
        $config = new DataCollectorConfig(
            title: 'Invoice',
            fields: [
                'customer_name' => 'Customer name | required | min:2',
                'notes' => [
                    'description' => 'Invoice notes',
                    'required' => false,
                ],
            ]
        );

        $this->assertSame(['customer_name', 'notes'], $config->getFieldNames());
        $this->assertTrue($config->getField('customer_name')?->required ?? false);
        $this->assertFalse($config->getField('notes')?->required ?? true);
    }

    public function test_parses_indexed_fields_with_name_property(): void
    {
        $config = new DataCollectorConfig(
            title: 'Invoice',
            fields: [
                [
                    'name' => 'customer_name',
                    'description' => 'Customer name',
                    'required' => true,
                ],
                [
                    'name' => 'issue_date',
                    'description' => 'Issue date',
                    'validation' => 'required|date',
                ],
            ]
        );

        $this->assertSame(['customer_name', 'issue_date'], $config->getFieldNames());
        $this->assertSame('Customer name', $config->getField('customer_name')?->description);
    }

    public function test_parses_indexed_single_key_field_objects(): void
    {
        $config = new DataCollectorConfig(
            title: 'Invoice',
            fields: [
                [
                    'customer_name' => 'Customer name | required',
                ],
                [
                    'notes' => [
                        'description' => 'Optional notes',
                        'required' => false,
                    ],
                ],
            ]
        );

        $this->assertSame(['customer_name', 'notes'], $config->getFieldNames());
        $this->assertFalse($config->getField('notes')?->required ?? true);
    }

    public function test_generates_fallback_name_when_indexed_field_has_no_name(): void
    {
        $config = new DataCollectorConfig(
            title: 'Invoice',
            fields: [
                [
                    'description' => 'Unnamed field',
                ],
            ]
        );

        $this->assertSame(['field_1'], $config->getFieldNames());
        $this->assertSame('Unnamed field', $config->getField('field_1')?->description);
    }
}
