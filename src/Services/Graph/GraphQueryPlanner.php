<?php

namespace LaravelAIEngine\Services\Graph;

class GraphQueryPlanner
{
    public function __construct(
        protected ?GraphOntologyService $ontology = null,
        protected ?GraphNaturalLanguagePlanService $naturalLanguagePlan = null,
        protected ?GraphRankingFeedbackService $rankingFeedback = null
    ) {
        if ($this->ontology === null && app()->bound(GraphOntologyService::class)) {
            $this->ontology = app(GraphOntologyService::class);
        }
        if ($this->naturalLanguagePlan === null && app()->bound(GraphNaturalLanguagePlanService::class)) {
            $this->naturalLanguagePlan = app(GraphNaturalLanguagePlanService::class);
        }
        if ($this->rankingFeedback === null && app()->bound(GraphRankingFeedbackService::class)) {
            $this->rankingFeedback = app(GraphRankingFeedbackService::class);
        }
    }

    /**
     * @return array{
     *   strategy:string,
     *   relationship_query:bool,
     *   contextual_follow_up:bool,
     *   query_kind:string,
     *   use_selected_entity_seed:bool,
     *   use_visible_list_seeds:bool,
     *   use_semantic_seeds:bool,
     *   traversal_enabled:bool,
     *   prefer_planner_ranking:bool,
     *   attach_neighbor_context:bool,
     *   max_hops:int,
     *   seed_limit:int,
     *   candidate_limit:int,
     *   cypher_template:string,
     *   traversal_direction:string,
     *   relation_types:array<int, string>,
     *   preferred_model_types:array<int, string>,
     *   lexical_focus_terms:array<int, string>,
     *   vector_weight:float,
     *   lexical_weight:float,
     *   selected_seed_boost:float,
     *   relationship_bonus:float
     * }
     */
    public function plan(string $query, array $collections = [], array $options = [], int $maxResults = 5): array
    {
        $normalized = strtolower(trim($query));
        $nlPlan = $this->naturalLanguagePlan?->interpret($query, $collections, $options, $maxResults) ?? [];
        $hasSelectedEntity = is_array($options['selected_entity'] ?? $options['selected_entity_context'] ?? null)
            && !empty($options['selected_entity'] ?? $options['selected_entity_context'] ?? []);
        $hasVisibleList = is_array($options['last_entity_list'] ?? null) && !empty($options['last_entity_list']);
        $relationshipQuery = $this->isRelationshipQuery($normalized) || $this->isGraphExpansionQuery($normalized);
        $contextualFollowUp = $hasSelectedEntity || $hasVisibleList;
        $queryKind = (string) ($nlPlan['query_kind'] ?? $this->detectQueryKind($normalized));
        $plannerEnabled = (bool) config('ai-engine.graph.planner_enabled', true);

        $strategy = 'semantic_only';
        if ($plannerEnabled && $relationshipQuery && $hasSelectedEntity) {
            $strategy = 'selected_entity_traversal';
        } elseif ($plannerEnabled && $queryKind === 'timeline' && count($collections) > 1) {
            $strategy = 'semantic_graph_planner';
        } elseif ($plannerEnabled && ($relationshipQuery || $contextualFollowUp)) {
            $strategy = 'semantic_graph_planner';
        }

        $maxHops = (int) config('ai-engine.graph.max_traversal_hops', 2);
        if ($strategy === 'semantic_only') {
            $maxHops = min(1, max(1, $maxHops));
        }

        $candidateMultiplier = max(1, (int) config('ai-engine.graph.planner_candidate_multiplier', 2));
        $seedLimit = max($maxResults, (int) config('ai-engine.graph.planner_seed_limit', max(6, $maxResults)));
        $traversalEnabled = $strategy !== 'semantic_only';
        $lexicalWeight = $relationshipQuery
            ? (float) config('ai-engine.graph.planner_relationship_lexical_weight', 0.5)
            : (float) config('ai-engine.graph.planner_lexical_weight', 0.4);
        $lexicalWeight = max(0.05, min(0.95, $lexicalWeight));
        $vectorWeight = round(1 - $lexicalWeight, 4);

        [$relationTypes, $preferredModelTypes, $lexicalFocusTerms] = $this->queryKindHints($queryKind, $normalized, $collections);
        [$cypherTemplate, $traversalDirection] = $this->cypherTemplateHints($queryKind, $normalized, $hasSelectedEntity, $relationshipQuery);

        $relationTypes = array_values(array_unique(array_merge(
            $relationTypes,
            array_values((array) ($nlPlan['relation_types'] ?? []))
        )));
        $preferredModelTypes = array_values(array_unique(array_merge(
            $preferredModelTypes,
            array_values((array) ($nlPlan['preferred_model_types'] ?? []))
        )));
        $lexicalFocusTerms = array_values(array_unique(array_merge(
            $lexicalFocusTerms,
            array_values((array) ($nlPlan['focus_terms'] ?? []))
        )));
        $cypherTemplate = (string) ($nlPlan['cypher_template'] ?? $cypherTemplate);
        $traversalDirection = (string) ($nlPlan['traversal_direction'] ?? $traversalDirection);
        $candidateLimit = max(
            max($maxResults, $maxResults * $candidateMultiplier),
            (int) ($nlPlan['limit_hint'] ?? 0)
        );

        $plan = [
            'strategy' => $strategy,
            'relationship_query' => $relationshipQuery,
            'contextual_follow_up' => $contextualFollowUp,
            'query_kind' => $queryKind,
            'use_selected_entity_seed' => $hasSelectedEntity,
            'use_visible_list_seeds' => $hasVisibleList,
            'use_semantic_seeds' => $strategy !== 'selected_entity_traversal' || !$hasSelectedEntity || $this->hasSemanticSearchCue($normalized),
            'traversal_enabled' => $traversalEnabled,
            'prefer_planner_ranking' => $traversalEnabled,
            'attach_neighbor_context' => (bool) config('ai-engine.graph.attach_neighbor_context', true),
            'max_hops' => max(1, $maxHops),
            'seed_limit' => $seedLimit,
            'candidate_limit' => $candidateLimit,
            'cypher_template' => $cypherTemplate,
            'traversal_direction' => $traversalDirection,
            'relation_types' => $relationTypes,
            'preferred_model_types' => $preferredModelTypes,
            'lexical_focus_terms' => $lexicalFocusTerms,
            'vector_weight' => $vectorWeight,
            'lexical_weight' => $lexicalWeight,
            'selected_seed_boost' => (float) config('ai-engine.graph.planner_selected_seed_boost', 0.05),
            'relationship_bonus' => (float) config('ai-engine.graph.planner_relationship_bonus', 0.05),
            'natural_language_plan' => $nlPlan,
        ];

        return $this->rankingFeedback?->adaptPlan($queryKind, $plan) ?? $plan;
    }

