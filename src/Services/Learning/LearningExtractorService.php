<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning;

use LaravelAIEngine\DTOs\LearningSourcePayload;
use LaravelAIEngine\DTOs\LearningSourceRequest;

class LearningExtractorService
{
    /**
     * @return array<int, array{kind: string, title?: string|null, content: string, metadata?: array, confidence?: float, position?: int}>
     */
    public function extract(LearningSourceRequest $request, LearningSourcePayload $payload): array
    {
        $sections = $this->markdownSections($payload->content);

        if ($sections === []) {
            $sections[] = [
                'title' => $payload->title ?? $request->title ?? $request->type,
                'content' => trim($payload->content),
            ];
        }

        $items = [];
        foreach ($sections as $position => $section) {
            $content = trim((string) $section['content']);
            if ($content === '') {
                continue;
            }

            $items[] = [
                'kind' => $this->classifyKind((string) ($section['title'] ?? ''), $content, $request->type),
                'title' => $section['title'] ?? null,
                'content' => $content,
                'metadata' => [
                    'learn_type' => $request->type,
                    'adapter' => $request->adapter,
                ],
                'confidence' => 0.75,
                'position' => $position,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{title: string|null, content: string}>
     */
    protected function markdownSections(string $content): array
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $sections = [];
        $title = null;
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*$/u', $line, $matches) === 1) {
                $this->pushSection($sections, $title, $buffer);
                $title = trim($matches[1]);
                $buffer = [];
                continue;
            }

            $buffer[] = $line;
        }

        $this->pushSection($sections, $title, $buffer);

        return $sections;
    }

    protected function pushSection(array &$sections, ?string $title, array $buffer): void
    {
        $content = trim(implode("\n", $buffer));
        if ($content === '' && $title === null) {
            return;
        }

        $sections[] = [
            'title' => $title,
            'content' => trim(($title !== null ? $title . "\n" : '') . $content),
        ];
    }

    protected function classifyKind(string $title, string $content, string $type): string
    {
        $haystack = mb_strtolower($title . ' ' . $content . ' ' . $type);

        return match (true) {
            str_contains($haystack, 'component') => 'component',
            str_contains($haystack, 'example') || str_contains($haystack, 'before') && str_contains($haystack, 'after') => 'example',
            str_contains($haystack, 'color') || str_contains($haystack, 'typography') || str_contains($haystack, 'spacing') => 'token',
            str_contains($haystack, 'rule') || str_contains($haystack, 'guideline') || str_contains($haystack, 'must') || str_contains($haystack, 'should') => 'rule',
            default => 'section',
        };
    }
}
