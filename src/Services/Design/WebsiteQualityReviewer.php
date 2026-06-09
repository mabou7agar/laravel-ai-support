<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Design;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\DesignSystem;
use LaravelAIEngine\DTOs\WebsiteGenerationRequest;
use LaravelAIEngine\Services\AIEngineService;

/**
 * Self-correcting quality control. Runs deterministic checks derived from the
 * UI/UX guideline set against generated output, and — when issues are found —
 * asks the model to fix exactly those issues.
 */
class WebsiteQualityReviewer
{
    public function __construct(
        private readonly AIEngineService $ai,
        private readonly WebsitePromptComposer $composer,
    ) {}

    /**
     * Deterministic checks. Returns a list of human-readable issues (empty = clean).
     *
     * @return array<int, string>
     */
    public function review(string $content, WebsiteGenerationRequest $request, DesignSystem $designSystem): array
    {
        $issues = [];
        $lower = mb_strtolower($content);

        if ($this->containsEmoji($content)) {
            $issues[] = 'Emoji detected in output — replace any emoji used as an icon with SVG icons.';
        }

        // Token/font grounding only applies to fresh generation. When editing an
        // existing document the base content owns the palette/fonts, so a freshly
        // resolved design system must not be enforced against it.
        if (!$request->isModification()) {
            $primary = mb_strtolower($designSystem->colors['primary'] ?? '');
            if ($primary !== '' && !str_contains($lower, $primary)) {
                $issues[] = "Design system primary color ({$designSystem->colors['primary']}) is not used — apply the provided color tokens.";
            }

            $heading = trim($designSystem->typography['heading'] ?? '');
            if ($heading !== '' && $heading !== 'Inter' && !str_contains($lower, mb_strtolower($heading))) {
                $issues[] = "Heading font \"{$heading}\" is not referenced — load and apply the design system fonts.";
            }
        }

        $hasMotion = str_contains($lower, 'transition') || str_contains($lower, 'animation') || str_contains($lower, '@keyframes');
        if ($hasMotion && !str_contains($lower, 'prefers-reduced-motion')) {
            $issues[] = 'Animations/transitions present but prefers-reduced-motion is not handled.';
        }

        if ($request->isHtmlDocument()) {
            $issues = array_merge($issues, $this->htmlChecks($lower));
        }

        // Asset portability applies to any stack: a generated artifact should not
        // depend on external/relative image URLs the model invented (they 404).
        $issues = array_merge($issues, $this->assetChecks($content));

        return array_values($issues);
    }

    /**
     * Flag <img> elements that are not inlined. The builder produces standalone
     * artifacts, so images must be inline SVG or data: URIs; external/relative/
     * empty src values commonly break (the model invents paths that don't exist).
     *
     * @return array<int, string>
     */
    private function assetChecks(string $content): array
    {
        if (!preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*("|\')(.*?)\1/is', $content, $matches)) {
            return [];
        }

        $bad = [];
        $placeholders = 0;
        foreach ($matches[2] as $src) {
            $src = trim($src);
            if ($src === '') {
                $bad[] = '(empty src)';
            } elseif (preg_match('/^data:image\/(?:png|jpe?g|gif|webp|avif|bmp);base64,(.*)$/is', $src, $m)) {
                // A short base64 raster is a placeholder stub that renders empty.
                // (Inline SVG data URIs are vector and fine at any length.)
                if (strlen(trim($m[1])) < 512) {
                    $placeholders++;
                }
            } elseif (!preg_match('/^data:/i', $src)) {
                $bad[] = $src;
            }
        }

        $issues = [];
        if ($bad !== []) {
            $examples = implode(', ', array_slice(array_values(array_unique($bad)), 0, 3));
            $issues[] = sprintf(
                '%d <img> element(s) reference non-inline assets (%s) that may 404 — use inline SVG or data: URIs so the standalone page has no broken images.',
                count($bad),
                $examples
            );
        }
        if ($placeholders > 0) {
            $issues[] = sprintf(
                '%d <img> element(s) use a tiny placeholder base64 raster that renders as an empty/broken box — '
                . 'build mockups and hero visuals as HTML+CSS or inline SVG instead.',
                $placeholders
            );
        }

        return $issues;
    }

    /**
     * @return array<int, string>
     */
    private function htmlChecks(string $lower): array
    {
        $issues = [];

        if (!str_contains($lower, 'name="viewport"')) {
            $issues[] = 'Missing responsive viewport meta tag (<meta name="viewport" ...>).';
        }
        if (!preg_match('/<html[^>]*\slang=/', $lower)) {
            $issues[] = 'Missing lang attribute on the <html> element.';
        }
        if (!str_contains($lower, '<main')) {
            $issues[] = 'Missing a <main> landmark for accessibility.';
        }
        if (!str_contains($lower, 'cursor:pointer') && !str_contains($lower, 'cursor: pointer')) {
            $issues[] = 'No cursor:pointer found — interactive elements should show a pointer cursor.';
        }
        if (!str_contains($lower, ':focus')) {
            $issues[] = 'No focus styles found — keyboard focus must be visible.';
        }

        return $issues;
    }

    /**
     * Review the content and, if needed and enabled, run LLM fix passes until the
     * output is clean or the pass budget is exhausted (one fix per remaining
     * issue set). Iterating clears issues a single pass tends to miss/reintroduce.
     *
     * @return array{content: string, issues: array<int, string>, fixed: bool, passes: int, remaining_issues: array<int, string>}
     */
    public function reviewAndFix(string $content, WebsiteGenerationRequest $request, DesignSystem $designSystem): array
    {
        $initialIssues = $this->review($content, $request, $designSystem);

        if ($initialIssues === [] || !$request->qualityReview) {
            return [
                'content' => $content,
                'issues' => $initialIssues,
                'fixed' => false,
                'passes' => 0,
                'remaining_issues' => $initialIssues,
            ];
        }

        $maxPasses = max(1, (int) config('ai-engine.design.quality_review_max_passes', 2));
        $current = $content;
        $remaining = $initialIssues;
        $passes = 0;

        while ($remaining !== [] && $passes < $maxPasses) {
            $fixRequest = new AIRequest(
                prompt: $this->composer->composeFix($request, $designSystem, $current, $remaining),
                engine: $request->engine,
                model: $request->model,
                maxTokens: $request->maxTokens,
                temperature: $request->temperature,
                metadata: ['website_quality_fix' => true, 'fix_pass' => $passes + 1],
            );

            $response = $this->ai->generate($fixRequest);
            if (!$response->isSuccessful() || trim($response->getContent()) === '') {
                break;
            }

            $current = $response->getContent();
            $remaining = $this->review($current, $request, $designSystem);
            $passes++;
        }

        return [
            'content' => $passes > 0 ? $current : $content,
            'issues' => $initialIssues,
            'fixed' => $passes > 0,
            'passes' => $passes,
            'remaining_issues' => $remaining,
        ];
    }

    private function containsEmoji(string $content): bool
    {
        return (bool) preg_match(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u',
            $content
        );
    }
}
