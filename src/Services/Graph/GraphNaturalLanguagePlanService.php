<?php

namespace LaravelAIEngine\Services\Graph;

class GraphNaturalLanguagePlanService
{
    public function __construct(
        protected ?GraphOntologyService $ontology = null
    ) {
        if ($this->ontology === null && app()->bound(GraphOntologyService::class)) {
            $this->ontology = app(GraphOntologyService::class);
        }
    }

    /**
     * @param array<int, string> $collections
     * @return array<string, mixed>
     */
    public function interpret(string $query, array $collections = [], array $options = [], int $maxResults = 5): array
    {
        $normalized = strtolower(trim($query));

        $queryKind = $this->queryKindOverride($normalized);
        $limitHint = $this->limitHint($normalized, $maxResults);
        $focusTerms = $this->focusTerms($query);
        $relationTypes = $this->ontology?->relationTypesForQuery($query, $collections) ?? [];
        $modelTypes = $this->ontology?->preferredModelTypesForQuery($query, $collections) ?? [];
        [$template, $direction] = $this->templateHints($normalized, $queryKind);

        return array_filter([
            'query_kind' => $queryKind,
            'limit_hint' => $limitHint,
            'focus_terms' => $focusTerms,
            'relation_types' => $relationTypes,
            'preferred_model_types' => $modelTypes,
            'cypher_template' => $template,
            'traversal_direction' => $direction,
            'requires_path_explanation' => $this->requiresPathExplanation($normalized),
            'explicit_time_filter' => $this->explicitTimeFilter($normalized),
            'selected_entity_bias' => !empty($options['selected_entity'] ?? $options['selected_entity_context'] ?? []),
        ], static fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    protected function queryKindOverride(string $normalizedQuery): ?string
    {
        return match (true) {
            preg_match('/\b(path|chain|ownership chain|dependency chain)\b/i', $normalizedQuery) === 1 => 'dependency',
            preg_match('/\b(in thread|in channel|conversation|mail thread|reply chain)\b/i', $normalizedQuery) === 1 => 'communication',
            preg_match('/\b(owner|ownership|assignee|manager|reporter)\b/i', $normalizedQuery) === 1 => 'ownership',
            preg_match('/\b(blocked by|depends on|upstream|downstream|dependency)\b/i', $normalizedQuery) === 1 => 'dependency',
            preg_match('/\b(history|timeline|changed|recent|latest|before|after|between)\b/i', $normalizedQuery) === 1 => 'timeline',
            preg_match('/\b(related|connected|around|context|involved|impact)\b/i', $normalizedQuery) === 1 => 'relationship',
            default => null,
        };
    }

    protected function limitHint(string $normalizedQuery, int $maxResults): ?int
    {
        if (preg_match('/\b(top|first|last)\s+(\d{1,2})\b/i', $normalizedQuery, $matches) === 1) {
            return max(1, min(50, (int) ($matches[2] ?? $maxResults)));
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function focusTerms(string $query): array
    {
        $terms = [];

        if (preg_match_all('/"([^"]+)"/', $query, $quoted) >= 1) {
            foreach ((array) ($quoted[1] ?? []) as $phrase) {
                $phrase = strtolower(trim((string) $phrase));
                if ($phrase !== '') {
                    $terms[] = $phrase;
                }
            }
        }

        if (preg_match_all('/\b[A-Z][A-Za-z0-9_-]{2,}\b/', $query, $named) >= 1) {
            foreach ((array) ($named[0] ?? []) as $token) {
                $token = strtolower(trim((string) $token));
                if ($token !== '') {
                    $terms[] = $token;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function templateHints(string $normalizedQuery, ?string $queryKind): array
    {
        if (preg_match('/\b(path|chain|trace|walk)\b/i', $normalizedQuery) === 1) {
            return match ($queryKind) {
                'ownership' => ['ownership_chain', 'outbound'],
                'dependency' => ['dependency_chain', 'outbound'],
                default => ['relationship_neighborhood', 'any'],
            };
        }

        if (preg_match('/\b(before|after|between|timeline|history)\b/i', $normalizedQuery) === 1) {
            return ['timeline_neighborhood', 'any'];
        }

        return [null, null];
    }

    protected function requiresPathExplanation(string $normalizedQuery): bool
    {
        return preg_match('/\b(why|how|path|chain|explain|through)\b/i', $normalizedQuery) === 1;
    }

    protected function explicitTimeFilter(string $normalizedQuery): ?string
    {
        return match (true) {
            preg_match('/\btoday\b/i', $normalizedQuery) === 1 => 'today',
            preg_match('/\byesterday\b/i', $normalizedQuery) === 1 => 'yesterday',
            preg_match('/\bthis week\b/i', $normalizedQuery) === 1 => 'this_week',
            preg_match('/\blast week\b/i', $normalizedQuery) === 1 => 'last_week',
            preg_match('/\bthis month\b/i', $normalizedQuery) === 1 => 'this_month',
            default => null,
        };
    }
}
