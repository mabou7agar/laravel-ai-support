<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Learning\LearningService;

class LearnSourceTool extends SimpleAgentTool
{
    public string $name = 'learn_source';

    public string $description = 'Learn reusable knowledge from explicit text, file, URL, or getdesign design slug.';

    public array $parameters = [
        'source' => ['type' => 'string', 'required' => true, 'description' => 'Text, URL, file path, or getdesign slug.'],
        'source_type' => ['type' => 'string', 'required' => true, 'description' => 'text, file, url, or getdesign_slug.'],
        'type' => ['type' => 'string', 'required' => false, 'description' => 'Knowledge type such as design, workflow, reply_style, or api_docs.'],
        'adapter' => ['type' => 'string', 'required' => false, 'description' => 'Optional adapter such as getdesign.'],
        'title' => ['type' => 'string', 'required' => false, 'description' => 'Optional source title.'],
        'index' => ['type' => 'boolean', 'required' => false, 'description' => 'Whether to index into the vector store registry.'],
    ];

    public bool $requiresConfirmation = true;

    public ?string $confirmationMessage = 'Learning from external or user-provided sources can persist project knowledge. Confirm before importing.';

    public function __construct(protected LearningService $learning) {}

    protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $sourceType = (string) $parameters['source_type'];
        $adapter = isset($parameters['adapter']) && is_scalar($parameters['adapter'])
            ? (string) $parameters['adapter']
            : null;

        if ($sourceType === 'getdesign_slug') {
            $adapter = $adapter ?: 'getdesign';
        }

        $result = $this->learning->ingest(new LearningSourceRequest(
            sourceType: $sourceType,
            source: (string) $parameters['source'],
            type: isset($parameters['type']) && is_scalar($parameters['type']) ? (string) $parameters['type'] : 'general',
            title: isset($parameters['title']) && is_scalar($parameters['title']) ? (string) $parameters['title'] : null,
            adapter: $adapter,
            userId: $context->userId,
            tenantId: $context->metadata['tenant_id'] ?? null,
            workspaceId: $context->metadata['workspace_id'] ?? null,
            sessionId: $context->sessionId,
            shouldIndex: (bool) ($parameters['index'] ?? false),
        ));

        return ActionResult::success('Learned source saved.', $result->toArray());
    }
}
