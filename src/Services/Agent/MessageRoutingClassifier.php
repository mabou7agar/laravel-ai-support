<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

class MessageRoutingClassifier
{
    /**
     * @param array<string, mixed> $signals
     * @return array{route:string,mode:string,reason:string,source:string}
     */
    public function classify(string $message, array $signals = []): array
    {
        $normalized = $this->normalize($message);

        if ($normalized === '') {
            return $this->decision('conversational', 'conversational', 'empty message treated as chat');
        }

        if ($this->isConversational($message)) {
            return $this->decision('conversational', 'conversational', 'greeting or general chat');
        }

        if ($this->isActionFlow($normalized)) {
            return $this->decision('ask_ai', 'action_flow', 'explicit mutation or action flow intent');
        }

        if ($this->isExactLookup($normalized)) {
            return $this->decision('ask_ai', 'exact_lookup', 'exact identifier lookup should use structured tools when available');
        }

        if ($this->isContextualFollowUp($normalized, $signals)) {
            return $this->decision('search_rag', 'contextual_follow_up', 'follow-up refers to selected or visible context');
        }

        if ($this->isStructuredQuery($normalized)) {
            return $this->decision('ask_ai', 'structured_query', 'explicit list/count/filter query should use structured tools when available');
        }

        if ($this->isSemanticRetrieval($message, $normalized, $signals)) {
            return $this->decision('search_rag', 'semantic_retrieval', 'semantic data lookup detected');
        }

        return $this->decision('ask_ai', 'ambiguous', 'requires higher-level routing');
    }

    public function prefersVectorSearch(string $message, array $signals = []): bool
    {
        $classification = $this->classify($message, $signals);

        return $classification['mode'] === 'semantic_retrieval';
    }

    public function prefersStructuredQuery(string $message, array $signals = []): bool
    {
        $classification = $this->classify($message, $signals);

        return $classification['mode'] === 'structured_query';
    }

    public function prefersContextualFollowUp(string $message, array $signals = []): bool
    {
        $classification = $this->classify($message, $signals);

        return $classification['mode'] === 'contextual_follow_up';
    }

