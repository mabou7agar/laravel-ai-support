<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\WebsiteGenerationRequest;
use LaravelAIEngine\Services\Design\WebsiteBuilderService;

/**
 * Agent tool that generates a complete website grounded in the package's
 * design-intelligence knowledge base.
 */
class GenerateWebsiteTool extends SimpleAgentTool
{
    public string $name = 'generate_website';

    public string $description = 'Generate a complete, accessible, on-brand website or landing page from a description. '
        . 'Automatically resolves a grounded design system (layout, style, color tokens, fonts) and outputs production code '
        . 'for the requested stack (html, react, next, vue, svelte).';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $parameters = [
        'prompt' => [
            'type' => 'string',
            'required' => true,
            'description' => 'What the website is for (product, audience, purpose, any style hints).',
        ],
        'stack' => [
            'type' => 'string',
            'required' => false,
            'enum' => WebsiteGenerationRequest::SUPPORTED_STACKS,
            'description' => 'Target stack. Defaults to html.',
        ],
        'project_name' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Optional project name used in the design system.',
        ],
        'page' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Optional specific page to build (e.g. landing, pricing, dashboard).',
        ],
    ];

    /**
     * @var array<int, string>
     */
    public array $capabilities = ['website.create', 'design.generate'];

    public ?string $entityType = 'website';

    public bool $requiresConfirmation = false;

    public function __construct(
        private readonly WebsiteBuilderService $builder,
    ) {}

    protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
    {
        if (!(bool) config('ai-engine.design.enabled', true)) {
            return ActionResult::failure('Website generation is disabled in configuration.');
        }

        $prompt = trim((string) ($parameters['prompt'] ?? ''));
        if ($prompt === '') {
            return ActionResult::failure('A website prompt is required.');
        }

        try {
            $request = WebsiteGenerationRequest::fromArray($parameters, $context->userId !== null ? (string) $context->userId : null);
            $result = $this->builder->build($request);
        } catch (\Throwable $e) {
            return ActionResult::failure('Website generation failed: ' . $e->getMessage());
        }

        $issues = $result->qualityReview['remaining_issues'] ?? [];
        $message = sprintf(
            'Generated a %s %s using the "%s" style (%s). %s',
            $result->designSystem->category,
            $result->stack,
            $result->designSystem->style['name'],
            $result->format,
            $issues === [] ? 'Passed all quality checks.' : (count($issues) . ' quality note(s) remain.')
        );

        return ActionResult::success($message, [
            'content' => $result->content,
            'stack' => $result->stack,
            'format' => $result->format,
            'design_system' => $result->designSystem->toArray(),
            'quality_review' => $result->qualityReview,
        ], [
            'engine' => $result->engine,
            'model' => $result->model,
            'tokens_used' => $result->tokensUsed,
        ]);
    }
}
