<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Stringable;

trait ExtractsConversationContextPayload
{
    protected function extractConversationContextPayload(array $metadata): array
    {
        $selected = is_array($metadata['metadata']['selected_entity_context'] ?? null)
            ? $metadata['metadata']['selected_entity_context']
            : [];
        $lastList = is_array($metadata['metadata']['last_entity_list'] ?? null)
            ? $metadata['metadata']['last_entity_list']
            : [];
        $createdFallback = $this->buildCreatedFocusPayload($metadata);
        $draftFallback = $this->buildDraftFocusPayload($metadata);

        $focusedEntity = $this->buildFocusedEntityPayload($selected);
        if ($focusedEntity === []) {
            $focusedEntity = $draftFallback;
        }
        if ($focusedEntity === []) {
            $focusedEntity = $createdFallback;
        }

        $conversationAbout = $this->buildConversationAboutPayload($selected, $lastList, $createdFallback, $draftFallback);

        return array_filter([
            ...$focusedEntity,
            ...$conversationAbout,
        ], static fn ($value) => $value !== null && $value !== []);
    }

    protected function normalizeEntityValue(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeEntityValue($item), $value);
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }

    protected function removeIdentifierFields(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $this->removeIdentifierFields($value->toArray());
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->removeIdentifierFields($value->toArray());
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && ($key === 'id' || str_ends_with($key, '_id'))) {
                    continue;
                }

                $result[$key] = $this->removeIdentifierFields($item);
            }

            return $result;
        }

        return $value;
    }

    protected function buildFocusedEntityPayload(array $selected): array
    {
        if ($selected === []) {
            return [];
        }

        $entity = $this->normalizeEntityValue($selected['object'] ?? ($selected['entity_data'] ?? null));
        $entityId = $selected['entity_id'] ?? null;
        $entityType = $selected['entity_type'] ?? null;
        $entityRef = is_array($selected['entity_ref'] ?? null) ? $selected['entity_ref'] : null;

        if ($entity === null && $entityId === null) {
            return [];
        }

        return array_filter([
            'focused_entity' => $entity,
            'focused_entity_id' => $entityId,
            'focused_entity_type' => $entityType,
            'focused_entity_ref' => $entityRef,
        ], static fn ($value) => $value !== null);
    }

    protected function buildConversationAboutPayload(array $selected, array $lastList, array $createdFallback, array $draftFallback): array
    {
        if ($selected !== []) {
            return array_filter([
                'conversation_about' => [
                    'type' => $selected['entity_type'] ?? null,
                    'id' => $selected['entity_id'] ?? null,
                    'source_node' => $selected['source_node'] ?? null,
                    'selected_via' => $selected['selected_via'] ?? null,
                    'entity_ref' => is_array($selected['entity_ref'] ?? null) ? $selected['entity_ref'] : null,
                ],
            ], static fn ($value) => $value !== null && $value !== []);
        }

        if ($lastList !== []) {
            return [
                'conversation_about' => array_filter([
                    'type' => $lastList['entity_type'] ?? null,
                    'entity_ids' => $lastList['entity_ids'] ?? [],
                    'entity_refs' => $lastList['entity_refs'] ?? [],
                    'start_position' => $lastList['start_position'] ?? null,
                    'end_position' => $lastList['end_position'] ?? null,
                ], static fn ($value) => $value !== null && $value !== []),
            ];
        }

        if ($draftFallback !== []) {
            return [
                'conversation_about' => array_filter([
                    'type' => $draftFallback['focused_entity_type'] ?? null,
                    'selected_via' => 'draft_summary',
                ], static fn ($value) => $value !== null),
            ];
        }

        if ($createdFallback !== []) {
            return [
                'conversation_about' => array_filter([
                    'type' => $createdFallback['focused_entity_type'] ?? null,
                    'id' => $createdFallback['focused_entity_id'] ?? null,
                    'selected_via' => 'runtime_result',
                ], static fn ($value) => $value !== null),
            ];
        }

        return [];
    }

    protected function buildDraftFocusPayload(array $metadata): array
    {
        $runtimeData = is_array($metadata['runtime_data'] ?? null) ? $metadata['runtime_data'] : [];
        $draft = $runtimeData['collected_data'] ?? null;

        if (!is_array($draft) || $draft === []) {
            return [];
        }

        $sanitizedDraft = $this->removeIdentifierFields($draft);
        if (!is_array($sanitizedDraft) || $sanitizedDraft === []) {
            return [];
        }

        return array_filter([
            'focused_entity' => $sanitizedDraft,
            'focused_entity_type' => $this->inferFlowEntityType($metadata),
        ], static fn ($value) => $value !== null && $value !== []);
    }

    protected function buildCreatedFocusPayload(array $metadata): array
    {
        $runtimeData = is_array($metadata['runtime_data'] ?? null) ? $metadata['runtime_data'] : [];
        $entity = $runtimeData['entity'] ?? ($metadata['created_entity'] ?? null);
        $entityId = $runtimeData['entity_id'] ?? ($metadata['created_entity_id'] ?? null);
        $entityType = is_string($metadata['created_entity_type'] ?? null) ? $metadata['created_entity_type'] : null;

        if ($entity === null) {
            foreach ($runtimeData as $key => $value) {
                if ($key === 'entity' || str_ends_with((string) $key, '_id')) {
                    continue;
                }

                if (is_array($value) || is_object($value)) {
                    $entity = $value;
                    $entityType ??= (string) $key;
                    break;
                }
            }
        }

        if ($entityId === null) {
            foreach ($runtimeData as $key => $value) {
                if (($key === 'entity_id' || str_ends_with((string) $key, '_id')) && (is_scalar($value) || $value instanceof Stringable)) {
                    $entityId = $value;
                    if ($entityType === null && $key !== 'entity_id') {
                        $entityType = substr((string) $key, 0, -3);
                    }
                    break;
                }
            }
        }

        $normalizedEntity = $this->normalizeEntityValue($entity);
        if ($normalizedEntity === null && $entityId === null) {
            return [];
        }

        if ($entityType === null && $entity instanceof Model) {
            $entityType = class_basename($entity);
        }

        return array_filter([
            'focused_entity' => $normalizedEntity,
            'focused_entity_id' => $entityId,
            'focused_entity_type' => $entityType,
        ], static fn ($value) => $value !== null);
    }

    protected function inferFlowEntityType(array $metadata): ?string
    {
        $flowName = $metadata['flow_name']
            ?? $metadata['current_flow']
            ?? ($metadata['metadata']['current_flow'] ?? null);

        if (!is_string($flowName) || trim($flowName) === '') {
            return null;
        }

        $base = class_basename($flowName);
        $base = preg_replace('/Flow$/', '', $base) ?? $base;
        $base = preg_replace('/^(Create|Update|Delete|Manage|Review|Confirm)/', '', $base) ?? $base;
        $base = trim((string) $base);

        return $base !== '' ? Str::snake($base) : null;
    }

}
