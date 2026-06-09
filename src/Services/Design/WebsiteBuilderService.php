<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Design;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\DesignSystem;
use LaravelAIEngine\DTOs\WebsiteGenerationRequest;
use LaravelAIEngine\DTOs\WebsiteGenerationResult;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\CreditManager;

/**
 * Design-intelligence-grounded website builder.
 *
 * Pipeline: resolve a design system from the bundled knowledge base → compose a
 * grounded, stack-aware prompt → generate via the configured LLM → normalize →
 * self-correcting quality-control pass → optional multi-page persistence.
 *
 * Unlike {@see \LaravelAIEngine\Services\Learning\LearnedDesignGeneratorService}
 * (which requires previously-learned design sources), this works out of the box
 * from the package's built-in design intelligence.
 */
class WebsiteBuilderService
{
    public function __construct(
        private readonly DesignSystemResolver $resolver,
        private readonly WebsitePromptComposer $composer,
        private readonly WebsiteQualityReviewer $reviewer,
        private readonly DesignSystemPersister $persister,
        private readonly AIEngineService $ai,
        private readonly CreditManager $credits,
    ) {}

    public function build(WebsiteGenerationRequest $request): WebsiteGenerationResult
    {
        if (trim($request->prompt) === '') {
            throw new \InvalidArgumentException('A website prompt is required.');
        }

        $designSystem = $request->designSystem
            ?? $this->resolver->resolve($this->resolverQuery($request), $request->resolvedProjectName());

        $engine = $request->engine ?? $this->defaultEngine();
        $model = $request->model ?? $this->defaultModel();

        $aiRequest = new AIRequest(
            prompt: $this->composePrompt($request, $designSystem),
            engine: $engine,
            model: $model,
            userId: $request->userId,
            maxTokens: $request->maxTokens ?? (int) config('ai-engine.design.max_tokens', 8000),
            temperature: $request->temperature,
            metadata: array_replace($request->metadata, [
                'website_generation' => true,
                'stack' => $request->stack,
                'mode' => $request->isModification() ? 'modify' : 'create',
                'design_category' => $designSystem->category,
                'design_style' => $designSystem->style['name'],
            ]),
        );

        // Flat per-website surcharge for the build operation, on top of the
        // per-token model credits AIEngineService deducts during generation.
        // Check availability up-front, charge only after a successful build.
        $surcharge = $this->websiteSurcharge();
        $chargeSurcharge = $surcharge > 0.0 && $this->creditsActive($request->userId);
        if ($chargeSurcharge && !$this->credits->hasCreditsForAmount((string) $request->userId, $aiRequest, $surcharge)) {
            throw new InsufficientCreditsException('Insufficient credits to generate a website.');
        }

        $response = $this->ai->generate($aiRequest);
        if (!$response->isSuccessful()) {
            throw new \RuntimeException($response->getError() ?: 'Website generation failed.');
        }

        $content = $this->normalize($response->getContent(), $request);

        $review = $this->reviewer->reviewAndFix($content, $request, $designSystem);
        $content = $review['fixed'] ? $this->normalize($review['content'], $request) : $content;

        $surchargeCharged = 0.0;
        if ($chargeSurcharge) {
            $this->credits->deductCredits((string) $request->userId, $aiRequest, $surcharge);
            $surchargeCharged = $surcharge;
        }

        if ($request->persist) {
            $this->persister->persist($designSystem, $request->page);
        }

        return new WebsiteGenerationResult(
            content: $content,
            stack: $request->stack,
            format: $request->isHtmlDocument() ? 'html' : 'code',
            designSystem: $designSystem,
            engine: $response->getEngine()->value,
            model: $response->getModel()->value,
            tokensUsed: $response->getTokensUsed(),
            creditsUsed: $response->getCreditsUsed() + $surchargeCharged,
            qualityReview: [
                'enabled' => $request->qualityReview,
                'issues_found' => $review['issues'],
                'auto_fixed' => $review['fixed'],
                'passes' => $review['passes'],
                'remaining_issues' => $review['remaining_issues'],
            ],
            metadata: [
                'prompt' => $request->prompt,
                'project_name' => $designSystem->projectName,
                'page' => $request->page,
                'mode' => $request->isModification() ? 'modify' : 'create',
                'persisted' => $request->persist,
                'website_credit_cost' => $surchargeCharged,
            ],
        );
    }

    private function websiteSurcharge(): float
    {
        return max(0.0, (float) config('ai-engine.design.credit_cost', 0.0));
    }

    private function creditsActive(?string $userId): bool
    {
        return $userId !== null
            && $userId !== ''
            && (bool) config('ai-engine.credits.enabled', false);
    }

