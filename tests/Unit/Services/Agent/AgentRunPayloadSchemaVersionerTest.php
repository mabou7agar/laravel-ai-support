<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\AgentRunPayloadSchemaVersioner;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentRunPayloadSchemaVersionerTest extends UnitTestCase
{
    public function test_legacy_run_payloads_are_upgraded_to_current_schema(): void
    {
        $attributes = (new AgentRunPayloadSchemaVersioner())->normalizeRunAttributes([
            'schema_version' => 0,
            'trace' => '[{"stage":"legacy"}]',
            'response' => '{"message":"done"}',
            'input' => '{"message":"hello"}',
        ]);

        $this->assertSame(AgentRunPayloadSchemaVersioner::CURRENT_VERSION, $attributes['schema_version']);
        $this->assertSame([['stage' => 'legacy']], $attributes['routing_trace']);
        $this->assertSame(['message' => 'done'], $attributes['final_response']);
        $this->assertSame(['message' => 'hello'], $attributes['input']);
        $this->assertArrayNotHasKey('trace', $attributes);
        $this->assertArrayNotHasKey('response', $attributes);
    }

    public function test_step_payloads_are_tagged_with_schema_version(): void
    {
        $attributes = (new AgentRunPayloadSchemaVersioner())->normalizeStepAttributes([
            'input' => '{"message":"hello"}',
            'metadata' => ['trace_id' => 'trace-1'],
        ]);

        $this->assertSame(['message' => 'hello'], $attributes['input']);
        $this->assertSame(AgentRunPayloadSchemaVersioner::CURRENT_VERSION, $attributes['metadata']['schema_version']);
        $this->assertSame('trace-1', $attributes['metadata']['trace_id']);
    }
}
