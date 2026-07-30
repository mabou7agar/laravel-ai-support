<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\Contracts\ConversationEntityMemory;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AssistantResourceItem;
use LaravelAIEngine\DTOs\AssistantResourceQuery;
use LaravelAIEngine\DTOs\ConversationEntityReference;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Assistant\AssistantResourceRegistry;

final class SearchAssistantResourcesTool extends SimpleAgentTool
{
    public string $name = 'search_assistant_resources';

    public string $description = 'Search host-application resources through policy-scoped providers and return structured UI data such as cards, carousels, metrics, and source links. Use for courses, lessons, paths, bundles, events, orders, revenue reports, and other structured domain records. The host application controls available resource types and access.';

    /** @var array<string, array<string, mixed>> */
    public array $parameters = [
        'query' => ['type' => 'string', 'required' => true, 'description' => 'The user request or semantic search query.'],
        'type' => ['type' => 'string', 'required' => false, 'description' => 'Optional resource type when the user explicitly names one. Do not guess a type when the request spans several resources.'],
        'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Maximum number of structured items to return (1-50).'],
    ];

    /** @var array<int, string> */
    public array $capabilities = ['search', 'semantic_retrieval', 'structured_response'];

    public ?string $toolKind = 'read';

    public function __construct(
        private readonly AssistantResourceRegistry $resources,
        private readonly ConversationEntityMemory $entityMemory,
    ) {
    }

    protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $query = trim((string) ($parameters['query'] ?? ''));
        if ($query === '') {
            return ActionResult::failure('A non-empty query is required.');
        }

        $scope = array_filter([
            'user_id' => $context->userId,
            'tenant_id' => $context->metadata['tenant_id'] ?? null,
            'workspace_id' => $context->metadata['workspace_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $recent = array_map(
            static fn (ConversationEntityReference $reference): array => $reference->toArray(),
            $this->entityMemory->recent($context->sessionId, scope: $scope),
        );

        $result = $this->resources->search(new AssistantResourceQuery(
            query: $query,
            type: $this->nullableString($parameters['type'] ?? null),
            limit: max(1, min(50, (int) ($parameters['limit'] ?? 8))),
            locale: $this->nullableString($context->metadata['locale'] ?? app()->getLocale()),
            scope: $scope,
            context: [
                'recent_entities' => $recent,
                'conversation_history' => array_slice($context->conversationHistory, -6),
            ],
        ));

        foreach (array_reverse($result->items) as $item) {
            $this->remember($context, $item, $scope);
        }

        $data = $result->toArray();
        $count = count($result->items);

        return ActionResult::success(
            $result->message ?? ($count > 0
                ? sprintf('Found %d relevant resource%s.', $count, $count === 1 ? '' : 's')
                : 'No matching resources were found.'),
            $data,
            ['presentation' => $this->presentation($result->items, $result->metrics)],
        );
    }

    /** @param array<string, mixed> $scope */
    private function remember(UnifiedActionContext $context, AssistantResourceItem $item, array $scope): void
    {
        $this->entityMemory->remember(
            $context->sessionId,
            new ConversationEntityReference(
                type: $item->type,
                id: $item->id,
                label: $item->title,
                visibility: $item->visibility,
                tenantId: $item->tenantId,
                workspaceId: $item->workspaceId,
                url: $item->url,
                metadata: $item->metadata,
            ),
            $scope,
        );
    }

    /** @param list<AssistantResourceItem> $items @param list<array<string, mixed>> $metrics */
    private function presentation(array $items, array $metrics): string
    {
        if ($metrics !== [] && $items === []) {
            return 'metrics';
        }

        return count($items) > 1 ? 'carousel' : 'cards';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
