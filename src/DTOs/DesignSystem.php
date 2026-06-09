<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

/**
 * A resolved, grounded design system for a product/website.
 *
 * Produced by DesignSystemResolver from the bundled design-intelligence
 * knowledge base. Carries layout pattern, visual style, WCAG-aware color tokens,
 * a font pairing, effects, and anti-patterns. Rendered to Markdown to ground an
 * LLM generation prompt.
 */
final class DesignSystem
{
    /**
     * @param array{name: string, sections: string, cta_placement: string, color_strategy: string, conversion: string} $pattern
     * @param array{name: string, type: string, effects: string, keywords: string, best_for: string, performance: string, accessibility: string, light_mode: string, dark_mode: string} $style
     * @param array{primary: string, on_primary: string, secondary: string, accent: string, background: string, foreground: string, muted: string, border: string, destructive: string, ring: string, notes: string} $colors
     * @param array{heading: string, body: string, mood: string, best_for: string, google_fonts_url: string, css_import: string} $typography
     * @param array<string, mixed> $decisionRules
     */
    public function __construct(
        public readonly string $projectName,
        public readonly string $category,
        public readonly array $pattern,
        public readonly array $style,
        public readonly array $colors,
        public readonly array $typography,
        public readonly string $keyEffects,
        public readonly string $antiPatterns,
        public readonly array $decisionRules,
        public readonly string $severity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project_name' => $this->projectName,
            'category' => $this->category,
            'pattern' => $this->pattern,
            'style' => $this->style,
            'colors' => $this->colors,
            'typography' => $this->typography,
            'key_effects' => $this->keyEffects,
            'anti_patterns' => $this->antiPatterns,
            'decision_rules' => $this->decisionRules,
            'severity' => $this->severity,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function sectionList(): array
    {
        $raw = $this->pattern['sections'] ?? '';
        $parts = preg_split('/[>\n]/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @return array<int, string>
     */
    public function antiPatternList(): array
    {
        $parts = explode('+', $this->antiPatterns);

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /**
     * Render the design system as a Markdown brief suitable for grounding an LLM
     * generation prompt. Mirrors the UI/UX Pro Max design-system markdown.
     */
    public function toMarkdown(): string
    {
        $lines = [];
        $lines[] = "## Design System: {$this->projectName}";
        $lines[] = "Product category: {$this->category}";
        $lines[] = '';

        $lines[] = '### Layout Pattern';
        $lines[] = "- Name: {$this->pattern['name']}";
        if (($this->pattern['conversion'] ?? '') !== '') {
            $lines[] = "- Conversion focus: {$this->pattern['conversion']}";
        }
        if (($this->pattern['cta_placement'] ?? '') !== '') {
            $lines[] = "- Primary CTA placement: {$this->pattern['cta_placement']}";
        }
        if (($this->pattern['color_strategy'] ?? '') !== '') {
            $lines[] = "- Color strategy: {$this->pattern['color_strategy']}";
        }
        $sections = $this->sectionList();
        if ($sections !== []) {
            $lines[] = '- Section order:';
            foreach ($sections as $i => $section) {
                $lines[] = '  ' . ($i + 1) . ". {$section}";
            }
        }
        $lines[] = '';

        $lines[] = '### Visual Style';
        $lines[] = "- Style: {$this->style['name']}";
        if (($this->style['keywords'] ?? '') !== '') {
            $lines[] = "- Keywords: {$this->style['keywords']}";
        }
        if (($this->style['best_for'] ?? '') !== '') {
            $lines[] = "- Best for: {$this->style['best_for']}";
        }
        if (($this->style['light_mode'] ?? '') !== '' || ($this->style['dark_mode'] ?? '') !== '') {
            $lines[] = "- Mode support: Light {$this->style['light_mode']} | Dark {$this->style['dark_mode']}";
        }
        $lines[] = '';

        $lines[] = '### Color Tokens (use these exact values as CSS variables)';
        $lines[] = '| Role | Hex | CSS Variable |';
        $lines[] = '|------|-----|--------------|';
        foreach ($this->colorRows() as [$label, $hex, $var]) {
            if ($hex !== '') {
                $lines[] = "| {$label} | `{$hex}` | `{$var}` |";
            }
        }
        if (($this->colors['notes'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = "Color notes: {$this->colors['notes']}";
        }
        $lines[] = '';

        $lines[] = '### Typography';
        $lines[] = "- Heading font: {$this->typography['heading']}";
        $lines[] = "- Body font: {$this->typography['body']}";
        if (($this->typography['mood'] ?? '') !== '') {
            $lines[] = "- Mood: {$this->typography['mood']}";
        }
        if (($this->typography['google_fonts_url'] ?? '') !== '') {
            $lines[] = "- Google Fonts: {$this->typography['google_fonts_url']}";
        }
        if (($this->typography['css_import'] ?? '') !== '') {
            $lines[] = '- CSS import:';
            $lines[] = '```css';
            $lines[] = $this->typography['css_import'];
            $lines[] = '```';
        }
        $lines[] = '';

        if ($this->keyEffects !== '') {
            $lines[] = '### Key Effects';
            $lines[] = $this->keyEffects;
            $lines[] = '';
        }

        $antiList = $this->antiPatternList();
        if ($antiList !== []) {
            $lines[] = '### Avoid (Anti-Patterns)';
            foreach ($antiList as $anti) {
                $lines[] = "- {$anti}";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public function colorRows(): array
    {
        return [
            ['Primary', $this->colors['primary'] ?? '', '--color-primary'],
            ['On Primary', $this->colors['on_primary'] ?? '', '--color-on-primary'],
            ['Secondary', $this->colors['secondary'] ?? '', '--color-secondary'],
            ['Accent/CTA', $this->colors['accent'] ?? '', '--color-accent'],
            ['Background', $this->colors['background'] ?? '', '--color-background'],
            ['Foreground', $this->colors['foreground'] ?? '', '--color-foreground'],
            ['Muted', $this->colors['muted'] ?? '', '--color-muted'],
            ['Border', $this->colors['border'] ?? '', '--color-border'],
            ['Destructive', $this->colors['destructive'] ?? '', '--color-destructive'],
            ['Ring', $this->colors['ring'] ?? '', '--color-ring'],
        ];
    }
}
