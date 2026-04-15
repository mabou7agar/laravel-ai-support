<?php

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Support\Str;

class GraphVectorNamingService
{
    public function indexName(?string $explicit = null, ?string $scopeNode = null, ?string $scopeTenant = null): string
    {
        $base = $explicit ?: (string) config('ai-engine.graph.neo4j.chunk_vector_index', 'chunk_embedding_index');

        return $this->applyStrategy($base, $scopeNode, $scopeTenant);
    }

    public function propertyName(?string $explicit = null, ?string $scopeNode = null, ?string $scopeTenant = null): string
    {
        $base = $explicit ?: (string) config('ai-engine.graph.neo4j.chunk_vector_property', 'embedding');

        return $this->applyStrategy($base, $scopeNode, $scopeTenant);
    }

    public function strategy(): string
    {
        $configured = trim((string) config('ai-engine.graph.neo4j.vector_naming.strategy', ''));
        if ($configured !== '') {
            return $configured;
        }

        return (bool) config('ai-engine.graph.neo4j.shared_deployment', false)
            ? 'node'
            : 'static';
    }

    public function scopeNode(?string $override = null): ?string
    {
        $value = $override ?: config('ai-engine.graph.neo4j.vector_naming.node_slug');
        if (!is_string($value) || trim($value) === '') {
            $value = config('ai-engine.nodes.local.slug') ?: config('app.name');
        }

        return $this->normalizeSegment((string) $value);
    }

    public function scopeTenant(?string $override = null): ?string
    {
        $value = $override ?: config('ai-engine.graph.neo4j.vector_naming.tenant_key');
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }

    protected function applyStrategy(string $base, ?string $scopeNode, ?string $scopeTenant): string
    {
        $segments = [$this->sanitizeName($base)];
        $strategy = $this->strategy();
        $node = $this->scopeNode($scopeNode);
        $tenant = $this->scopeTenant($scopeTenant);

        if (in_array($strategy, ['node', 'node_tenant'], true) && $node !== null) {
            $segments[] = $node;
        }

        if (in_array($strategy, ['tenant', 'node_tenant'], true) && $tenant !== null) {
            $segments[] = $tenant;
        }

        return implode('_', array_values(array_filter($segments, static fn ($segment): bool => $segment !== '')));
    }

    protected function sanitizeName(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '_', trim($value)) ?: '';
        $sanitized = preg_replace('/_+/', '_', $sanitized) ?: '';
        $sanitized = trim($sanitized, '_');

        return $sanitized !== '' ? $sanitized : 'embedding';
    }

    protected function normalizeSegment(string $value): ?string
    {
        $normalized = Str::snake(trim($value));
        $normalized = $this->sanitizeName($normalized);

        return $normalized !== '' ? $normalized : null;
    }
}
