<?php

namespace LaravelAIEngine\Services\Graph;

use Carbon\CarbonImmutable;

class GraphCypherPlanCompiler
{
    /**
     * @param array<string, mixed> $plan
     * @return array{
     *   statement:string,
     *   parameters:array<string, mixed>,
     *   signature:string,
     *   explanation:string,
     *   filters:array<string, mixed>
     * }
     */
    public function compileTraversal(array $plan, string $query, int $maxHops, string $accessPredicate): array
    {
        $template = (string) ($plan['cypher_template'] ?? 'generic_neighborhood');
        $direction = (string) ($plan['traversal_direction'] ?? 'any');
        $queryKind = (string) ($plan['query_kind'] ?? 'generic');
        $nlPlan = is_array($plan['natural_language_plan'] ?? null) ? $plan['natural_language_plan'] : [];
        $normalizedQuery = strtolower(trim($query));
        $relationshipPattern = match ($direction) {
            'outbound' => "-[*0..{$maxHops}]->",
            'inbound' => "<-[*0..{$maxHops}]-",
            default => "-[*0..{$maxHops}]-",
        };

        $temporalWindow = $this->temporalWindow($normalizedQuery);
        $focusTerms = array_values(array_unique(array_merge(
            $this->focusTerms($query, $plan),
            array_map('strtolower', (array) ($nlPlan['focus_terms'] ?? []))
        )));
        $pathPredicate = $this->pathPredicate($template, $nlPlan);
        $orderBy = $this->orderBy($template, $temporalWindow['sort']);

        $whereClauses = [
            "all(rel IN relationships(path) WHERE type(rel) <> 'HAS_CHUNK' AND type(rel) <> 'SOURCE_APP' AND type(rel) <> 'CAN_ACCESS')",
            '($include_self = true OR length(path) > 0)',
            '($collections_empty = true OR n.model_class IN $collections)',
            $accessPredicate,
            '($updated_after_ts IS NULL OR coalesce(n.updated_at_ts, 0) >= $updated_after_ts)',
            '($updated_before_ts IS NULL OR coalesce(n.updated_at_ts, 9223372036854775807) <= $updated_before_ts)',
            "(size(\$focus_terms) = 0 OR any(term IN \$focus_terms WHERE "
                . "toLower(coalesce(n.title, '')) CONTAINS term "
                . "OR toLower(coalesce(n.rag_summary, '')) CONTAINS term "
                . "OR toLower(coalesce(c.content, '')) CONTAINS term))",
        ];

        if ($pathPredicate !== '') {
            $whereClauses[] = $pathPredicate;
        }

        $statement = <<<CYPHER
UNWIND \$hits AS hit
MATCH path = (start:Entity {entity_key: hit.entity_key}){$relationshipPattern}(n:Entity)
MATCH (n)-[:SOURCE_APP]->(a:App)
OPTIONAL MATCH (n)-[:BELONGS_TO]->(s:Scope)
OPTIONAL MATCH (n)-[:HAS_CHUNK]->(c:Chunk)
WHERE {$this->compileWhere($whereClauses)}
WITH hit,
     path,
     n,
     a,
     s,
     c,
     CASE WHEN length(path) = 0 THEN ['SELF'] ELSE [rel IN relationships(path) | type(rel)] END AS relation_path
ORDER BY c.chunk_index ASC
WITH hit,
     length(path) AS path_length,
     relation_path,
     n,
     a,
     s,
     collect(c{.*}) AS chunks
ORDER BY {$orderBy}
RETURN hit.entity_key AS source_entity_key,
       hit.score AS source_score,
       hit.seed_type AS seed_type,
       path_length,
       relation_path,
       n{.*} AS e,
       a{.*} AS a,
       s{.*} AS s,
       chunks
LIMIT \$limit
CYPHER;

        $filters = [
            'template' => $template,
            'query_kind' => $queryKind,
            'direction' => $direction,
            'max_hops' => $maxHops,
            'temporal_sort' => $temporalWindow['sort'],
            'temporal_label' => $temporalWindow['label'],
            'focus_terms' => $focusTerms,
            'relation_types' => array_values((array) ($plan['relation_types'] ?? [])),
            'preferred_model_types' => array_values((array) ($plan['preferred_model_types'] ?? [])),
            'natural_language_plan' => $nlPlan,
        ];

        return [
            'statement' => $statement,
            'parameters' => [
                'updated_after_ts' => $temporalWindow['after_ts'],
                'updated_before_ts' => $temporalWindow['before_ts'],
                'focus_terms' => $focusTerms,
            ],
            'signature' => sha1(json_encode([
                'template' => $template,
                'query_kind' => $queryKind,
                'direction' => $direction,
                'max_hops' => $maxHops,
                'temporal' => $temporalWindow['label'],
                'focus_terms' => $focusTerms,
                'relation_types' => array_values((array) ($plan['relation_types'] ?? [])),
                'preferred_model_types' => array_values((array) ($plan['preferred_model_types'] ?? [])),
                'nl_focus_terms' => array_values((array) ($nlPlan['focus_terms'] ?? [])),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'explanation' => $this->explanation($template, $direction, $maxHops, $focusTerms, $temporalWindow['label']),
            'filters' => $filters,
        ];
    }

    protected function compileWhere(array $clauses): string
    {
        $clauses = array_values(array_filter(array_map(
            static fn ($clause): string => trim((string) $clause),
            $clauses
        )));

        return implode("\n  AND ", $clauses);
    }

    protected function pathPredicate(string $template, array $nlPlan = []): string
    {
        $predicate = match ($template) {
            'ownership_chain' => "(length(path) = 0 OR any(rel IN relationships(path) WHERE type(rel) IN \$relation_types))"
                . "\n  AND (size(\$preferred_model_types) = 0 OR toLower(n.model_type) IN \$preferred_model_types)",
            'dependency_chain' => '(length(path) = 0 OR any(rel IN relationships(path) WHERE type(rel) IN $relation_types))',
            'timeline_neighborhood' => "(length(path) = 0 OR any(rel IN relationships(path) WHERE type(rel) IN \$relation_types OR type(rel) IN ['IN_PROJECT', 'IN_WORKSPACE', 'BELONGS_TO']))",
            'relationship_neighborhood' => '(length(path) = 0 OR any(rel IN relationships(path) WHERE type(rel) IN $relation_types))',
            default => '',
        };

        if (!empty($nlPlan['requires_path_explanation'])) {
            $predicate = $predicate === ''
                ? 'length(path) > 0'
                : $predicate . "\n  AND length(path) > 0";
        }

        return $predicate;
    }

    protected function orderBy(string $template, string $temporalSort): string
    {
        $timeExpression = match ($temporalSort) {
            'asc' => "coalesce(n.updated_at_ts, 0) ASC",
            'desc' => "coalesce(n.updated_at_ts, 0) DESC",
            default => "coalesce(n.updated_at, '') DESC",
        };

        return match ($template) {
            'timeline_neighborhood' => "{$timeExpression}, path_length ASC, source_score DESC",
            'ownership_chain', 'dependency_chain' => 'path_length ASC, source_score DESC',
            default => "source_score DESC, path_length ASC, {$timeExpression}",
        };
    }

    /**
     * @return array{after_ts:int|null,before_ts:int|null,sort:string,label:string|null}
     */
    protected function temporalWindow(string $normalizedQuery): array
    {
        $now = CarbonImmutable::now();

        if (preg_match('/\byesterday\b/', $normalizedQuery) === 1) {
            $start = $now->subDay()->startOfDay();
            $end = $start->endOfDay();

            return [
                'after_ts' => $start->timestamp,
                'before_ts' => $end->timestamp,
                'sort' => 'desc',
                'label' => 'yesterday',
            ];
        }

        if (preg_match('/\btoday\b/', $normalizedQuery) === 1) {
            return [
                'after_ts' => $now->startOfDay()->timestamp,
                'before_ts' => null,
                'sort' => 'desc',
                'label' => 'today',
            ];
        }

        if (preg_match('/\b(this week|week|weekly)\b/', $normalizedQuery) === 1) {
            return [
                'after_ts' => $now->subDays(7)->timestamp,
                'before_ts' => null,
                'sort' => 'desc',
                'label' => 'last_7_days',
            ];
        }

        if (preg_match('/\b(recent|latest|newest|changed|happened)\b/', $normalizedQuery) === 1) {
            return [
                'after_ts' => $now->subDays(30)->timestamp,
                'before_ts' => null,
                'sort' => 'desc',
                'label' => 'last_30_days',
            ];
        }

        return [
            'after_ts' => null,
            'before_ts' => null,
            'sort' => 'none',
            'label' => null,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<int, string>
     */
    protected function focusTerms(string $query, array $plan): array
    {
        $normalizedQuery = strtolower(trim($query));
        $focusTerms = array_map('strtolower', (array) ($plan['lexical_focus_terms'] ?? []));

        if (preg_match_all('/"([^"]+)"/', $query, $matches) === 1) {
            foreach ((array) ($matches[1] ?? []) as $phrase) {
                $phrase = strtolower(trim((string) $phrase));
                if ($phrase !== '') {
                    $focusTerms[] = $phrase;
                }
            }
        }

        $stopWords = [
            'what', 'who', 'when', 'where', 'why', 'how', 'with', 'from', 'into', 'about', 'show',
            'tell', 'give', 'list', 'find', 'all', 'the', 'and', 'for', 'are', 'was', 'were',
            'that', 'this', 'these', 'those', 'have', 'has', 'had', 'will', 'would', 'could',
            'should', 'around', 'related', 'relationship', 'relationships', 'context', 'owner',
            'ownership', 'dependency', 'dependencies', 'project', 'projects', 'task', 'tasks',
            'mail', 'mails', 'email', 'emails', 'workspace', 'workspaces', 'latest', 'recent',
        ];

        foreach (preg_split('/[^a-z0-9_]+/i', $normalizedQuery) ?: [] as $term) {
            $term = strtolower(trim((string) $term));
            if ($term === '' || strlen($term) < 3 || in_array($term, $stopWords, true)) {
                continue;
            }
            $focusTerms[] = $term;
        }

        $focusTerms = array_values(array_unique($focusTerms));

        return array_slice($focusTerms, 0, 8);
    }

    /**
     * @param array<int, string> $focusTerms
     */
    protected function explanation(string $template, string $direction, int $maxHops, array $focusTerms, ?string $temporalLabel): string
    {
        $parts = [
            'template=' . $template,
            'direction=' . $direction,
            'max_hops=' . $maxHops,
        ];

        if ($temporalLabel !== null && $temporalLabel !== '') {
            $parts[] = 'temporal=' . $temporalLabel;
        }

        if ($focusTerms !== []) {
            $parts[] = 'focus=' . implode(',', $focusTerms);
        }

        return implode('; ', $parts);
    }
}
