<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\AiNative\AiNativeToolSchemaMapper;
use LaravelAIEngine\Tests\UnitTestCase;

class AiNativeToolSchemaMapperTest extends UnitTestCase
{
    public function test_maps_agent_parameter_documents_to_provider_json_schema(): void
    {
        $definitions = (new AiNativeToolSchemaMapper())->map([[
            'name' => 'lookup_customer',
            'description' => 'Find a customer.',
            'parameters' => [
                'query' => ['type' => 'string', 'required' => true],
                'limit' => ['type' => 'integer', 'required' => false],
                'context' => ['type' => 'mixed', 'required' => false],
            ],
        ]]);

        $lookup = $definitions[0];

        $this->assertSame('lookup_customer', $lookup['name']);
        $this->assertSame('object', $lookup['parameters']['type']);
        $this->assertSame(['query'], $lookup['parameters']['required']);
        $this->assertSame('string', $lookup['parameters']['properties']['query']['type']);
        $this->assertArrayNotHasKey('required', $lookup['parameters']['properties']['query']);
        $this->assertArrayNotHasKey('type', $lookup['parameters']['properties']['context']);
        $this->assertSame(AiNativeToolSchemaMapper::FINAL_TOOL, $definitions[1]['name']);
        $this->assertSame(AiNativeToolSchemaMapper::ASK_USER_TOOL, $definitions[2]['name']);
    }
}