    protected function isRelationshipQuery(string $normalizedQuery): bool
    {
        if ($normalizedQuery === '') {
            return false;
        }

        return preg_match('/\b(related to|connected to|who is involved|show context|what changed around|what happened around|linked to|depends on|impact of|connected with|who owns|what is it related to|who is it related to)\b/i', $normalizedQuery) === 1;
    }

    protected function isGraphExpansionQuery(string $normalizedQuery): bool
    {
        if ($normalizedQuery === '') {
            return false;
        }

        return preg_match('/\b(across|around|with|alongside|owner|ownership|dependency|dependencies|context|relationship|relationships|neighbour|neighbor|touching|affected by)\b/i', $normalizedQuery) === 1;
    }

    protected function hasSemanticSearchCue(string $normalizedQuery): bool
    {
        if ($normalizedQuery === '') {
            return false;
        }

        return preg_match('/\b(what|who|why|when|where|show|tell|summar|latest|status|changed|happened|about)\b/i', $normalizedQuery) === 1;
    }

    protected function detectQueryKind(string $normalizedQuery): string
    {
        if ($normalizedQuery === '') {
            return 'generic';
        }

        if (preg_match('/\b(who owns|owner|ownership|owned by|assignee|assigned to)\b/i', $normalizedQuery) === 1) {
            return 'ownership';
        }

        if (preg_match('/\b(depends on|dependency|dependencies|blocked by|blocker|linked work|upstream|downstream)\b/i', $normalizedQuery) === 1) {
            return 'dependency';
        }

        if (preg_match('/\b(reply|replied|thread|conversation|mail|email|message|mention|mentioned|attachment|attachments|sent to|sent by)\b/i', $normalizedQuery) === 1) {
            return 'communication';
        }

        if (preg_match('/\b(what changed|what happened|history|timeline|status|latest|friday|monday|tuesday|wednesday|thursday|saturday|sunday|today|yesterday|recent)\b/i', $normalizedQuery) === 1) {
            return 'timeline';
        }

        if (preg_match('/\b(related to|connected to|who is involved|show context|context|relationship|relationships)\b/i', $normalizedQuery) === 1) {
            return 'relationship';
        }

        return 'generic';
    }

