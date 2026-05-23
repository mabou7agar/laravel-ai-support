<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Learning\LearningService;

class SearchLearnedContextTool extends SimpleAgentTool
{
    public string $name = 'search_learned_context';

    public string $description = 'Search scoped learned examples, design rules, workflow rules, and reusable guidance.';

    public array $parameters = [
        'query' => ['type' => 'string', 'required' => true, 'description' => 'What to search for in learned context.'],
        'type' => ['type' => 'string', 'required' => false, 'description' => 'Optional learned knowledge type such as design, workflow, or reply_style.'],
        'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Maximum number of learned items to return.'],
    ];

    public function __construct(protected LearningService $learning) {}

    protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $query = (string) $parameters['query'];
        $limit = max(1, min(20, (int) ($parameters['limit'] ?? 5)));
        $type = isset($parameters['type']) && is_scalar($parameters['type']) ? (string) $parameters['type'] : null;
        $matches = [];

        foreach ($this->scopeCandidates($context) as $scope) {
            $matches = $this->learning->search(
                query: $query,
                scope: $scope,
                limit: $limit,
                type: $type,
            );

            if ($matches !== []) {
                break;
            }
        }

        return ActionResult::success('Learned context loaded.', array_map(static fn ($match): array => [
            'score' => $match->score,
            'reason' => $match->reason,
            'source' => [
                'source_id' => $match->source->sourceId,
                'type' => $match->source->type,
                'title' => $match->source->title,
                'adapter' => $match->source->adapter,
            ],
            'item' => [
                'item_id' => $match->item->itemId,
                'kind' => $match->item->kind,
                'title' => $match->item->title,
                'content' => $match->item->content,
            ],
        ], $matches));
    }

    protected function scopeFromContext(UnifiedActionContext $context): array
    {
        return [
            'user_id' => $context->userId,
            'tenant_id' => $context->metadata['tenant_id'] ?? null,
            'workspace_id' => $context->metadata['workspace_id'] ?? null,
            'session_id' => $context->sessionId,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function scopeCandidates(UnifiedActionContext $context): array
    {
        $scope = $this->scopeFromContext($context);
        $candidates = [$scope];

        if (($scope['session_id'] ?? null) !== null && $scope['session_id'] !== '') {
            $withoutSession = $scope;
            unset($withoutSession['session_id']);
            $candidates[] = $withoutSession;
        }

        if (($scope['user_id'] ?? null) !== null && $scope['user_id'] !== '') {
            $withoutUser = $scope;
            unset($withoutUser['user_id'], $withoutUser['session_id']);
            $candidates[] = $withoutUser;
        }

        $workspaceScope = array_filter([
            'tenant_id' => $scope['tenant_id'] ?? null,
            'workspace_id' => $scope['workspace_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($workspaceScope !== []) {
            $candidates[] = $workspaceScope;
        }

        return array_values(array_unique($candidates, SORT_REGULAR));
    }
}