    protected function isConversational(string $message): bool
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return true;
        }

        if (preg_match('/^(hi|hello|hey|yo|good morning|good afternoon|good evening)\b[!. ]*$/i', $normalized)) {
            return true;
        }

        if (preg_match('/^(thanks|thank you|thx|ok thanks|cool thanks|great thanks|got it|okay got it)\b[!. ]*$/i', $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $signals
     */
    protected function isContextualFollowUp(string $normalized, array $signals): bool
    {
        $hasSelected = is_array($signals['selected_entity'] ?? null) && !empty($signals['selected_entity']);
        $hasVisibleList = is_array($signals['last_entity_list'] ?? null) && !empty($signals['last_entity_list']);

        if (!$hasSelected && !$hasVisibleList) {
            return false;
        }

        if (preg_match('/^(it|that|this|them|those|these)\b/i', $normalized)) {
            return true;
        }

        if (preg_match('/\b(first|second|third|fourth|fifth|last|previous|above|that one|this one)\b/i', $normalized)) {
            return true;
        }

        if (preg_match('/^(tell me more|more info|more information|details|show details|what about|who is it related to|what is it related to|summarize it)\b/i', $normalized)) {
            return true;
        }

        if (preg_match('/^#?\d+$/', trim($normalized))) {
            return true;
        }

        return false;
    }

    protected function isActionFlow(string $normalized): bool
    {
        if (preg_match('/^(please\s+)?(prepare|draft|create|add|new|make|generate|update|edit|change|modify|delete|remove|cancel|approve|reject|submit|send|run|execute|trigger)\b/i', $normalized) === 1) {
            return true;
        }

        $entities = $this->configuredTerms('action_entities');
        if ($entities === []) {
            return false;
        }

        return preg_match('/\b(prepare|draft|create|add|make|generate)\b.+\b(' . $this->termPattern($entities) . ')\b/iu', $normalized) === 1;
    }

    protected function isExactLookup(string $normalized): bool
    {
        $entities = $this->configuredTerms('action_entities');
        if ($entities !== [] && preg_match('/\b(' . $this->termPattern($entities) . ')\s+(number|no|id|code|sku)\b/iu', $normalized)) {
            return true;
        }

        if (preg_match('/\b[A-Z]{2,}(?:-[A-Z0-9]+){2,}\b/', strtoupper($normalized))) {
            return true;
        }

        return false;
    }

    protected function isStructuredQuery(string $normalized): bool
    {
        if (preg_match('/\b(count|how many|total|sum|average|avg|min|max|group by)\b/i', $normalized)) {
            return true;
        }

        if (preg_match('/^(list|show all|find all|get all|display all|show me all)\b/i', $normalized)) {
            return true;
        }

        $fieldTerms = $this->configuredTerms('structured_field_terms');
        if ($fieldTerms !== [] && preg_match('/\b(' . $this->termPattern($fieldTerms) . ')\s*(=|:)\s*[\w-]+/iu', $normalized)) {
            return true;
        }

        $statusTerms = $this->configuredTerms('structured_status_terms');
        $collectionTerms = $this->configuredTerms('structured_collection_terms');
        if ($statusTerms !== [] && $collectionTerms !== []
            && preg_match('/\b(' . $this->termPattern($statusTerms) . ')\b.*\b(' . $this->termPattern($collectionTerms) . ')\b/iu', $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function configuredTerms(string $key): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $term): string => mb_strtolower(trim((string) $term)), (array) config("ai-agent.routing_classifier.{$key}", [])),
            static fn (string $term): bool => $term !== ''
        ));
    }

    /**
     * @param list<string> $terms
     */
    protected function termPattern(array $terms): string
    {
        return implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms));
    }

    /**
     * @param array<string, mixed> $signals
     */
    protected function isSemanticRetrieval(string $message, string $normalized, array $signals): bool
    {
        $strongPatterns = [
            '/\bwhat changed\b/i',
            '/\bwhat happened\b/i',
            '/\brelated to\b/i',
            '/\bconnected to\b/i',
            '/\bwho is involved\b/i',
            '/\btell me about\b/i',
            '/\bdetails? about\b/i',
            '/\bsummar(?:ize|y)\b/i',
            '/\bshow context\b/i',
            '/\bwhat do we know about\b/i',
            '/\bstatus of\b/i',
            '/\bhistory of\b/i',
        ];

        foreach ($strongPatterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        $hasContextSignals = !empty($signals['rag_collections'] ?? [])
            || !empty($signals['selected_entity'] ?? [])
            || !empty($signals['last_entity_list'] ?? []);

        $hasCapitalizedSubject = preg_match('/\b[A-Z][a-z0-9_-]{2,}\b/', $message) === 1;
        $hasTimeSignal = preg_match('/\b(today|yesterday|last week|recent|friday|monday|tuesday|wednesday|thursday|saturday|sunday)\b/i', $normalized) === 1;
        $hasQuestionWord = preg_match('/\b(what|who|why|when|where|which)\b/i', $normalized) === 1;
        $hasDomainSignal = $this->containsDomainTerm($normalized, $signals);

        return $hasQuestionWord && ($hasContextSignals || $hasCapitalizedSubject || $hasTimeSignal || $hasDomainSignal);
    }

    /**
     * @param array<string, mixed> $signals
     */
    protected function containsDomainTerm(string $normalized, array $signals): bool
    {
        foreach ($this->domainTerms($signals) as $term) {
            if ($term !== '' && preg_match('/\b' . preg_quote($term, '/') . '\b/i', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $signals
     * @return array<int, string>
     */
    protected function domainTerms(array $signals): array
    {
        $terms = [];

        foreach ((array) ($signals['rag_collections'] ?? []) as $collection) {
            if (!is_string($collection) || trim($collection) === '') {
                continue;
            }

            $base = strtolower(class_basename($collection));
            $terms[] = $base;
            if (!str_ends_with($base, 's')) {
                $terms[] = $base . 's';
            }
        }

        $selected = (array) ($signals['selected_entity'] ?? []);
        $selectedType = strtolower(trim((string) ($selected['entity_type'] ?? '')));
        if ($selectedType !== '') {
            $terms[] = $selectedType;
        }

        $lastList = (array) ($signals['last_entity_list'] ?? []);
        $listType = strtolower(trim((string) ($lastList['entity_type'] ?? '')));
        if ($listType !== '') {
            $terms[] = $listType;
        }

        return array_values(array_unique(array_filter($terms)));
    }

    protected function normalize(string $message): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $message) ?? ''));
    }

    /**
     * @return array{route:string,mode:string,reason:string,source:string}
     */
    protected function decision(string $route, string $mode, string $reason): array
    {
        return [
            'route' => $route,
            'mode' => $mode,
            'reason' => $reason,
            'source' => 'heuristic',
        ];
    }
}
