<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class AgentSelectionService
{
    public function __construct(protected AgentResponseFinalizer $responseFinalizer)
    {
    }

    protected function documentBuilder(): \LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder
    {
        return app(\LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder::class);
    }

    public function detectsOptionSelection(string $message, UnifiedActionContext $context): bool
    {
        $trimmed = trim($message);
        if (!is_numeric($trimmed)) {
            return false;
        }

        $optionNumber = (int) $trimmed;
        if ($optionNumber < 1 || $optionNumber > 10) {
            return false;
        }

        $history = $context->conversationHistory ?? [];
        if (empty($history)) {
            return false;
        }

        $lastAssistantMessage = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'assistant') {
                $lastAssistantMessage = $history[$i]['content'] ?? '';
                break;
            }
        }

        if (!$lastAssistantMessage) {
            return false;
        }

        return (bool) preg_match('/\b\d+[\.\)]\s+/m', $lastAssistantMessage);
    }

    public function detectsPositionalReference(string $message, UnifiedActionContext $context): bool
    {
        $positionalPattern = '/\b(first|second|third|fourth|fifth|1st|2nd|3rd|4th|5th|the\s+\d+|number\s+\d+|\d+)\s*(one|email|invoice|item|entry)?\b/i';
        if (!preg_match($positionalPattern, $message)) {
            return false;
        }

        $messages = array_reverse($context->conversationHistory);
        foreach ($messages as $msg) {
            if (($msg['role'] ?? null) === 'assistant' && !empty($msg['metadata']['entity_ids'])) {
                return true;
            }
        }

        return false;
    }

    public function handleOptionSelection(
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $searchRag,
        callable $routeToNode
    ): ?AgentResponse {
        $optionNumber = (int) trim($message);

        $history = $context->conversationHistory ?? [];
        $lastAssistantMessage = '';
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'assistant') {
                $lastAssistantMessage = $history[$i]['content'] ?? '';
                break;
            }
        }

        Log::channel('ai-engine')->info('Handling option selection', [
            'option_number' => $optionNumber,
            'last_message_preview' => substr($lastAssistantMessage, 0, 200),
        ]);

        return $this->handleStoredSelectionOption($optionNumber, $context, $options, $searchRag, $routeToNode);
    }

    public function handlePositionalReference(
        string $message,
        UnifiedActionContext $context,
        array $options,
        callable $searchRag,
        ?callable $routeToNode = null
    ): AgentResponse {
        $position = $this->extractPosition($message);
        if ($position === null) {
            return AgentResponse::conversational(
                message: $this->translate(
                    'ai-engine::messages.agent.selection_unrecognized',
                    "I couldn't understand which item you're referring to. Could you be more specific?"
                ),
                context: $context
            );
        }

        $entityData = $this->resolveSelectionOption($position, $context)
            ?? $this->getEntityFromPosition($position, $context);

        if (!$entityData) {
            return AgentResponse::conversational(
                message: $this->translate(
                    'ai-engine::messages.agent.selection_item_not_found',
                    "I couldn't find item #{$position} in the previous list. Please check the number and try again.",
                    ['position' => $position]
                ),
                context: $context
            );
        }

        $entityId = (int) ($entityData['entity_id'] ?? $entityData['id'] ?? 0);
        $entityType = (string) ($entityData['entity_type'] ?? $entityData['type'] ?? 'item');
        $modelClass = is_string($entityData['model_class'] ?? null) ? $entityData['model_class'] : null;
        $sourceNode = is_string($entityData['source_node'] ?? null) ? $entityData['source_node'] : null;

        if ($entityId <= 0) {
            return AgentResponse::conversational(
                message: $this->translate(
                    'ai-engine::messages.agent.selection_item_not_found',
                    "I couldn't find item #{$position} in the previous list. Please check the number and try again.",
                    ['position' => $position]
                ),
                context: $context
            );
        }

        if ($sourceNode && (!$modelClass || !class_exists($modelClass)) && is_callable($routeToNode)) {
            Log::channel('ai-engine')->debug('Selection detail direct route to source node', [
                'session_id' => $context->sessionId,
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'source_node' => $sourceNode,
            ]);

            $context->metadata['selected_entity_context'] = [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'model_class' => $modelClass,
                'source_node' => $sourceNode,
                'selected_via' => 'positional_reference',
                'position' => $position,
                'selected_at' => now()->toIso8601String(),
            ];

            $nodeResponse = $routeToNode(
                $sourceNode,
                "show full details for {$entityType} id {$entityId}",
                $context,
                $options
            );
            if ($nodeResponse instanceof AgentResponse) {
                $this->responseFinalizer->appendAssistantMessageIfNew($context, $nodeResponse->message);

                return $nodeResponse;
            }
        }

        $fullEntity = $this->fetchEntityDetails($entityId, $entityType, $modelClass, $context);

        if (!$fullEntity) {
            Log::channel('ai-engine')->debug('Selection detail fallback to RAG by entity reference', [
                'session_id' => $context->sessionId,
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'model_class' => $modelClass,
                'source_node' => $sourceNode,
            ]);

            // Keep deterministic selected entity context even when local direct fetch is unavailable.
            // This allows RAG to resolve the follow-up using id + type (including remote-node cases).
            $context->metadata['selected_entity_context'] = [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'model_class' => $modelClass,
                'source_node' => $sourceNode,
                'selected_via' => 'positional_reference',
                'position' => $position,
                'selected_at' => now()->toIso8601String(),
            ];

            if ($sourceNode && is_callable($routeToNode)) {
                $nodeResponse = $routeToNode(
                    $sourceNode,
                    "show full details for {$entityType} id {$entityId}",
                    $context,
                    $options
                );
                if ($nodeResponse instanceof AgentResponse && $nodeResponse->success) {
                    $this->responseFinalizer->appendAssistantMessageIfNew($context, $nodeResponse->message);

                    return $nodeResponse;
                }
            }

            $fallbackMessage = "show full details for {$entityType} id {$entityId}";
            $response = $searchRag($fallbackMessage, $context, $options);
            $this->responseFinalizer->appendAssistantMessageIfNew($context, $response->message);

            return $response;
        }

            $context->metadata['selected_entity_context'] = [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'entity_data' => $fullEntity,
                'object' => $fullEntity['object'] ?? null,
                'entity_ref' => $fullEntity['entity_ref'] ?? null,
                'model_class' => $modelClass,
                'source_node' => $sourceNode,
                'selected_via' => 'positional_reference',
            'position' => $position,
            'suggested_action_content' => null,
        ];

        return $searchRag($message, $context, $options);
    }

    public function captureSelectionStateFromResult(array $result, UnifiedActionContext $context): void
    {
        $metadata = (isset($result['metadata']) && is_array($result['metadata']))
            ? $result['metadata']
            : [];
        $sources = (isset($metadata['sources']) && is_array($metadata['sources']))
            ? $metadata['sources']
            : [];
        $numberedOptions = (isset($metadata['numbered_options']) && is_array($metadata['numbered_options']))
            ? $metadata['numbered_options']
            : [];

        $queryState = Cache::get("rag_query_state:{$context->sessionId}");
        if (!is_array($queryState)) {
            $queryState = [];
        }

        $entityIds = $result['entity_ids'] ?? $metadata['entity_ids'] ?? $queryState['entity_ids'] ?? [];
        $entityType = $result['entity_type'] ?? $metadata['entity_type'] ?? $queryState['model'] ?? null;
        $defaultModelClass = $queryState['model_class'] ?? null;
        $defaultSourceNode = null;

        foreach ($sources as $sourceItem) {
            if (!is_array($sourceItem)) {
                continue;
            }

            if (!$defaultModelClass) {
                $candidateClass = $sourceItem['model_class'] ?? null;
                if (!empty($candidateClass) && $candidateClass !== 'Unknown') {
                    $defaultModelClass = $candidateClass;
                }
            }

            if (!$defaultSourceNode && !empty($sourceItem['source_node'])) {
                $defaultSourceNode = $sourceItem['source_node'];
            }
        }

        if (!empty($entityIds)) {
            $context->metadata['last_entity_list'] = [
                'entity_ids' => array_values($entityIds),
                'entity_type' => $entityType,
                'start_position' => $queryState['start_position'] ?? 1,
                'end_position' => $queryState['end_position'] ?? count($entityIds),
                'entity_data' => $queryState['entity_data'] ?? [],
                'entity_refs' => array_values(array_filter(array_map(
                    static fn ($source) => is_array($source['entity_ref'] ?? null) ? $source['entity_ref'] : null,
                    $sources
                ))),
                'objects' => array_values(array_filter(array_map(
                    static fn ($source) => is_array($source['object'] ?? null) ? $source['object'] : null,
                    $sources
                ))),
            ];
        }

        $mapOptions = [];

        foreach (array_values(array_slice($numberedOptions, 0, 20)) as $idx => $option) {
            $number = isset($option['number']) && is_numeric($option['number'])
                ? (int) $option['number']
                : (isset($option['value']) && is_numeric($option['value']) ? (int) $option['value'] : null);

            if (!$number) {
                continue;
            }

            $sourceIndex = isset($option['source_index']) && is_numeric($option['source_index'])
                ? (int) $option['source_index']
                : null;
            $source = ($sourceIndex !== null && isset($sources[$sourceIndex]) && is_array($sources[$sourceIndex]))
                ? $sources[$sourceIndex]
                : [];

            $entityId = $source['model_id'] ?? $source['id'] ?? null;
            if ($entityId === null && isset($entityIds[$idx])) {
                $entityId = $entityIds[$idx];
            }

            $mapOptions[(string) $number] = [
                'number' => $number,
                'entity_id' => $entityId,
                'entity_type' => $source['model_type'] ?? $entityType,
                'model_class' => $source['model_class'] ?? $defaultModelClass,
                'source_node' => $source['source_node'] ?? $defaultSourceNode,
                'entity_ref' => $source['entity_ref'] ?? null,
                'object' => $source['object'] ?? null,
                'label' => $option['text'] ?? null,
            ];
        }

        if (!empty($mapOptions) && !empty($entityIds)) {
            $orderedOptionNumbers = array_keys($mapOptions);
            sort($orderedOptionNumbers, SORT_NUMERIC);

            $hasValidEntityId = false;
            foreach ($orderedOptionNumbers as $optionNumberKey) {
                if (!empty($mapOptions[(string) $optionNumberKey]['entity_id'])) {
                    $hasValidEntityId = true;
                    break;
                }
            }

            if (!$hasValidEntityId) {
                foreach ($orderedOptionNumbers as $idx => $optionNumberKey) {
                    if (!isset($entityIds[$idx])) {
                        continue;
                    }

                    $mapOptions[(string) $optionNumberKey]['entity_id'] = $entityIds[$idx];
                    $mapOptions[(string) $optionNumberKey]['model_class'] = $mapOptions[(string) $optionNumberKey]['model_class'] ?? $defaultModelClass;
                    $mapOptions[(string) $optionNumberKey]['source_node'] = $mapOptions[(string) $optionNumberKey]['source_node'] ?? $defaultSourceNode;
                }
            }
        }

        if (empty($mapOptions) && !empty($entityIds)) {
            $start = (int) ($queryState['start_position'] ?? 1);
            foreach (array_slice(array_values($entityIds), 0, 20) as $idx => $entityId) {
                $number = $start + $idx;
                $mapOptions[(string) $number] = [
                    'number' => $number,
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'model_class' => $defaultModelClass,
                    'source_node' => $defaultSourceNode,
                    'label' => null,
                ];
            }
        }

        if (!empty($mapOptions)) {
            $context->metadata['selection_map'] = [
                'created_at' => now()->toIso8601String(),
                'expires_at' => now()->addMinutes(20)->toIso8601String(),
                'options' => $mapOptions,
            ];

            Log::channel('ai-engine')->debug('Stored hidden selection map', [
                'session_id' => $context->sessionId,
                'option_count' => count($mapOptions),
                'keys' => array_keys($mapOptions),
            ]);

            return;
        }

        unset($context->metadata['selection_map']);
    }

    protected function handleStoredSelectionOption(
        int $optionNumber,
        UnifiedActionContext $context,
        array $options,
        callable $searchRag,
        callable $routeToNode
    ): ?AgentResponse {
        $selection = $this->resolveSelectionOption($optionNumber, $context);
        if (!$selection) {
            return null;
        }

        Log::channel('ai-engine')->info('Resolved option from hidden selection map', [
            'option_number' => $optionNumber,
            'entity_id' => $selection['entity_id'] ?? null,
            'entity_type' => $selection['entity_type'] ?? null,
            'model_class' => $selection['model_class'] ?? null,
            'source_node' => $selection['source_node'] ?? null,
        ]);

        $entityId = $selection['entity_id'] ?? null;
        $modelClass = $selection['model_class'] ?? null;
        if ($entityId) {
            unset($context->metadata['suggested_actions']);
        }

        if ($entityId && $modelClass && class_exists($modelClass)) {
            try {
                $query = $modelClass::query();

                if ($context->userId !== null && method_exists($modelClass, 'scopeForUser')) {
                    $query->forUser($context->userId);
                } elseif ($context->userId !== null) {
                    $instance = new $modelClass();
                    $table = $instance->getTable();
                    if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'user_id')) {
                        $query->where('user_id', $context->userId);
                    }
                }

                $record = $query->find($entityId);
                if ($record) {
                    $detail = $this->formatSelectedRecordDetails($record);
                    $responseText = "**Selected option {$optionNumber}**\n\n{$detail}";

                    $context->metadata['last_selected_option'] = [
                        'option' => $optionNumber,
                        'entity_id' => $entityId,
                        'entity_type' => $selection['entity_type'] ?? class_basename($modelClass),
                        'model_class' => $modelClass,
                        'source_node' => $selection['source_node'] ?? null,
                    ];
                    $context->metadata['selected_entity_context'] = [
                        'entity_id' => (int) $entityId,
                        'entity_type' => $selection['entity_type'] ?? class_basename($modelClass),
                        'model_class' => $modelClass,
                        'source_node' => $selection['source_node'] ?? null,
                        'entity_ref' => $this->documentBuilder()->buildEntityRef($record, [
                            'source_node' => $selection['source_node'] ?? null,
                        ]),
                        'object' => $this->documentBuilder()->buildGraphObject($record, [
                            'source_node' => $selection['source_node'] ?? null,
                        ]),
                        'selected_via' => 'numbered_option',
                        'detail_excerpt' => substr(trim(strip_tags($detail)), 0, 800),
                        'selected_at' => now()->toIso8601String(),
                    ];
                    $this->responseFinalizer->appendAssistantMessageIfNew($context, $responseText);

                    return AgentResponse::conversational(
                        message: $responseText,
                        context: $context
                    );
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to resolve selected option from local model', [
                    'option_number' => $optionNumber,
                    'model_class' => $modelClass,
                    'entity_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($selection['source_node']) && $entityId && empty($modelClass)) {
            $context->metadata['selected_entity_context'] = [
                'entity_id' => (int) $entityId,
                'entity_type' => $selection['entity_type'] ?? 'record',
                'model_class' => $selection['model_class'] ?? null,
                'source_node' => $selection['source_node'],
                'entity_ref' => $selection['entity_ref'] ?? null,
                'object' => $selection['object'] ?? null,
                'selected_via' => 'numbered_option',
                'selected_at' => now()->toIso8601String(),
            ];
            $entityType = $selection['entity_type'] ?? 'record';
            $response = $routeToNode($selection['source_node'], "show details for {$entityType} id {$entityId}", $context, $options);
            $this->responseFinalizer->appendAssistantMessageIfNew($context, $response->message);

            return $response;
        }

        if ($entityId) {
            $context->metadata['selected_entity_context'] = [
                'entity_id' => (int) $entityId,
                'entity_type' => $selection['entity_type'] ?? 'item',
                'model_class' => $selection['model_class'] ?? null,
                'source_node' => $selection['source_node'] ?? null,
                'entity_ref' => $selection['entity_ref'] ?? null,
                'object' => $selection['object'] ?? null,
                'selected_via' => 'numbered_option',
                'selected_at' => now()->toIso8601String(),
            ];
            $entityType = $selection['entity_type'] ?? 'item';
            $response = $searchRag("show full details for {$entityType} id {$entityId}", $context, $options);
            $this->responseFinalizer->appendAssistantMessageIfNew($context, $response->message);

            return $response;
        }

        return null;
    }

    protected function extractPosition(string $message): ?int
    {
        $ordinals = [
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
            '1st' => 1,
            '2nd' => 2,
            '3rd' => 3,
            '4th' => 4,
            '5th' => 5,
        ];

        foreach ($ordinals as $word => $position) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $message)) {
                return $position;
            }
        }

        if (preg_match('/\b(?:the\s+|number\s+)?(\d+)\b/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    protected function getEntityFromPosition(int $position, UnifiedActionContext $context): ?array
    {
        $messages = array_reverse($context->conversationHistory);
        foreach ($messages as $msg) {
            if (($msg['role'] ?? null) === 'assistant' && !empty($msg['metadata']['entity_ids'])) {
                $entityIds = $msg['metadata']['entity_ids'];
                $entityType = $msg['metadata']['entity_type'] ?? 'item';
                $index = $position - 1;

                if (isset($entityIds[$index])) {
                    $source = (isset($msg['metadata']['sources']) && is_array($msg['metadata']['sources']) && isset($msg['metadata']['sources'][$index]) && is_array($msg['metadata']['sources'][$index]))
                        ? $msg['metadata']['sources'][$index]
                        : [];

                    return [
                        'id' => $entityIds[$index],
                        'type' => $entityType,
                        'model_class' => $source['model_class'] ?? null,
                        'source_node' => $source['source_node'] ?? null,
                    ];
                }

                return null;
            }
        }

        $queryState = Cache::get("rag_query_state:{$context->sessionId}");
        if (!is_array($queryState) || empty($queryState['entity_ids'])) {
            return null;
        }

        $startPosition = (int) ($queryState['start_position'] ?? 1);
        $index = $position - $startPosition;
        if ($index < 0 || $index >= count($queryState['entity_ids'])) {
            return null;
        }

        return [
            'id' => $queryState['entity_ids'][$index],
            'type' => $queryState['model'] ?? 'item',
            'model_class' => $queryState['model_class'] ?? null,
            'source_node' => $queryState['source_node'] ?? null,
        ];
    }

    protected function fetchEntityDetails(
        int $entityId,
        string $entityType,
        ?string $modelClass,
        UnifiedActionContext $context
    ): ?array {
        try {
            $modelClass = $modelClass ?: $this->getModelClassForEntityType($entityType);
            if (!$modelClass || !class_exists($modelClass)) {
                Log::channel('ai-engine')->warning('Unknown entity type for fetch', [
                    'entity_type' => $entityType,
                    'model_class' => $modelClass,
                ]);

                return null;
            }

            $entity = $modelClass::find($entityId);
            if (!$entity) {
                Log::channel('ai-engine')->warning('Entity not found in database', [
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'model_class' => $modelClass,
                ]);

                return null;
            }

            $document = $this->documentBuilder()->build($entity);

            return [
                ...$entity->toArray(),
                'entity_ref' => $document->entityRef(),
                'object' => $document->object,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Error fetching entity details', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function getModelClassForEntityType(string $entityType): ?string
    {
        $normalized = strtolower(trim($entityType));
        if ($normalized === '') {
            return null;
        }

        $explicitMap = (array) config('ai-agent.selection.entity_model_map', []);
        foreach ($explicitMap as $key => $class) {
            if (strtolower(trim((string) $key)) !== $normalized) {
                continue;
            }

            if (is_string($class) && class_exists($class)) {
                return $class;
            }
        }

        if (class_exists($entityType)) {
            return $entityType;
        }

        $namespace = rtrim(app()->getNamespace(), '\\') . '\\Models\\';
        $token = str_replace(['-', ' '], '_', $normalized);
        $candidates = array_values(array_unique(array_filter([
            Str::studly(Str::singular($token)),
            Str::studly(Str::plural($token)),
            Str::studly($token),
        ])));

        foreach ($candidates as $candidate) {
            $class = $namespace . $candidate;
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    protected function formatSelectedRecordDetails(object $record): string
    {
        if (method_exists($record, 'toRAGDetail')) {
            return (string) $record->toRAGDetail();
        }

        if (method_exists($record, 'toRAGContent')) {
            return (string) $record->toRAGContent();
        }

        if (method_exists($record, '__toString')) {
            return (string) $record;
        }

        if (!method_exists($record, 'toArray')) {
            return (string) json_encode($record, JSON_PRETTY_PRINT);
        }

        $data = $record->toArray();
        foreach (['body_text', 'content', 'description', 'notes', 'message', 'text'] as $field) {
            if (!empty($data[$field]) && is_string($data[$field])) {
                $clean = trim((string) preg_replace('/\s+/', ' ', strip_tags($data[$field])));
                if ($clean !== '') {
                    $data[$field] = strlen($clean) > 1800 ? substr($clean, 0, 1800) . '...' : $clean;
                    break;
                }
            }
        }

        return (string) json_encode($data, JSON_PRETTY_PRINT);
    }

    protected function resolveSelectionOption(int $optionNumber, UnifiedActionContext $context): ?array
    {
        $selectionMap = $context->metadata['selection_map'] ?? null;
        if (is_array($selectionMap)) {
            $expiresAt = $selectionMap['expires_at'] ?? null;
            if ($expiresAt && strtotime($expiresAt) < time()) {
                unset($context->metadata['selection_map']);
            } else {
                $options = $selectionMap['options'] ?? [];
                $option = $options[(string) $optionNumber] ?? $options[$optionNumber] ?? null;
                if (is_array($option) && !empty($option['entity_id'])) {
                    return $option;
                }
            }
        }

        $queryState = Cache::get("rag_query_state:{$context->sessionId}");
        if (!is_array($queryState) || empty($queryState['entity_ids'])) {
            return null;
        }

        $startPosition = (int) ($queryState['start_position'] ?? 1);
        $index = $optionNumber - $startPosition;
        if ($index < 0 || $index >= count($queryState['entity_ids'])) {
            return null;
        }

        $entityId = $queryState['entity_ids'][$index] ?? null;
        if (!$entityId) {
            return null;
        }

        return [
            'number' => $optionNumber,
            'entity_id' => $entityId,
            'entity_type' => $queryState['model'] ?? null,
            'model_class' => $queryState['model_class'] ?? null,
            'source_node' => $queryState['source_node'] ?? null,
            'label' => null,
        ];
    }

    protected function translate(string $key, string $fallback, array $replace = []): string
    {
        $translated = __($key, $replace);

        if (!is_string($translated) || $translated === $key) {
            $fallbackText = $fallback;
            foreach ($replace as $replaceKey => $replaceValue) {
                $fallbackText = str_replace(":{$replaceKey}", (string) $replaceValue, $fallbackText);
            }

            return $fallbackText;
        }

        return $translated;
    }
}
