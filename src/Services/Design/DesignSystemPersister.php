<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Design;

use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\DesignSystem;

/**
 * Persists a design system using the Master + Overrides pattern so a multi-page
 * project stays visually consistent: one MASTER.md source of truth plus optional
 * per-page override files. Port of the UI/UX Pro Max persistence behaviour.
 *
 * Files are written under <base>/design-system/<project-slug>/.
 */
class DesignSystemPersister
{
    /**
     * @return array{master: string, page: ?string, directory: string}
     */
    public function persist(DesignSystem $designSystem, ?string $page = null, ?string $baseDir = null): array
    {
        $base = $baseDir !== null && $baseDir !== ''
            ? rtrim($baseDir, DIRECTORY_SEPARATOR)
            : $this->defaultBaseDir();

        $projectSlug = Str::slug($designSystem->projectName) ?: 'default';
        $dir = $base . DIRECTORY_SEPARATOR . 'design-system' . DIRECTORY_SEPARATOR . $projectSlug;
        $pagesDir = $dir . DIRECTORY_SEPARATOR . 'pages';

        $this->ensureDir($pagesDir);

        $masterPath = $dir . DIRECTORY_SEPARATOR . 'MASTER.md';
        file_put_contents($masterPath, $this->masterMarkdown($designSystem));

        $pagePath = null;
        if ($page !== null && trim($page) !== '') {
            $pagePath = $pagesDir . DIRECTORY_SEPARATOR . (Str::slug($page) ?: 'page') . '.md';
            file_put_contents($pagePath, $this->pageOverrideMarkdown($designSystem, $page));
        }

        return [
            'master' => $masterPath,
            'page' => $pagePath,
            'directory' => $dir,
        ];
    }

    private function masterMarkdown(DesignSystem $ds): string
    {
        $lines = [];
        $lines[] = '# Design System Master File';
        $lines[] = '';
        $lines[] = '> LOGIC: When building a specific page, first check `design-system/pages/[page-name].md`.';
        $lines[] = '> If that file exists, its rules override this Master file. Otherwise follow the rules below.';
        $lines[] = '';
        $lines[] = "**Project:** {$ds->projectName}";
        $lines[] = "**Category:** {$ds->category}";
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = $ds->toMarkdown();
        $lines[] = '';
        $lines[] = '## Spacing Scale';
        $lines[] = '';
        $lines[] = '| Token | Value |';
        $lines[] = '|-------|-------|';
        $lines[] = '| `--space-xs` | 4px |';
        $lines[] = '| `--space-sm` | 8px |';
        $lines[] = '| `--space-md` | 16px |';
        $lines[] = '| `--space-lg` | 24px |';
        $lines[] = '| `--space-xl` | 32px |';
        $lines[] = '| `--space-2xl` | 48px |';
        $lines[] = '| `--space-3xl` | 64px |';
        $lines[] = '';
        $lines[] = '## Forbidden Patterns';
        $lines[] = '';
        foreach ($ds->antiPatternList() as $anti) {
            $lines[] = "- Do NOT: {$anti}";
        }
        $lines[] = '- Do NOT use emoji as icons (use SVG icons).';
        $lines[] = '- Do NOT ship missing cursor:pointer or invisible focus states.';
        $lines[] = '- Do NOT use low-contrast text (keep 4.5:1 minimum).';
        $lines[] = '- Do NOT use instant state changes (always 150-300ms transitions).';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function pageOverrideMarkdown(DesignSystem $ds, string $page): string
    {
        $title = Str::headline(str_replace(['-', '_'], ' ', $page));

        $lines = [];
        $lines[] = "# {$title} Page Overrides";
        $lines[] = '';
        $lines[] = "> PROJECT: {$ds->projectName}";
        $lines[] = '> These rules override `design-system/MASTER.md`. Only deviations are documented here;';
        $lines[] = '> for everything else, follow the Master.';
        $lines[] = '';
        $lines[] = '## Page-Specific Rules';
        $lines[] = '';
        $lines[] = '- Layout: inherit the Master layout pattern unless this page needs a different structure.';
        $lines[] = '- Reuse the Master color tokens, typography, spacing scale, and component specs.';
        $lines[] = '- Document any intentional deviation below.';
        $lines[] = '';
        $lines[] = '## Deviations';
        $lines[] = '';
        $lines[] = '- (none yet — add page-specific overrides here)';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function defaultBaseDir(): string
    {
        $configured = config('ai-engine.design.output_path');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        if (function_exists('storage_path')) {
            return storage_path('app/ai-engine');
        }

        return sys_get_temp_dir();
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException("Unable to create design-system directory: {$path}");
        }
    }
}
