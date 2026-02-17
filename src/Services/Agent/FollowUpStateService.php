<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;

class FollowUpStateService
{
    public function __construct(
        protected array $entityModelMap = []
    ) {
    }

    public function hasEntityListContext(UnifiedActionContext $context): bool
    {
        $lastEntityList = $context->metadata['last_entity_list'] ?? null;
        if (is_array($lastEntityList) && (!empty($lastEntityList['entity_ids']) || !empty($lastEntityList['entity_data']))) {
            return true;
        }

        $ragMetadata = $context->metadata['rag_last_metadata'] ?? [];
        if (is_array($ragMetadata)) {
            if (!empty($ragMetadata['entity_ids']) || !empty($ragMetadata['numbered_options']) || !empty($ragMetadata['sources'])) {
                return true;
            }
        }

        if ($this->getSelectedEntityContext($context) !== null) {
            return true;
        }

        $messages = array_reverse($context->conversationHistory ?? []);
        foreach ($messages as $msg) {
            if (($msg['role'] ?? null) === 'assistant' && !empty($msg['metadata']['entity_ids'])) {
                return true;
            }
        }

        return false;
    }

    public function formatEntityListContext(UnifiedActionContext $context): string
    {
        $payload = $this->buildContextPayloadFromLastEntityList($context);
        if (is_array($payload)) {
            return json_encode($payload, JSON_PRETTY_PRINT);
        }

        $payload = $this->buildContextPayloadFromConversation($context);
        if (is_array($payload)) {
            return json_encode($payload, JSON_PRETTY_PRINT);
        }

        $selected = $this->getSelectedEntityContext($context);
        if (is_array($selected) && !empty($selected['entity_id'])) {
            return json_encode([
                'entity_type' => $selected['entity_type'] ?? 'item',
                'count' => 1,
                'selected_entity' => $selected,
            ], JSON_PRETTY_PRINT);
        }

        return '(none)';
    }

    protected function buildContextPayloadFromLastEntityList(UnifiedActionContext $context): ?array
    {
        $lastEntityList = $context->metadata['last_entity_list'] ?? null;
        if (!is_array($lastEntityList)) {
            return null;
        }

        $entityIds = array_values(array_filter((array) ($lastEntityList['entity_ids'] ?? []), fn ($id) => $id !== null && $id !== ''));
        $entityData = array_values((array) ($lastEntityList['entity_data'] ?? []));
        if (empty($entityIds) && empty($entityData)) {
            return null;
        }

        $payload = [
            'entity_type' => $lastEntityList['entity_type'] ?? 'item',
            'count' => !empty($entityIds) ? count($entityIds) : count($entityData),
        ];

        if (!empty($entityIds)) {
            $payload['entity_ids_preview'] = array_slice($entityIds, 0, 10);
        }

        if (!empty($entityData)) {
            $payload['entity_data_preview'] = array_slice($entityData, 0, 5);
        }

        if (isset($lastEntityList['start_position'])) {
            $payload['start_position'] = (int) $lastEntityList['start_position'];
        }
        if (isset($lastEntityList['end_position'])) {
            $payload['end_position'] = (int) $lastEntityList['end_position'];
        }

        return $payload;
    }

    protected function buildContextPayloadFromConversation(UnifiedActionContext $context): ?array
    {
        $messages = array_reverse($context->conversationHistory ?? []);
        foreach ($messages as $msg) {
            if (($msg['role'] ?? null) !== 'assistant') {
                continue;
            }

            $entityIds = $msg['metadata']['entity_ids'] ?? [];
            if (empty($entityIds) || !is_array($entityIds)) {
                continue;
            }

            return [
                'entity_type' => $msg['metadata']['entity_type'] ?? 'item',
                'count' => count($entityIds),
                'entity_ids_preview' => array_slice(array_values($entityIds), 0, 10),
            ];
        }

        return null;
    }

    public function resolveModelClass(string $entityType, UnifiedActionContext $context): ?string
    {
        $trimmedType = trim($entityType);
        if ($trimmedType === '') {
            return null;
        }

        if (str_contains($trimmedType, '\\') && class_exists($trimmedType)) {
            return $trimmedType;
        }

        $normalizedType = strtolower($trimmedType);

        $selectedContext = $context->metadata['selected_entity_context'] ?? [];
        if (is_array($selectedContext) && ($selectedContext['entity_type'] ?? null) !== null) {
            $selectedType = strtolower((string) ($selectedContext['entity_type'] ?? ''));
            $selectedClass = $selectedContext['model_class'] ?? null;
            if ($selectedType === $normalizedType && is_string($selectedClass) && class_exists($selectedClass)) {
                return $selectedClass;
            }
        }

        $legacy = $context->metadata['last_selected_option'] ?? [];
        if (is_array($legacy) && ($legacy['entity_type'] ?? null) !== null) {
            $legacyType = strtolower((string) ($legacy['entity_type'] ?? ''));
            $legacyClass = $legacy['model_class'] ?? null;
            if ($legacyType === $normalizedType && is_string($legacyClass) && class_exists($legacyClass)) {
                return $legacyClass;
            }
        }

        $entityMap = $this->getEntityModelMap();
        $mappedClass = $entityMap[$normalizedType] ?? null;
        if (is_string($mappedClass) && class_exists($mappedClass)) {
            return $mappedClass;
        }

        return null;
    }

    public function getSelectedEntityContext(UnifiedActionContext $context): ?array
    {
        $selected = $context->metadata['selected_entity_context'] ?? null;
        if (is_array($selected) && !empty($selected['entity_id'])) {
            return $selected;
        }

        $legacy = $context->metadata['last_selected_option'] ?? null;
        if (is_array($legacy) && !empty($legacy['entity_id'])) {
            return [
                'entity_id' => (int) $legacy['entity_id'],
                'entity_type' => $legacy['entity_type'] ?? null,
                'model_class' => $legacy['model_class'] ?? null,
                'source_node' => $legacy['source_node'] ?? null,
                'selected_via' => 'numbered_option',
            ];
        }

        return null;
    }

    protected function getEntityModelMap(): array
    {
        if (!empty($this->entityModelMap)) {
            return $this->entityModelMap;
        }

        $defaultMap = [];

        try {
            $agentMap = config('ai-agent.entity_model_map', []);
            $engineMap = config('ai-engine.entity_model_map', []);
            return array_merge($defaultMap, is_array($engineMap) ? $engineMap : [], is_array($agentMap) ? $agentMap : []);
        } catch (\Throwable $e) {
            return $defaultMap;
        }
    }
}
