<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;

class RoutingContextResolver
{
    public function __construct(
        protected ?SelectedEntityContextService $selectedEntityContext = null
    ) {
        $this->selectedEntityContext ??= app()->bound(SelectedEntityContextService::class)
            ? app(SelectedEntityContextService::class)
            : new SelectedEntityContextService();
    }

    /**
     * @return array{selected_entity:?array,last_entity_list:?array,rag_collections:array<int, string>}
     */
    public function signalsFromContext(UnifiedActionContext $context, array $options = []): array
    {
        $selected = $this->selectedEntityContext->getFromContext($context);
        $lastEntityList = is_array($context->metadata['last_entity_list'] ?? null)
            ? $context->metadata['last_entity_list']
            : null;

        if ($lastEntityList === null && is_array($options['last_entity_list'] ?? null)) {
            $lastEntityList = $options['last_entity_list'];
        }

        return [
            'selected_entity' => $selected,
            'last_entity_list' => $lastEntityList,
            'rag_collections' => array_values(array_filter(
                (array) ($options['rag_collections'] ?? []),
                static fn (mixed $collection): bool => is_string($collection) && trim($collection) !== ''
            )),
        ];
    }

    public function mergeConversationContext(UnifiedActionContext $context, array $options = []): array
    {
        $signals = $this->signalsFromContext($context, $options);

        if (!isset($options['selected_entity']) && $signals['selected_entity'] !== null) {
            $options['selected_entity'] = $signals['selected_entity'];
        }

        if (!isset($options['selected_entity_context']) && $signals['selected_entity'] !== null) {
            $options['selected_entity_context'] = $signals['selected_entity'];
        }

        if (!isset($options['last_entity_list']) && $signals['last_entity_list'] !== null) {
            $options['last_entity_list'] = $signals['last_entity_list'];
        }

        if (!isset($options['conversation_summary']) && is_string($context->metadata['conversation_summary'] ?? null)) {
            $summary = trim($context->metadata['conversation_summary']);
            if ($summary !== '') {
                $options['conversation_summary'] = $summary;
            }
        }

        return $options;
    }
}
