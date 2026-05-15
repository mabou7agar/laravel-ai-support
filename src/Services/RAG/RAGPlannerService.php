<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class RAGPlannerService
{
    protected ?string $activeContextLocale = null;

    public function __construct(
        protected AIEngineService $ai,
        protected ?RAGDecisionPolicy $policy = null,
        protected ?RAGDecisionPromptService $promptService = null,
        protected ?RAGDecisionFeedbackService $feedbackService = null,
        protected ?LocaleResourceService $locale = null,
        protected ?MessageRoutingClassifier $messageClassifier = null
    )
    {
        $this->policy = $policy ?? new RAGDecisionPolicy();
        $this->feedbackService = $feedbackService ?? new RAGDecisionFeedbackService($this->policy);
        $this->promptService = $promptService ?? new RAGDecisionPromptService(
            $this->policy,
            $this->feedbackService
        );
        $this->locale = $locale ?? (
            app()->bound(LocaleResourceService::class)
                ? app(LocaleResourceService::class)
                : new LocaleResourceService()
        );
        $this->messageClassifier = $this->messageClassifier ?? (
            app()->bound(MessageRoutingClassifier::class)
                ? app(MessageRoutingClassifier::class)
                : new MessageRoutingClassifier()
        );
    }

    public function decide(string $message, array $context, ?string $model = null): array
    {
        $this->activeContextLocale = $this->normalizeLocaleTag($context['locale'] ?? null);
        $model = $model ?: $this->policy->decisionModel();
        $promptPayload = $this->buildPromptWithMetadata($message, $context);
        $prompt = $promptPayload['prompt'];
        $runtime = $this->buildRuntimeContext($context, [
            'policy' => $promptPayload['policy'] ?? null,
        ]);
        $startedAt = microtime(true);

        try {
            Log::channel('ai-engine')->info('RAG Agent Prompt', ['prompt' => $prompt]);

            $response = $this->generateDecisionResponse($prompt, $model);

            $content = trim($response->getContent());
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $usage = (array) ($response->getUsage() ?? []);
            $runtime = array_merge($runtime, [
                'latency_ms' => $latencyMs,
                'tokens_used' => $response->getTokensUsed(),
                'token_cost' => $response->getCreditsUsed(),
                'metadata' => array_merge(
                    (array) ($runtime['metadata'] ?? []),
                    ['usage' => $usage]
                ),
            ]);

            Log::channel('ai-engine')->info('RAG Agent Response', ['content' => $content]);

            return $this->parseDecision($content, $message, $context, $runtime);
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->error('AI decision failed', ['error' => $e->getMessage()]);

            $fallback = $this->fallbackDecisionForMessage(
                $message,
                $context,
                $runtime,
                'AI decision failed: ' . $e->getMessage()
            );
            $this->feedbackService->recordFallbackDecision($fallback, $message, array_merge($runtime, [
                'metadata' => array_merge(
                    (array) ($runtime['metadata'] ?? []),
                    ['decision_exception' => $e->getMessage()]
                ),
            ]));

            return $fallback;
        }
    }

    public function feedbackService(): RAGDecisionFeedbackService
    {
        return $this->feedbackService;
    }

    public function recordExecutionOutcome(array $decision, array $result, array $runtime = []): void
    {
        $this->feedbackService->recordExecutionOutcome($decision, $result, $runtime);
    }

    protected function generateDecisionResponse(string $prompt, string $model): \LaravelAIEngine\DTOs\AIResponse
    {
        $entity = EntityEnum::from($model);

        return $this->ai->generateText(new AIRequest(
            prompt: $prompt,
            engine: $entity->engine(),
            model: $entity,
            temperature: 0.1,
            maxTokens: 1000
        ));
    }

    protected function buildPrompt(string $message, array $context): string
    {
        return $this->promptService->build($message, $context);
    }

    protected function buildPromptWithMetadata(string $message, array $context): array
    {
        return $this->promptService->buildWithMetadata($message, $context);
    }

    protected function parseDecision(string $content, string $message, array $context, array $runtime = []): array
    {
        $decision = $this->tryParseDecisionJson($content);
        if ($decision) {
            $decision = $this->normalizeDecisionParameters($decision, $context, $message);
            $decision = $this->annotateDecision($decision, $runtime, 'ai');
            $this->feedbackService->recordParsedDecision($decision, $message, $context, $runtime);
            return $decision;
        }

        Log::channel('ai-engine')->info('AI DECISION PARSING FAILED', ['content' => $content]);
        $this->feedbackService->recordParseFailure($message, $content, $runtime);

        $fallback = $this->fallbackDecisionForMessage($message, $context, $runtime);
        $this->feedbackService->recordFallbackDecision($fallback, $message, $runtime);

        return $fallback;
    }

    public function fallbackDecisionForMessage(
        string $message,
        array $context,
        array $runtime = [],
        ?string $baseReason = null
    ): array {
        $messageLower = strtolower($message);
        $detectedModel = $this->inferModelName($context, $messageLower);
        $defaultLimit = $this->policy->itemsPerPage();
        $selectedEntity = $context['selected_entity'] ?? [];
        $selectedEntityId = $selectedEntity['entity_id'] ?? null;
        $selectedEntityModel = $selectedEntity['entity_type'] ?? null;
        $baseReason = trim((string) $baseReason);

        if ($this->hasAggregateIntent($messageLower, '')) {
            $operation = $this->inferAggregateOperation($messageLower, '');
            $groupBy = $this->inferAggregateGroupBy($context, $messageLower);
            if ($operation === 'count') {
                $fallback = [
                    'tool' => $groupBy ? 'db_aggregate' : 'db_count',
                    'reasoning' => $this->fallbackReason($baseReason, 'Aggregate-like request inferred as count from message'),
                    'parameters' => $groupBy
                        ? [
                            'model' => $detectedModel ?? 'unknown',
                            'aggregate' => [
                                'operation' => 'count',
                                'field' => $this->inferAggregateField($context, $detectedModel, $messageLower),
                                'group_by' => $groupBy,
                            ],
                        ]
                        : ['model' => $detectedModel ?? 'unknown'],
                ];

                return $this->annotateDecision(
                    $this->injectMessageDerivedFilters($fallback, $context, $message),
                    $runtime,
                    'fallback'
                );
            }

            $fallback = [
                'tool' => 'db_aggregate',
                'reasoning' => $this->fallbackReason($baseReason, 'Aggregate request inferred from message'),
                'parameters' => [
                    'model' => $detectedModel ?? 'unknown',
                    'aggregate' => [
                        'operation' => $operation,
                        'field' => $this->inferAggregateField($context, $detectedModel, $messageLower),
                        'group_by' => $groupBy,
                    ],
                ],
            ];

            return $this->annotateDecision(
                $this->injectMessageDerivedFilters($fallback, $context, $message),
                $runtime,
                'fallback'
            );
        }

        if ($this->hasNextPageIntent($messageLower)) {
            $fallback = [
                'tool' => 'db_query_next',
                'reasoning' => $this->fallbackReason($baseReason, 'Pagination follow-up inferred from message'),
                'parameters' => [],
            ];

            return $this->annotateDecision($fallback, $runtime, 'fallback');
        }

        if ($selectedEntityId && !$this->hasListIntent($messageLower)) {
            $fallback = [
                'tool' => 'db_query',
                'reasoning' => $this->fallbackReason($baseReason, 'Follow-up inferred for selected entity context'),
                'parameters' => [
                    'model' => $selectedEntityModel ?? $detectedModel ?? 'unknown',
                    'filters' => ['id' => $selectedEntityId],
                    'limit' => 1,
                ],
            ];

            return $this->annotateDecision($fallback, $runtime, 'fallback');
        }

        if (!empty($context['last_entity_list']) && !$this->hasListIntent($messageLower)) {
            $fallback = [
                'tool' => 'vector_search',
                'reasoning' => $this->fallbackReason($baseReason, 'Follow-up inferred from previous visible list context'),
                'parameters' => [
                    'model' => $context['last_entity_list']['entity_type'] ?? $detectedModel ?? 'unknown',
                    'query' => $message,
                    'limit' => $defaultLimit,
                ],
            ];

            return $this->annotateDecision($fallback, $runtime, 'fallback');
        }

        $classification = $this->messageClassifier->classify($message, [
            'selected_entity' => $context['selected_entity'] ?? null,
            'last_entity_list' => $context['last_entity_list'] ?? null,
            'rag_collections' => array_values(array_filter(array_map(
                static fn (array $model): ?string => isset($model['class']) && is_string($model['class']) ? $model['class'] : null,
                array_values(array_filter((array) ($context['models'] ?? []), 'is_array'))
            ))),
        ]);

        if ($classification['mode'] === 'semantic_retrieval') {
            $fallback = [
                'tool' => 'vector_search',
                'reasoning' => $this->fallbackReason($baseReason, 'Semantic question inferred from message'),
                'parameters' => [
                    'model' => $detectedModel ?? 'unknown',
                    'query' => $message,
                    'limit' => $defaultLimit,
                ],
            ];

            return $this->annotateDecision($fallback, $runtime, 'fallback');
        }

        if ($classification['mode'] === 'structured_query') {
            $fallback = [
                'tool' => 'db_query',
                'reasoning' => $this->fallbackReason($baseReason, 'Structured data request inferred from message'),
                'parameters' => [
                    'model' => $detectedModel ?? 'unknown',
                    'limit' => $defaultLimit,
                ],
            ];

            return $this->annotateDecision($fallback, $runtime, 'fallback');
        }

        if ($this->hasListIntent($messageLower)) {
            $fallback = [
                'tool' => 'db_query',
                'reasoning' => $this->fallbackReason($baseReason, 'List intent inferred from message'),
                'parameters' => [
                    'model' => $detectedModel ?? 'unknown',
                    'limit' => $defaultLimit,
                ],
            ];

            return $this->annotateDecision($fallback, $runtime, 'fallback');
        }

        $fallback = [
            'tool' => 'db_query',
            'reasoning' => $this->fallbackReason($baseReason, 'Could not parse AI decision; defaulting to direct query'),
            'parameters' => [
                'model' => $detectedModel ?? 'unknown',
                'limit' => $defaultLimit,
            ],
        ];

        return $this->annotateDecision($fallback, $runtime, 'fallback');
    }

    protected function buildRuntimeContext(array $context, array $extra = []): array
    {
        $runtime = [
            'session_id' => $this->nullableString($context['session_id'] ?? null),
            'conversation_id' => $this->nullableString($context['conversation_id'] ?? null),
            'user_id' => $this->nullableString($context['user_id'] ?? null),
            'tenant_id' => $this->nullableString($context['tenant_id'] ?? null),
            'app_id' => $this->nullableString($context['app_id'] ?? null),
            'metadata' => [],
        ];

        return array_merge($runtime, $extra);
    }

    protected function annotateDecision(array $decision, array $runtime, string $source): array
    {
        $decision['decision_source'] = $source;

        if (!empty($runtime['policy']) && is_array($runtime['policy'])) {
            $decision['policy'] = $runtime['policy'];
        }

        return $decision;
    }

    protected function fallbackReason(string $baseReason, string $suffix): string
    {
        if ($baseReason === '') {
            return $suffix;
        }

        return rtrim($baseReason, '. ') . '; ' . $suffix;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function normalizeDecisionParameters(array $decision, array $context, string $message): array
    {
        $parameters = is_array($decision['parameters'] ?? null) ? $decision['parameters'] : [];
        $tool = strtolower((string) ($decision['tool'] ?? ''));
        $requiresModel = in_array($tool, ['db_query', 'db_count', 'db_aggregate', 'model_tool'], true);
        $explicitModel = $this->detectExplicitModelFromMessage($context, $message);

        $hasModel = !empty($parameters['model']) && is_string($parameters['model']);
        if (!$hasModel) {
            $candidate = $parameters['table'] ?? $parameters['collection'] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                $resolved = $this->resolveModelNameFromCandidate($candidate, (array) ($context['models'] ?? []));
                if ($resolved) {
                    $parameters['model'] = $resolved;
                    unset($parameters['table'], $parameters['collection']);
                    $hasModel = true;
                }
            }
        }

        if ($requiresModel && $explicitModel !== null) {
            $currentModel = $this->canonicalEntityType((string) ($parameters['model'] ?? ''));
            $explicitCanonical = $this->canonicalEntityType($explicitModel);

            if ($currentModel === '' || $currentModel !== $explicitCanonical) {
                $parameters['model'] = $explicitModel;
                unset($parameters['table'], $parameters['collection']);
                $hasModel = true;
            }
        }

        if ($requiresModel && !$hasModel) {
            $inferred = $this->inferModelName($context, strtolower($message));
            if (is_string($inferred) && trim($inferred) !== '') {
                $parameters['model'] = $inferred;
                $hasModel = true;
            }
        }

        if ($requiresModel && !$hasModel) {
            return $decision;
        }

        $decision['parameters'] = $parameters;
        $decision = $this->normalizeAggregateDecision($decision, $context, $message);
        $decision = $this->injectMessageDerivedFilters($decision, $context, $message);
        $decision = $this->injectContextualRelationFilters($decision, $context, $message);
        $decision = $this->enforceFollowUpContextUsage($decision, $context, $message);

        return $decision;
    }

    protected function injectMessageDerivedFilters(array $decision, array $context, string $message): array
    {
        $tool = strtolower((string) ($decision['tool'] ?? ''));
        if (!in_array($tool, ['db_query', 'db_count', 'db_aggregate'], true)) {
            return $decision;
        }

        $parameters = is_array($decision['parameters'] ?? null) ? $decision['parameters'] : [];
        $modelName = strtolower(trim((string) ($parameters['model'] ?? '')));
        if ($modelName === '') {
            return $decision;
        }

        $modelContext = $this->findModelContext($context, $modelName);
        $schemaFields = array_map('strtolower', array_keys((array) ($modelContext['schema'] ?? [])));
        $filters = is_array($parameters['filters'] ?? null) ? $parameters['filters'] : [];
        $messageLower = strtolower($message);

        if (!array_key_exists('status', $filters) && in_array('status', $schemaFields, true)) {
            $statuses = $this->inferStatusFilters($messageLower);
            if ($statuses !== []) {
                $filters['status'] = count($statuses) === 1 ? $statuses[0] : $statuses;
            }
        }

        $amountField = $filters['amount_field']
            ?? ($parameters['aggregate']['field'] ?? null)
            ?? $this->inferAggregateField($context, $parameters['model'] ?? null, $messageLower);
        if (is_string($amountField) && in_array(strtolower($amountField), $schemaFields, true)) {
            $amountFilters = $this->inferAmountFilters($messageLower);
            if ($amountFilters !== []) {
                $filters = array_merge(['amount_field' => $amountField], $filters, $amountFilters);
            }
        }

        if ($filters !== []) {
            $parameters['filters'] = $filters;
            $decision['parameters'] = $parameters;
        }

        return $decision;
    }

    protected function inferStatusFilters(string $messageLower): array
    {
        $patterns = [
            'awaiting_approval' => '/\b(awaiting approval|awaiting_approval|needs approval|pending approval)\b/',
            'legal_hold' => '/\b(legal hold|legal_hold)\b/',
            'overdue' => '/\boverdue\b/',
            'paid' => '/\bpaid\b/',
            'sent' => '/\bsent\b/',
            'draft' => '/\bdraft\b/',
            'void' => '/\b(void|voided)\b/',
            'open' => '/\bopen\b/',
            'closed' => '/\bclosed\b/',
            'active' => '/\bactive\b/',
            'pending' => '/\bpending\b/',
            'completed' => '/\bcompleted\b/',
        ];

        $matches = [];
        foreach ($patterns as $status => $pattern) {
            if (preg_match($pattern, $messageLower) === 1) {
                $matches[] = $status;
            }
        }

        return array_values(array_unique($matches));
    }

    protected function inferAmountFilters(string $messageLower): array
    {
        $filters = [];
        $number = '([0-9][0-9,]*(?:\.[0-9]+)?)';

        if (preg_match('/\b(?:under|below|less than|lower than|at most|<=?)\s*\$?' . $number . '\b/i', $messageLower, $matches)) {
            $filters['amount_max'] = (float) str_replace(',', '', $matches[1]);
        }

        if (preg_match('/\b(?:over|above|greater than|more than|at least|>=?)\s*\$?' . $number . '\b/i', $messageLower, $matches)) {
            $filters['amount_min'] = (float) str_replace(',', '', $matches[1]);
        }

        if (preg_match('/\bbetween\s*\$?' . $number . '\s+(?:and|to)\s+\$?' . $number . '\b/i', $messageLower, $matches)) {
            $filters['amount_min'] = (float) str_replace(',', '', $matches[1]);
            $filters['amount_max'] = (float) str_replace(',', '', $matches[2]);
        }

        return $filters;
    }

    protected function normalizeAggregateDecision(array $decision, array $context, string $message): array
    {
        $messageLower = strtolower($message);
        $tool = strtolower((string) ($decision['tool'] ?? ''));
        if ($this->hasAggregateIntent($messageLower, '') && $tool === 'db_query') {
            $operation = $this->inferAggregateOperation($messageLower, '');
            $groupBy = $this->inferAggregateGroupBy($context, $messageLower);
            $decision['tool'] = ($operation === 'count' && !$groupBy) ? 'db_count' : 'db_aggregate';
            $tool = $decision['tool'];
        }

        if ($tool !== 'db_aggregate') {
            return $decision;
        }

        $parameters = is_array($decision['parameters'] ?? null) ? $decision['parameters'] : [];
        $aggregate = is_array($parameters['aggregate'] ?? null) ? $parameters['aggregate'] : [];
        $modelName = isset($parameters['model']) ? (string) $parameters['model'] : null;

        $aggregate['operation'] = $this->inferAggregateOperation(
            $messageLower,
            (string) ($aggregate['operation'] ?? '')
        );
        $aggregate['field'] = $aggregate['field'] ?? $this->inferAggregateField($context, $modelName, $messageLower);
        $aggregate['group_by'] = $aggregate['group_by'] ?? $this->inferAggregateGroupBy($context, $messageLower);

        $parameters['aggregate'] = array_filter(
            $aggregate,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
        $decision['parameters'] = $parameters;

        return $decision;
    }

    protected function injectContextualRelationFilters(array $decision, array $context, string $message): array
    {
        $tool = strtolower((string) ($decision['tool'] ?? ''));
        if (!in_array($tool, ['db_query', 'db_count', 'db_aggregate'], true)) {
            return $decision;
        }

        $parameters = is_array($decision['parameters'] ?? null) ? $decision['parameters'] : [];
        $modelName = strtolower(trim((string) ($parameters['model'] ?? '')));
        if ($modelName === '') {
            return $decision;
        }

        $modelContext = $this->findModelContext($context, $modelName);
        if (!$modelContext) {
            return $decision;
        }

        $filters = is_array($parameters['filters'] ?? null) ? $parameters['filters'] : [];
        $messageLower = strtolower($message);
        $schemaFields = array_map('strtolower', array_keys((array) ($modelContext['schema'] ?? [])));
        [$filters, $hasCurrentUserPlaceholder] = $this->normalizeCurrentUserFilters(
            $filters,
            $modelContext,
            $context,
            $schemaFields
        );

        if ($this->hasPossessiveIntent($messageLower) || $hasCurrentUserPlaceholder) {
            $userField = $this->resolveUserField($modelContext, $schemaFields);
            $userId = $context['user_id'] ?? null;
            if (
                $userField &&
                $userId !== null &&
                $userId !== '' &&
                !array_key_exists($userField, $filters) &&
                !$this->hasPrimaryFilter($filters)
            ) {
                $filters[$userField] = $userId;
            }
        }

        $selected = $context['selected_entity'] ?? null;
        if (is_array($selected) && !empty($selected['entity_id'])) {
            $selectedType = strtolower(trim((string) ($selected['entity_type'] ?? '')));
            if (
                $selectedType !== '' &&
                $selectedType !== $modelName &&
                $this->messageReferencesSelectedEntity($messageLower, $selectedType)
            ) {
                $relationField = $this->resolveRelationField($modelContext, $selectedType, $schemaFields);
                if ($relationField && !array_key_exists($relationField, $filters) && !$this->hasPrimaryFilter($filters)) {
                    $filters[$relationField] = (int) $selected['entity_id'];
                }
            }
        }

        if (!empty($filters)) {
            $parameters['filters'] = $filters;
            $decision['parameters'] = $parameters;
        }

        return $decision;
    }

    protected function enforceFollowUpContextUsage(array $decision, array $context, string $message): array
    {
        $tool = strtolower((string) ($decision['tool'] ?? ''));
        if ($tool !== 'db_query') {
            return $decision;
        }

        $messageLower = strtolower($message);
        if ($this->hasListIntent($messageLower)) {
            return $decision;
        }

        $parameters = is_array($decision['parameters'] ?? null) ? $decision['parameters'] : [];
        $filters = is_array($parameters['filters'] ?? null) ? $parameters['filters'] : [];
        if ($this->hasPrimaryFilter($filters)) {
            return $decision;
        }

        $normalizedModel = strtolower(trim((string) ($parameters['model'] ?? '')));

        $selected = $context['selected_entity'] ?? null;
        if (is_array($selected) && !empty($selected['entity_id']) && !empty($selected['entity_type'])) {
            $selectedType = strtolower(trim((string) $selected['entity_type']));
            if ($normalizedModel === '' || $normalizedModel === $selectedType) {
                $parameters['model'] = $selectedType;
                $parameters['filters'] = array_merge($filters, ['id' => (int) $selected['entity_id']]);
                $parameters['limit'] = 1;
                $decision['parameters'] = $parameters;
                $decision['reasoning'] = 'Follow-up resolved using selected entity context';
                return $decision;
            }
        }

        $lastList = $context['last_entity_list'] ?? null;
        if (!is_array($lastList)) {
            return $decision;
        }

        $listType = strtolower(trim((string) ($lastList['entity_type'] ?? '')));
        if ($listType === '' || ($normalizedModel !== '' && $normalizedModel !== $listType)) {
            return $decision;
        }

        $entityIds = array_values(array_filter((array) ($lastList['entity_ids'] ?? []), fn ($id) => is_numeric($id)));
        $resolvedId = $this->resolveVisibleEntityId($messageLower, $entityIds, (int) ($lastList['start_position'] ?? 1));

        if ($resolvedId !== null) {
            $parameters['model'] = $listType;
            $parameters['filters'] = array_merge($filters, ['id' => $resolvedId]);
            $parameters['limit'] = 1;
            $decision['parameters'] = $parameters;
            $decision['reasoning'] = 'Follow-up resolved using visible list entity reference';
            return $decision;
        }

        if (count($entityIds) === 1) {
            $parameters['model'] = $listType;
            $parameters['filters'] = array_merge($filters, ['id' => (int) $entityIds[0]]);
            $parameters['limit'] = 1;
            $decision['parameters'] = $parameters;
            $decision['reasoning'] = 'Follow-up resolved using single visible entity';
            return $decision;
        }

        $fallback = [
            'tool' => 'vector_search',
            'reasoning' => 'Follow-up inferred from visible list context without explicit record selection',
            'parameters' => [
                'model' => $listType,
                'query' => $message,
                'limit' => $this->policy->itemsPerPage(),
            ],
            'decision_source' => $decision['decision_source'] ?? 'ai',
        ];

        if (isset($decision['policy']) && is_array($decision['policy'])) {
            $fallback['policy'] = $decision['policy'];
        }

        return $fallback;
    }

    protected function findModelContext(array $context, string $modelName): ?array
    {
        $target = $this->canonicalEntityType($modelName);

        foreach ((array) ($context['models'] ?? []) as $model) {
            $name = $this->canonicalEntityType((string) ($model['name'] ?? ''));
            $table = $this->canonicalEntityType((string) ($model['table'] ?? ''));
            $class = $this->canonicalEntityType((string) ($model['class'] ?? ''));

            if ($target !== '' && ($name === $target || $table === $target || $class === $target)) {
                return is_array($model) ? $model : null;
            }
        }

        return null;
    }

    protected function resolveUserField(array $modelContext, array $schemaFields): ?string
    {
        $filterConfig = (array) ($modelContext['filter_config'] ?? []);
        $userField = strtolower(trim((string) ($filterConfig['user_field'] ?? '')));
        if ($userField !== '') {
            return $userField;
        }

        $candidates = ['created_by', 'user_id', 'owner_id'];
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $schemaFields, true)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function resolveRelationField(array $modelContext, string $selectedType, array $schemaFields): ?string
    {
        $selectedType = $this->canonicalEntityType($selectedType);
        $filterConfig = (array) ($modelContext['filter_config'] ?? []);
        $relationFilters = (array) ($filterConfig['relation_filters'] ?? []);
        foreach ($relationFilters as $alias => $field) {
            $normalizedAlias = $this->canonicalEntityType((string) $alias);
            if ($normalizedAlias === $selectedType && is_string($field) && trim($field) !== '') {
                return strtolower(trim($field));
            }
        }

        $candidates = [
            $selectedType . '_id',
            $this->normalizeSingular($selectedType) . '_id',
            $this->normalizePlural($selectedType) . '_id',
        ];

        if ($selectedType === 'user') {
            $candidates = array_merge(['user_id', 'created_by', 'owner_id'], $candidates);
        }

        if ($selectedType === 'customer') {
            $candidates = array_merge(['customer_id', 'user_id'], $candidates);
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (in_array($candidate, $schemaFields, true)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function messageReferencesSelectedEntity(string $messageLower, string $selectedType): bool
    {
        $selectedType = $this->canonicalEntityType($selectedType);
        $selectedTypeSingular = $this->normalizeSingular($selectedType);
        $selectedTypePlural = $this->normalizePlural($selectedType);

        if (
            ($selectedType !== '' && str_contains($messageLower, $selectedType)) ||
            ($selectedTypeSingular !== '' && str_contains($messageLower, $selectedTypeSingular)) ||
            ($selectedTypePlural !== '' && str_contains($messageLower, $selectedTypePlural))
        ) {
            return true;
        }

        if (preg_match('/\b(this|that|selected|current|their|his|her)\b/', $messageLower)) {
            return true;
        }

        return preg_match('/\b(for|of|from|by)\b/', $messageLower) === 1;
    }

    protected function hasPossessiveIntent(string $messageLower): bool
    {
        if ($this->decisionLanguageMode() === 'ai_first') {
            return false;
        }

        foreach ($this->lexiconAcrossLocales('intent.possessive', ['my', 'mine', 'our', 'ours']) as $token) {
            if ($this->containsAlias($messageLower, $token)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeCurrentUserFilters(
        array $filters,
        array $modelContext,
        array $context,
        array $schemaFields
    ): array {
        $userId = $context['user_id'] ?? null;
        if ($userId === null || $userId === '') {
            return [$filters, false];
        }

        $normalizedFields = array_values(array_filter(array_map(
            static fn ($field): string => strtolower(trim((string) $field)),
            (array) config('ai-engine.rag.user_scope_fields', ['user_id', 'created_by', 'owner_id'])
        )));

        $resolvedUserField = $this->resolveUserField($modelContext, $schemaFields);
        if ($resolvedUserField !== null && $resolvedUserField !== '') {
            $normalizedFields[] = strtolower($resolvedUserField);
        }

        $placeholderMatched = false;
        foreach ($filters as $field => $value) {
            if (!is_string($field)) {
                continue;
            }

            $normalizedField = strtolower(trim($field));
            if (!in_array($normalizedField, $normalizedFields, true)) {
                continue;
            }

            if ($this->isCurrentUserPlaceholder($value)) {
                unset($filters[$field]);
                $placeholderMatched = true;
            }
        }

        if ($placeholderMatched && $resolvedUserField !== null && $resolvedUserField !== '') {
            $filters[$resolvedUserField] = $userId;
        }

        return [$filters, $placeholderMatched];
    }

    protected function isCurrentUserPlaceholder(mixed $value): bool
    {
        if (is_numeric($value)) {
            return false;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        $normalized = preg_replace('/\s+/u', ' ', str_replace(['-', '_'], ' ', $normalized)) ?: $normalized;
        if ($this->isTechnicalCurrentUserPlaceholder($normalized)) {
            return true;
        }

        if ($this->decisionLanguageMode() === 'ai_first') {
            return false;
        }

        $tokens = array_map(
            static fn (string $token): string => preg_replace('/\s+/u', ' ', str_replace(['-', '_'], ' ', trim($token))) ?: '',
            $this->lexiconAcrossLocales(
                'user.current_placeholders',
                ['current_user_id', 'current user', 'me', 'myself', 'self']
            )
        );

        return in_array($normalized, array_values(array_filter(array_unique($tokens))), true);
    }

    protected function isTechnicalCurrentUserPlaceholder(string $normalized): bool
    {
        return in_array($normalized, [
            'current user id',
            'current userid',
            'current user',
            'authenticated user',
            'auth user',
            'my user id',
            'me',
            'myself',
            'self',
        ], true);
    }

    protected function lexiconAcrossLocales(string $key, array $default = []): array
    {
        $tokens = [];
        foreach ($this->availableLexiconLocales() as $locale) {
            $tokens = array_merge($tokens, $this->locale->lexicon($key, $locale));
        }

        if ($tokens === []) {
            $tokens = $default;
        }

        return array_values(array_filter(array_unique(array_map(static function ($token): string {
            return mb_strtolower(trim((string) $token));
        }, $tokens))));
    }

    protected function availableLexiconLocales(): array
    {
        $mode = $this->decisionLanguageMode();
        if ($mode === 'ai_first') {
            return [];
        }

        $base = array_values(array_filter(array_unique([
            $this->activeContextLocale,
            $this->normalizeLocaleTag((string) app()->getLocale()),
            $this->normalizeLocaleTag((string) (config('ai-engine.localization.fallback_locale') ?: config('app.fallback_locale'))),
        ])));

        if ($mode !== 'strict') {
            return $base;
        }

        return array_values(array_filter(array_unique([
            ...$base,
            ...array_map([$this, 'normalizeLocaleTag'], (array) config('ai-engine.localization.supported_locales', [])),
        ])));
    }

    protected function decisionLanguageMode(): string
    {
        if ($this->policy && method_exists($this->policy, 'decisionLanguageMode')) {
            return $this->policy->decisionLanguageMode();
        }

        $mode = strtolower(trim((string) config('ai-engine.rag.decision.language_mode', 'hybrid')));

        return in_array($mode, ['ai_first', 'hybrid', 'strict'], true) ? $mode : 'hybrid';
    }

    protected function normalizeLocaleTag(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $normalized = strtolower(str_replace('_', '-', trim((string) $value)));

        return $normalized !== '' ? $normalized : null;
    }

    protected function hasPrimaryFilter(array $filters): bool
    {
        return isset($filters['id']) || isset($filters['uuid']) || isset($filters['slug']);
    }

    protected function resolveVisibleEntityId(string $messageLower, array $entityIds, int $startPosition): ?int
    {
        if (empty($entityIds)) {
            return null;
        }

        if (preg_match('/#(\d{1,10})/', $messageLower, $idMatch)) {
            $explicitId = (int) $idMatch[1];
            if (in_array($explicitId, array_map('intval', $entityIds), true)) {
                return $explicitId;
            }
        }

        $wordOrdinals = [
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
            'sixth' => 6,
            'seventh' => 7,
            'eighth' => 8,
            'ninth' => 9,
            'tenth' => 10,
        ];

        foreach ($wordOrdinals as $word => $ordinal) {
            if (str_contains($messageLower, $word)) {
                return $this->resolveIdByPosition($entityIds, $startPosition, $ordinal);
            }
        }

        if (preg_match('/\b(\d+)(st|nd|rd|th)\b/', $messageLower, $positionMatch)) {
            return $this->resolveIdByPosition($entityIds, $startPosition, (int) $positionMatch[1]);
        }

        if (preg_match('/\b(?:item|record|result|number)\s+(\d+)\b/', $messageLower, $positionMatch)) {
            return $this->resolveIdByPosition($entityIds, $startPosition, (int) $positionMatch[1]);
        }

        return null;
    }

    protected function resolveIdByPosition(array $entityIds, int $startPosition, int $position): ?int
    {
        if ($position <= 0) {
            return null;
        }

        $absoluteIndex = $position - $startPosition;
        if (isset($entityIds[$absoluteIndex])) {
            return (int) $entityIds[$absoluteIndex];
        }

        $relativeIndex = $position - 1;
        if (isset($entityIds[$relativeIndex])) {
            return (int) $entityIds[$relativeIndex];
        }

        return null;
    }

    protected function resolveModelNameFromCandidate(string $candidate, array $models): ?string
    {
        $normalizedCandidate = strtolower(trim($candidate));
        $singularCandidate = $this->normalizeSingular($normalizedCandidate);
        $pluralCandidate = $this->normalizePlural($normalizedCandidate);

        foreach ($models as $model) {
            $name = strtolower((string) ($model['name'] ?? ''));
            $table = strtolower((string) ($model['table'] ?? ''));
            $singularName = $this->normalizeSingular($name);
            $pluralName = $this->normalizePlural($name);

            if (
                $normalizedCandidate === $name ||
                $normalizedCandidate === $table ||
                $singularCandidate === $singularName ||
                $pluralCandidate === $pluralName
            ) {
                return (string) ($model['name'] ?? '');
            }
        }

        return null;
    }

    protected function normalizeSingular(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        if (strlen($value) > 2 && str_ends_with($value, 's') && !str_ends_with($value, 'ss')) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    protected function normalizePlural(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        return str_ends_with($value, 's') ? $value : $value . 's';
    }

    protected function tryParseDecisionJson(string $content): ?array
    {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);

        $decision = json_decode($content, true);
        if (is_array($decision) && isset($decision['tool'])) {
            return $decision;
        }

        if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
            $decision = json_decode($matches[0], true);
            if (is_array($decision) && isset($decision['tool'])) {
                return $decision;
            }
        }

        return null;
    }

    protected function inferModelName(array $context, string $messageLower): ?string
    {
        $explicit = $this->detectExplicitModelFromMessage($context, $messageLower);
        if ($explicit !== null) {
            return $explicit;
        }

        foreach ($context['models'] ?? [] as $model) {
            $modelName = strtolower((string) ($model['name'] ?? ''));
            if ($modelName !== '' && stripos($messageLower, $modelName) !== false) {
                return $model['name'];
            }
        }

        return $context['selected_entity']['entity_type']
            ?? $context['last_entity_list']['entity_type']
            ?? $context['target_model']
            ?? ($context['models'][0]['name'] ?? null);
    }

    protected function detectExplicitModelFromMessage(array $context, string $message): ?string
    {
        $normalizedMessage = mb_strtolower(trim($message));
        if ($normalizedMessage === '') {
            return null;
        }

        $matches = [];
        foreach ((array) ($context['models'] ?? []) as $model) {
            $modelName = (string) ($model['name'] ?? '');
            if ($modelName === '') {
                continue;
            }

            $score = $this->scoreModelAliasMatches($normalizedMessage, $modelName, $model);
            if ($score > 0) {
                $matches[$modelName] = $score;
            }
        }

        if ($matches === []) {
            return null;
        }

        arsort($matches);
        $bestScore = reset($matches);
        if (!is_int($bestScore)) {
            return null;
        }

        $bestModels = array_keys(array_filter($matches, static fn (int $score): bool => $score === $bestScore));
        if (count($bestModels) === 1) {
            return $bestModels[0];
        }

        $selected = $this->canonicalEntityType((string) ($context['selected_entity']['entity_type'] ?? ''));
        foreach ($bestModels as $candidate) {
            if ($this->canonicalEntityType($candidate) === $selected) {
                return $candidate;
            }
        }

        $lastList = $this->canonicalEntityType((string) ($context['last_entity_list']['entity_type'] ?? ''));
        foreach ($bestModels as $candidate) {
            if ($this->canonicalEntityType($candidate) === $lastList) {
                return $candidate;
            }
        }

        return null;
    }

    protected function scoreModelAliasMatches(string $message, string $modelName, array $model): int
    {
        $aliases = $this->modelAliasCandidates($modelName, $model);
        $score = 0;

        foreach ($aliases as $alias) {
            if ($this->containsAlias($message, $alias)) {
                $score = max($score, mb_strlen($alias));
            }
        }

        return $score;
    }

    protected function modelAliasCandidates(string $modelName, array $model): array
    {
        $canonical = $this->canonicalEntityType($modelName);
        $explicitAliases = array_values(array_filter(array_map(
            static fn ($alias): string => mb_strtolower(trim((string) $alias)),
            (array) ($model['aliases'] ?? [])
        )));

        $aliases = [
            mb_strtolower(trim((string) ($model['display_name'] ?? ''))),
            mb_strtolower(trim((string) ($model['table'] ?? ''))),
            mb_strtolower(trim((string) ($model['class'] ?? ''))),
        ];

        if ($explicitAliases === []) {
            $aliases[] = mb_strtolower(trim($modelName));
            $aliases[] = $this->normalizeSingular(mb_strtolower(trim($modelName)));
            $aliases[] = $this->normalizePlural(mb_strtolower(trim($modelName)));
        } else {
            $aliases = array_merge($aliases, $explicitAliases);
        }

        if ($this->decisionLanguageMode() !== 'ai_first') {
            foreach ($this->availableLexiconLocales() as $locale) {
                $aliases = array_merge($aliases, $this->locale->lexicon("entities.aliases.{$canonical}", $locale));
            }
        }

        return array_values(array_filter(array_unique(array_map(static function (string $alias): string {
            $alias = trim(mb_strtolower($alias));
            return str_replace(['-', '_'], ' ', $alias);
        }, $aliases))));
    }

    protected function containsAlias(string $message, string $alias): bool
    {
        if ($alias === '') {
            return false;
        }

        if ($message === $alias) {
            return true;
        }

        $pattern = '/(^|[^\p{L}\p{N}_])' . preg_quote($alias, '/') . '([^\p{L}\p{N}_]|$)/u';

        return preg_match($pattern, $message) === 1;
    }

    protected function inferAggregateOperation(string $messageLower, string $content): string
    {
        $haystack = strtolower($content . ' ' . $messageLower);

        if (
            str_contains($haystack, 'summary') ||
            str_contains($haystack, 'statistics') ||
            str_contains($haystack, 'stats') ||
            ((str_contains($haystack, 'total') || str_contains($haystack, 'sum')) &&
                (str_contains($haystack, 'average') || str_contains($haystack, 'avg')))
        ) {
            return 'summary';
        }

        if (str_contains($haystack, 'count') || str_contains($haystack, 'how many')) {
            return 'count';
        }

        if (str_contains($haystack, 'average') || str_contains($haystack, 'avg')) {
            return 'avg';
        }

        if (str_contains($haystack, 'minimum') || str_contains($haystack, 'min')) {
            return 'min';
        }

        if (str_contains($haystack, 'maximum') || str_contains($haystack, 'max')) {
            return 'max';
        }

        return 'sum';
    }

    protected function inferAggregateField(array $context, ?string $modelName, string $messageLower = ''): string
    {
        $modelContext = collect($context['models'] ?? [])
            ->first(fn (array $model) => ($model['name'] ?? null) === $modelName);

        $schema = (array) ($modelContext['schema'] ?? []);
        $scored = [];
        foreach ($schema as $field => $type) {
            $fieldLower = strtolower((string) $field);
            $typeString = strtolower((string) $type);
            $isNumeric = str_contains($typeString, 'int') ||
                str_contains($typeString, 'float') ||
                str_contains($typeString, 'double') ||
                str_contains($typeString, 'decimal') ||
                str_contains($typeString, 'numeric') ||
                preg_match('/(amount|total|price|cost|balance|subtotal|tax|quantity|qty|rate|value)$/i', $fieldLower) === 1;

            if (!$isNumeric) {
                continue;
            }

            $score = 10;
            if (preg_match('/(^|_)id$/', $fieldLower)) {
                $score -= 100;
            }
            if (str_contains($messageLower, str_replace('_', ' ', $fieldLower)) || str_contains($messageLower, $fieldLower)) {
                $score += 120;
            }
            if (in_array($fieldLower, ['total', 'amount', 'total_amount', 'amount_total'], true)) {
                $score += 90;
            } elseif (str_contains($fieldLower, 'total') || str_contains($fieldLower, 'amount')) {
                $score += 75;
            } elseif (str_contains($fieldLower, 'balance') || str_contains($fieldLower, 'price') || str_contains($fieldLower, 'cost')) {
                $score += 60;
            } elseif (str_contains($fieldLower, 'subtotal') || str_contains($fieldLower, 'tax')) {
                $score += 45;
            }

            $scored[(string) $field] = $score;
        }

        if ($scored !== []) {
            arsort($scored);
            return (string) array_key_first($scored);
        }

        return array_key_first($schema) ?: 'id';
    }

    protected function inferAggregateGroupBy(array $context, string $messageLower): ?string
    {
        if (preg_match('/\b(group by|by|per)\s+([a-z_ ]{2,32})/i', $messageLower, $matches)) {
            $candidate = trim(str_replace(' ', '_', strtolower($matches[2])));
            $candidate = preg_replace('/\b(invoice|invoices|mail|mails|emails|records|items|amount|total|average|avg|sum|count)\b/', '', $candidate) ?? '';
            $candidate = trim($candidate, '_ ');
            if ($candidate !== '') {
                $field = $this->resolveAggregateGroupField($context, $candidate);
                if ($field !== null) {
                    return $field;
                }
            }
        }

        foreach ([
            'status' => ['status', 'state'],
            'month' => ['month', 'monthly'],
            'customer_name' => ['customer', 'client'],
            'project_id' => ['project'],
            'workspace_id' => ['workspace'],
            'user_id' => ['user', 'owner'],
        ] as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/\b(by|per|grouped by|group by)\s+' . preg_quote($alias, '/') . '\b/i', $messageLower)) {
                    return $this->resolveAggregateGroupField($context, $field) ?? $field;
                }
            }
        }

        return null;
    }

    protected function resolveAggregateGroupField(array $context, string $candidate): ?string
    {
        if ($candidate === 'month') {
            return 'month';
        }

        $schema = [];
        foreach ((array) ($context['models'] ?? []) as $model) {
            $schema = array_merge($schema, (array) ($model['schema'] ?? []));
        }

        $candidate = strtolower(trim($candidate));
        foreach (array_keys($schema) as $field) {
            $fieldLower = strtolower((string) $field);
            if ($fieldLower === $candidate || str_replace('_', ' ', $fieldLower) === str_replace('_', ' ', $candidate)) {
                return (string) $field;
            }
        }

        return null;
    }

    protected function hasAggregateIntent(string $messageLower, string $content): bool
    {
        $haystack = strtolower($content . ' ' . $messageLower);

        return str_contains($haystack, 'db_aggregate') ||
            str_contains($haystack, 'sum') ||
            str_contains($haystack, 'total') ||
            str_contains($haystack, 'average') ||
            str_contains($haystack, 'avg') ||
            str_contains($haystack, 'minimum') ||
            str_contains($haystack, 'maximum') ||
            str_contains($haystack, 'how many') ||
            str_contains($haystack, 'count');
    }

    protected function hasNextPageIntent(string $messageLower): bool
    {
        return str_contains($messageLower, 'next') ||
            str_contains($messageLower, 'more') ||
            str_contains($messageLower, 'continue');
    }

    protected function hasListIntent(string $messageLower): bool
    {
        if (
            str_contains($messageLower, 'list') ||
            str_contains($messageLower, 'show all') ||
            str_contains($messageLower, 'get all')
        ) {
            return true;
        }

        if (preg_match('/\b(show|get|fetch|display)\s+(?:me\s+)?(?:all\s+)?([a-z_0-9]+)\b/', $messageLower, $matches) !== 1) {
            return false;
        }

        $token = $matches[2] ?? '';
        if ($token === '') {
            return false;
        }

        if (preg_match('/^\d+(?:st|nd|rd|th)?$/', $token) === 1) {
            return false;
        }

        if (in_array($token, [
            'status',
            'detail',
            'details',
            'summary',
            'total',
            'count',
            'first',
            'second',
            'third',
            'fourth',
            'fifth',
            'this',
            'that',
            'selected',
            'current',
            'one',
            'it',
        ], true)) {
            return false;
        }

        return str_ends_with($token, 's');
    }

    protected function canonicalEntityType(string $value): string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '\\')) {
            $parts = explode('\\', $value);
            $value = end($parts) ?: $value;
        }

        return trim(str_replace(['-', ' '], '_', $value));
    }
}
