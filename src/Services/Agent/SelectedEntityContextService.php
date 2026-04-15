<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;

class SelectedEntityContextService
{
    public function getFromContext(UnifiedActionContext $context): ?array
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

    public function bindToToolParams(
        string $toolName,
        array $params,
        ?array $selectedEntity,
        array $toolSchema = []
    ): array {
        if (!$selectedEntity || empty($selectedEntity['entity_id'])) {
            return $params;
        }

        $selectedId = (int) $selectedEntity['entity_id'];
        if ($selectedId <= 0) {
            return $params;
        }

        $singleIdKey = $this->findSingleEntityIdParamKey($toolSchema);
        if ($singleIdKey && ($params[$singleIdKey] ?? null) !== $selectedId) {
            $params[$singleIdKey] = $selectedId;
        }

        if (isset($toolSchema['email_ids'])) {
            $existing = $params['email_ids'] ?? [];
            if (!is_array($existing) || $existing !== [$selectedId]) {
                $params['email_ids'] = [$selectedId];
            }
        }

        if (!empty($selectedEntity['suggested_action_content'])) {
            $params['suggested_action_content'] = $selectedEntity['suggested_action_content'];
        }

        if (!empty($selectedEntity['entity_data'])) {
            $params['entity_data'] = $selectedEntity['entity_data'];
        }

        if (!empty($selectedEntity['entity_ref']) && is_array($selectedEntity['entity_ref'])) {
            $params['entity_ref'] = $selectedEntity['entity_ref'];
        }

        if (!empty($selectedEntity['object']) && is_array($selectedEntity['object'])) {
            $params['object'] = $selectedEntity['object'];
        }

        return $params;
    }

    protected function findSingleEntityIdParamKey(array $toolSchema): ?string
    {
        if (empty($toolSchema)) {
            return null;
        }

        $excludedKeys = ['user_id', 'mailbox_id', 'session_id', 'node_id'];
        $candidates = [];

        foreach (array_keys($toolSchema) as $key) {
            if (!is_string($key) || in_array($key, $excludedKeys, true)) {
                continue;
            }

            if ($key === 'id' || str_ends_with($key, '_id')) {
                $candidates[] = $key;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if (in_array('email_id', $candidates, true)) {
            return 'email_id';
        }

        if (in_array('id', $candidates, true)) {
            return 'id';
        }

        return null;
    }
}
