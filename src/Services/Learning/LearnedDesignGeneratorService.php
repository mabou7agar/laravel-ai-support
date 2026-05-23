<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\LearnedDesignGenerationRequest;
use LaravelAIEngine\DTOs\LearnedDesignGenerationResult;
use LaravelAIEngine\DTOs\LearningSearchResult;
use LaravelAIEngine\Services\AIEngineService;

class LearnedDesignGeneratorService
{
    public function __construct(
        protected LearningService $learning,
        protected AIEngineService $ai,
        protected LearnedDesignHtmlComposer $htmlComposer,
    ) {}

    public function generate(LearnedDesignGenerationRequest $request): LearnedDesignGenerationResult
    {
        if ($request->prompt === '') {
            throw new \InvalidArgumentException('A design prompt is required.');
        }

        $format = $request->normalizedFormat();
        $matches = $this->learning->search($this->retrievalQuery($request), $request->scope, $request->limit, $request->type);

        if ($matches === []) {
            throw new \RuntimeException('No learned design context matched the requested scope and type.');
        }

        $aiRequest = new AIRequest(
            prompt: $this->buildPrompt($request, $matches, $format),
            engine: $request->engine,
            model: $request->model,
            maxTokens: $request->maxTokens,
            temperature: $request->temperature,
            metadata: array_replace_recursive($request->metadata, [
                'learned_design_generation' => true,
                'learn_type' => $request->type,
                'learn_scope' => $request->scope,
                'learn_match_count' => count($matches),
                'format' => $format,
            ])
        );

        $response = $this->ai->generate($aiRequest);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException($response->getError() ?: 'Learned design generation failed.');
        }

        $content = $format === 'html'
            ? $this->normalizeHtml($response->getContent())
            : $this->stripMarkdownFence($response->getContent());

        if ($format === 'html' && $request->composeHtml) {
            $content = $this->htmlComposer->compose($request, $matches, $content);
        }

        $content = $this->sanitizeSourceTerms($content, $matches);

