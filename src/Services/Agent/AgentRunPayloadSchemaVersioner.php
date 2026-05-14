<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

class AgentRunPayloadSchemaVersioner
{
    public const CURRENT_VERSION = 1;

    public function normalizeRunAttributes(array $attributes): array
    {
        $version = (int) ($attributes['schema_version'] ?? self::CURRENT_VERSION);

        if ($version < 1) {
            $attributes = $this->upgradeRunFromLegacy($attributes);
        }

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

    protected function upgradeRunFromLegacy(array $attributes): array
    {
        if (!array_key_exists('routing_trace', $attributes) && array_key_exists('trace', $attributes)) {
            $attributes['routing_trace'] = $attributes['trace'];
            unset($attributes['trace']);
        }

        if (!array_key_exists('final_response', $attributes) && array_key_exists('response', $attributes)) {
            $response = $this->normalizeJsonPayload($attributes['response']);
            $attributes['final_response'] = is_array($response) ? $response : ['message' => (string) $response];
            unset($attributes['response']);
        }

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
