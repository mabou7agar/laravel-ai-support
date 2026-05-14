<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

class AgentRunPayloadSchemaVersioner
{
    public const CURRENT_VERSION = 1;

    public function normalizeRunAttributes(array $attributes): array
    {
        $attributes['schema_version'] = self::CURRENT_VERSION;

        foreach (['input', 'final_response', 'routing_trace', 'metadata'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $this->normalizeJsonPayload($attributes[$key]);
            }
        }

        return $attributes;
    }

    public function normalizeStepAttributes(array $attributes): array
    {
        foreach (['input', 'output', 'routing_decision', 'routing_trace', 'approvals', 'artifacts', 'metadata'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $this->normalizeJsonPayload($attributes[$key]);
            }
        }

        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
        $metadata['schema_version'] = self::CURRENT_VERSION;
        $attributes['metadata'] = $metadata;

        return $attributes;
    }

    protected function normalizeJsonPayload(mixed $payload): mixed
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $payload;
        }

        return $payload;
    }
}