    /**
     * Stream generation as a sequence of events:
     *   design_system → content (deltas) → quality_review → done
     *
     * The QC auto-fix pass is disabled while streaming (bytes are already on the
     * wire); the deterministic review is reported in the quality_review/done
     * events so a client can choose to request a follow-up fix.
     *
     * @return \Generator<int, array{event: string, data: array<string, mixed>}>
     */
    public function stream(WebsiteGenerationRequest $request): \Generator
    {
        if (trim($request->prompt) === '') {
            throw new \InvalidArgumentException('A website prompt is required.');
        }

        $designSystem = $request->designSystem
            ?? $this->resolver->resolve($this->resolverQuery($request), $request->resolvedProjectName());

        $aiRequest = new AIRequest(
            prompt: $this->composePrompt($request, $designSystem),
            engine: $request->engine ?? $this->defaultEngine(),
            model: $request->model ?? $this->defaultModel(),
            userId: $request->userId,
            stream: true,
            maxTokens: $request->maxTokens ?? (int) config('ai-engine.design.max_tokens', 8000),
            temperature: $request->temperature,
            metadata: array_replace($request->metadata, [
                'website_generation' => true,
                'stack' => $request->stack,
                'design_category' => $designSystem->category,
                'design_style' => $designSystem->style['name'],
                'streaming' => true,
            ]),
        );

        $surcharge = $this->websiteSurcharge();
        $chargeSurcharge = $surcharge > 0.0 && $this->creditsActive($request->userId);
        if ($chargeSurcharge && !$this->credits->hasCreditsForAmount((string) $request->userId, $aiRequest, $surcharge)) {
            throw new InsufficientCreditsException('Insufficient credits to generate a website.');
        }

        yield ['event' => 'design_system', 'data' => $designSystem->toArray()];

        $raw = '';
        foreach ($this->ai->stream($aiRequest) as $chunk) {
            $chunk = (string) $chunk;
            if ($chunk === '') {
                continue;
            }
            $raw .= $chunk;
            yield ['event' => 'content', 'data' => ['delta' => $chunk]];
        }

        $content = $this->normalize($raw, $request);
        $issues = $this->reviewer->review($content, $request, $designSystem);

        $surchargeCharged = 0.0;
        if ($chargeSurcharge) {
            $this->credits->deductCredits((string) $request->userId, $aiRequest, $surcharge);
            $surchargeCharged = $surcharge;
        }

        if ($request->persist) {
            $this->persister->persist($designSystem, $request->page);
        }

        $quality = [
            'enabled' => $request->qualityReview,
            'issues_found' => $issues,
            'auto_fixed' => false,
            'remaining_issues' => $issues,
            'note' => 'Auto-fix is disabled during streaming; issues are reported for an optional follow-up fix.',
        ];

        yield ['event' => 'quality_review', 'data' => $quality];

        $result = new WebsiteGenerationResult(
            content: $content,
            stack: $request->stack,
            format: $request->isHtmlDocument() ? 'html' : 'code',
            designSystem: $designSystem,
            engine: $aiRequest->getEngine()->value,
            model: $aiRequest->getModel()->value,
            tokensUsed: 0,
            creditsUsed: $surchargeCharged,
            qualityReview: $quality,
            metadata: [
                'prompt' => $request->prompt,
                'project_name' => $designSystem->projectName,
                'page' => $request->page,
                'persisted' => $request->persist,
                'streamed' => true,
                'website_credit_cost' => $surchargeCharged,
            ],
        );

        yield ['event' => 'done', 'data' => $result->toArray()];
    }

    /**
     * Resolve only the design system, without generating any code. Useful for
     * previews and for seeding a multi-page project's MASTER file.
     */
    public function resolveDesignSystem(WebsiteGenerationRequest $request): DesignSystem
    {
        return $request->designSystem
            ?? $this->resolver->resolve($this->resolverQuery($request), $request->resolvedProjectName());
    }

    private function composePrompt(WebsiteGenerationRequest $request, DesignSystem $designSystem): string
    {
        return $request->isModification()
            ? $this->composer->composeModification($request, $designSystem, (string) $request->baseContent)
            : $this->composer->compose($request, $designSystem);
    }

    private function resolverQuery(WebsiteGenerationRequest $request): string
    {
        $parts = [$request->prompt];
        if ($request->page !== null && trim($request->page) !== '') {
            $parts[] = $request->page;
        }

        return trim(implode(' ', $parts));
    }

    private function normalize(string $content, WebsiteGenerationRequest $request): string
    {
        $content = $this->stripMarkdownFence($content);

        if ($request->isHtmlDocument()) {
            $content = $this->normalizeHtml($content);
        }

        return trim($content);
    }

    private function stripMarkdownFence(string $content): string
    {
        $content = trim($content);

        if (preg_match('/^```[a-z]*\s*(.*?)\s*```$/is', $content, $matches) === 1) {
            return trim($matches[1]);
        }

        return $content;
    }

    private function normalizeHtml(string $content): string
    {
        $content = trim($content);

        if (!str_contains(strtolower($content), '<html')) {
            $content = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
                . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>Website</title>\n"
                . "</head>\n<body>\n{$content}\n</body>\n</html>";
        }

        if (!str_starts_with(strtolower(ltrim($content)), '<!doctype')) {
            $content = "<!doctype html>\n" . $content;
        }

        return $content;
    }

    private function defaultEngine(): ?string
    {
        $engine = config('ai-engine.design.default_engine');

        return is_string($engine) && $engine !== '' ? $engine : null;
    }

    private function defaultModel(): ?string
    {
        $model = config('ai-engine.design.default_model');

        return is_string($model) && $model !== '' ? $model : null;
    }
}