    /**
     * @return array{0:array<int,string>,1:array<int,string>,2:array<int,string>}
     */
    protected function queryKindHints(string $queryKind, string $normalizedQuery, array $collections = []): array
    {
        [$relationTypes, $preferredModelTypes, $lexicalFocusTerms] = match ($queryKind) {
            'ownership' => [
                ['OWNED_BY', 'CREATED_BY', 'ASSIGNED_TO', 'BELONGS_TO'],
                ['user'],
                $this->extractFocusTerms($normalizedQuery, ['owner', 'ownership', 'owns', 'owned']),
            ],
            'dependency' => [
                ['DEPENDS_ON', 'BLOCKED_BY', 'HAS_RELATED', 'RELATED_TO', 'HAS_TASK', 'HAS_PROJECT', 'HAS_MAIL', 'HAS_TICKET', 'HAS_ISSUE', 'BELONGS_TO'],
                ['task', 'project', 'mail', 'email'],
                $this->extractFocusTerms($normalizedQuery, ['dependency', 'dependencies', 'blocked', 'blocker', 'depends']),
            ],
            'communication' => [
                ['SENT_BY', 'SENT_TO', 'REPLIED_TO', 'MENTIONS', 'HAS_ATTACHMENT', 'IN_THREAD', 'IN_CHANNEL', 'HAS_MESSAGE', 'HAS_COMMENT', 'HAS_MAIL', 'FOR_CUSTOMER', 'FOR_VENDOR'],
                ['mail', 'email', 'message', 'comment', 'user', 'contact'],
                $this->extractFocusTerms($normalizedQuery, ['reply', 'thread', 'conversation', 'email', 'mail', 'message', 'mention', 'attachment']),
            ],
            'timeline' => [
                ['BELONGS_TO', 'IN_PROJECT', 'IN_WORKSPACE', 'IN_MILESTONE', 'IN_SPRINT', 'HAS_RELATED', 'RELATED_TO', 'HAS_TASK', 'HAS_MAIL', 'HAS_PROJECT', 'OWNED_BY', 'CREATED_BY', 'ASSIGNED_TO', 'MANAGED_BY', 'REPORTED_BY', 'HAS_USER', 'HAS_MESSAGE', 'HAS_COMMENT'],
                ['mail', 'email', 'task', 'project', 'workspace', 'user', 'message', 'comment'],
                $this->extractFocusTerms($normalizedQuery, ['today', 'yesterday', 'recent', 'latest', 'status', 'changed', 'friday', 'monday', 'tuesday', 'wednesday', 'thursday', 'saturday', 'sunday']),
            ],
            'relationship' => [
                ['BELONGS_TO', 'IN_PROJECT', 'IN_WORKSPACE', 'IN_THREAD', 'IN_CHANNEL', 'HAS_RELATED', 'RELATED_TO', 'HAS_TASK', 'HAS_MAIL', 'HAS_PROJECT', 'OWNED_BY', 'MANAGED_BY', 'REPORTED_BY', 'HAS_MEMBER', 'HAS_PARTICIPANT', 'HAS_DOCUMENT', 'HAS_ATTACHMENT'],
                [],
                $this->extractFocusTerms($normalizedQuery, ['related', 'connected', 'context', 'relationship']),
            ],
            default => [
                ['BELONGS_TO', 'HAS_RELATED', 'RELATED_TO', 'OWNED_BY', 'MANAGED_BY', 'REPORTED_BY', 'DEPENDS_ON', 'BLOCKED_BY', 'SENT_BY', 'SENT_TO', 'REPLIED_TO', 'MENTIONS'],
                [],
                [],
            ],
        };

        $ontologyModelTypes = $this->ontology?->preferredModelTypesForCollections($collections) ?? [];
        $ontologyRelationTypes = $this->ontology?->relationTypesForCollections($collections) ?? [];

        return [
            array_values(array_unique(array_merge($relationTypes, $ontologyRelationTypes))),
            array_values(array_unique(array_merge($preferredModelTypes, $ontologyModelTypes))),
            $lexicalFocusTerms,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function cypherTemplateHints(string $queryKind, string $normalizedQuery, bool $hasSelectedEntity, bool $relationshipQuery): array
    {
        return match ($queryKind) {
            'ownership' => ['ownership_chain', 'outbound'],
            'dependency' => ['dependency_chain', 'outbound'],
            'communication' => ['relationship_neighborhood', 'any'],
            'timeline' => ['timeline_neighborhood', $hasSelectedEntity ? 'outbound' : 'any'],
            'relationship' => ['relationship_neighborhood', 'any'],
            default => [$relationshipQuery ? 'relationship_neighborhood' : 'generic_neighborhood', $hasSelectedEntity ? 'outbound' : 'any'],
        };
    }

    /**
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    protected function extractFocusTerms(string $normalizedQuery, array $candidates): array
    {
        $matches = [];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && str_contains($normalizedQuery, $candidate)) {
                $matches[] = $candidate;
            }
        }

        return array_values(array_unique($matches));
    }
}