        return new LearnedDesignGenerationResult(
            content: $content,
            format: $format,
            matches: $matches,
            engine: $response->getEngine()->value,
            model: $response->getModel()->value,
            tokensUsed: $response->getTokensUsed(),
            creditsUsed: $response->getCreditsUsed(),
            metadata: [
                'prompt' => $request->prompt,
                'type' => $request->type,
                'scope' => $request->scope,
            ]
        );
    }

    /**
     * @param array<int, LearningSearchResult> $matches
     */
    protected function buildPrompt(LearnedDesignGenerationRequest $request, array $matches, string $format): string
    {
        $sourceContext = $this->sourceContext($matches, $request->sourceContextChars);

        $context = array_map(function (LearningSearchResult $match, int $index): string {
            return sprintf(
                "[%d] score=%.2f source=%s kind=%s title=%s\n%s",
                $index + 1,
                $match->score,
                $match->source->title ?? $match->source->source,
                $match->item->kind,
                $match->item->title ?? 'Untitled',
                mb_substr($match->item->content, 0, 1800)
            );
        }, $matches, array_keys($matches));

        $formatInstruction = $format === 'html'
            ? 'Return only a complete, standalone HTML document with embedded CSS. Do not wrap it in markdown fences. Include responsive desktop and mobile CSS. Do not include external JavaScript. Build a full first-screen composition with header/navigation, a primary work area, secondary panels, repeated records/cards when the prompt implies data, and suggested actions when the prompt implies chat/actions. Define CSS variables from learned tokens, use the learned component geometry visibly, and include placeholder media/photo bands when the learned source relies on full-bleed visual bands.'
            : 'Return only a concise Markdown design spec with sections for layout, tokens, components, responsive behavior, accessibility, and implementation notes.';

        return implode("\n\n", [
            'You are a package-owned learned-design generator.',
            'Use the learned context as the design source of truth, but do not copy trademarks, logos, proprietary brand names, or protected content from examples.',
            'Never emit source brand names, source logo names, source font names, or source product labels in the generated artifact; translate them to generic system tokens.',
            'Translate design principles into a generic application design for the user prompt.',
            'Avoid hardcoded business assumptions that are not present in the prompt.',
            'Create a visually complete, inspectable first screen rather than a generic skeleton.',
            'Use concrete sample content only when it is implied by the prompt.',
            'Reflect learned tokens, layout rhythm, typography, component geometry, and interaction states as CSS and markup.',
            'If the learned source defines named tokens or components, translate the relevant ones directly into CSS variables, layout sections, and component classes.',
            'Do not introduce arbitrary gradients, shadows, rounded corners, or stock UI defaults when the learned context points elsewhere.',
            'Prefer accessible UI, stable responsive dimensions, no overlapping text, and production-ready frontend structure.',
            $formatInstruction,
            "User design prompt:\n{$request->prompt}",
            $sourceContext !== '' ? "Learned source context:\n{$sourceContext}" : 'Learned source context: omitted by configuration.',
            "Learned context:\n" . implode("\n\n---\n\n", $context),
        ]);
    }

    /**
     * @param array<int, LearningSearchResult> $matches
     */
    protected function sourceContext(array $matches, int $maxChars): string
    {
        if ($maxChars <= 0) {
            return '';
        }

        $sources = [];
        foreach ($matches as $match) {
            $sourceId = $match->source->sourceId;
            if (isset($sources[$sourceId])) {
                continue;
            }

            $content = trim((string) $match->source->content);
            if ($content === '') {
                continue;
            }

            $remaining = $maxChars - mb_strlen(implode("\n\n---\n\n", $sources));
            if ($remaining <= 0) {
                break;
            }

            $sources[$sourceId] = mb_substr($content, 0, $remaining);
        }

        return implode("\n\n---\n\n", $sources);
    }

    protected function retrievalQuery(LearnedDesignGenerationRequest $request): string
    {
        return trim($request->prompt . ' layout typography colors spacing components cards buttons inputs responsive accessibility');
    }

    protected function normalizeHtml(string $content): string
    {
        $content = trim($this->stripMarkdownFence($content));

        if (!str_contains(strtolower($content), '<html')) {
            $content = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>Learned Design</title>\n</head>\n<body>\n{$content}\n</body>\n</html>";
        }

        if (!str_starts_with(strtolower(ltrim($content)), '<!doctype')) {
            $content = "<!doctype html>\n" . $content;
        }

        return $content;
    }

    protected function stripMarkdownFence(string $content): string
    {
        $content = trim($content);

        if (preg_match('/^```(?:html|markdown|md)?\s*(.*?)\s*```$/is', $content, $matches) === 1) {
            return trim($matches[1]);
        }

        return $content;
    }

    /**
     * @param array<int, LearningSearchResult> $matches
     */
    protected function sanitizeSourceTerms(string $content, array $matches): string
    {
        foreach ($this->sourceTerms($matches) as $term) {
            $content = preg_replace('/' . preg_quote($term, '/') . '/i', 'system-ui', $content) ?? $content;
        }

        return preg_replace(
            '/font-family:\s*[\'"]system-ui,\s*sans-serif[\'"]/i',
            'font-family: system-ui, sans-serif',
            $content
        ) ?? $content;
    }

    /**
     * @param array<int, LearningSearchResult> $matches
     * @return array<int, string>
     */
    protected function sourceTerms(array $matches): array
    {
        $terms = [];

        foreach ($matches as $match) {
            foreach ([$match->source->title, $match->source->source] as $value) {
                if (is_string($value) && preg_match('/^[a-z0-9._-]{3,}$/i', $value) === 1) {
                    $terms[] = $value;
                }
            }

            if (preg_match_all('/fontFamily:\s*[\'"]([^,\'"]+)/i', $match->item->content, $fontMatches) > 0) {
                foreach ($fontMatches[1] as $fontName) {
                    $fontName = trim((string) $fontName);
                    if (mb_strlen($fontName) >= 4) {
                        $terms[] = $fontName;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($terms)));
    }
}
