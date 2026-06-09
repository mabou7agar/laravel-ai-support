<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Design;

use LaravelAIEngine\DTOs\DesignSystem;
use LaravelAIEngine\DTOs\WebsiteGenerationRequest;

/**
 * Builds the grounded generation prompt. The resolved design system is injected
 * as hard constraints so the model produces consistent, accessible, on-brand
 * output instead of generic markup.
 */
class WebsitePromptComposer
{
    public function compose(WebsiteGenerationRequest $request, DesignSystem $designSystem): string
    {
        return implode("\n\n", [
            $this->role(),
            $this->rules(),
            $this->stackInstructions($request->stack),
            $this->formatInstruction($request),
            "Design system (the single source of truth — obey it exactly):\n" . $designSystem->toMarkdown(),
            $this->checklist(),
            "User request:\n" . $request->prompt,
        ]);
    }

    private function role(): string
    {
        return 'You are a senior product designer and front-end engineer. You generate '
            . 'production-grade, accessible, responsive websites that strictly follow a provided design system.';
    }

    private function rules(): string
    {
        return implode("\n", [
            'Hard rules:',
            '- Use the exact color hex values from the design system, declared as CSS custom properties (the listed CSS variable names).',
            '- Use the design system\'s heading and body fonts; load them via the provided Google Fonts import.',
            '- Follow the layout pattern and section order from the design system.',
            '- Honour the visual style and key effects; do NOT introduce styles the design system tells you to avoid.',
            '- Use SVG icons (inline or a well-known set like Lucide/Heroicons). Never use emoji as icons.',
            '- Do NOT reference external or relative image URLs (they will 404), and do NOT emit placeholder base64 '
                . 'rasters (e.g. data:image/png;base64,...) — they render as broken/empty boxes. For icons and '
                . 'illustrations use inline SVG. For device/app mockups, BUILD the frame in HTML+CSS (a styled phone/'
                . 'browser shell containing real UI: header, balance, chart drawn as an inline SVG <polyline>, list rows) '
                . 'or use CSS gradients/shapes. Never use an <img> for a mockup or hero visual.',
            '- Every interactive element: cursor:pointer, a visible focus ring, hover/active states with 150-300ms transitions.',
            '- Meet WCAG AA contrast (4.5:1 body text). Add a skip-to-content link and semantic landmarks.',
            '- Mobile-first and responsive at 375 / 768 / 1024 / 1440px; no horizontal scroll on mobile.',
            '- Respect prefers-reduced-motion. Reserve space for media to avoid layout shift.',
            '- Use realistic, on-topic placeholder copy — never lorem ipsum, never invented brand/legal claims.',
            '- Build a COMPLETE, content-rich page: include and fully populate every section in the design '
                . 'system\'s section order (nav, hero, the listed sections, footer). No empty shells or "coming soon" '
                . 'stubs. A device/app mockup must contain real UI inside the frame (header, key numbers, an inline '
                . 'SVG chart, a few list rows) — never an empty rectangle.',
        ]);
    }

    private function stackInstructions(string $stack): string
    {
        return match ($stack) {
            'react' => "Target stack: React. Output a single self-contained React function component (default export) using "
                . "Tailwind CSS utility classes for styling and the design tokens via inline CSS variables on a root wrapper. "
                . "Use hooks for any interactivity. Assume Tailwind and lucide-react are available.",
            'next' => "Target stack: Next.js (App Router). Output a single self-contained client component (\"use client\") as the "
                . "default export, styled with Tailwind CSS utilities and design tokens as CSS variables. Assume Tailwind and "
                . "lucide-react are available.",
            'vue' => "Target stack: Vue 3. Output a single-file component (<script setup>, <template>, <style scoped>) styled with "
                . "the design tokens as CSS variables. Keep it self-contained.",
            'svelte' => "Target stack: Svelte. Output a single self-contained .svelte component with <script>, markup, and a "
                . "<style> block using the design tokens as CSS variables.",
            default => "Target stack: HTML + CSS. Output one complete, standalone, responsive HTML5 document with all CSS in a "
                . "single <style> tag in the head. No build step, no external CSS frameworks (hand-write the CSS), no external "
                . "JavaScript except a tiny inline script if strictly needed for interactivity.",
        };
    }

    private function formatInstruction(WebsiteGenerationRequest $request): string
    {
        if ($request->isHtmlDocument()) {
            return 'Output format: return ONLY the raw HTML document, starting with <!doctype html>. '
                . 'Do not wrap it in markdown code fences. Do not add commentary before or after.';
        }

        return 'Output format: return ONLY the component source code. Do not wrap it in markdown code fences. '
            . 'Do not add commentary before or after.';
    }

    private function checklist(): string
    {
        return implode("\n", [
            'Before finishing, self-verify:',
            '- [ ] All design-system color tokens are defined and used.',
            '- [ ] Heading/body fonts loaded and applied.',
            '- [ ] No emoji used as icons.',
            '- [ ] Focus states + cursor:pointer on all interactive elements.',
            '- [ ] Responsive with no horizontal scroll on mobile.',
            '- [ ] prefers-reduced-motion respected.',
        ]);
    }

    /**
     * Build a targeted correction prompt for the quality-review fix pass.
     *
     * @param array<int, string> $issues
     */
    public function composeFix(WebsiteGenerationRequest $request, DesignSystem $designSystem, string $content, array $issues): string
    {
        $issueList = implode("\n", array_map(static fn (string $i): string => "- {$i}", $issues));

        return implode("\n\n", [
            'You are fixing a generated website to satisfy its design system and accessibility requirements.',
            "The following quality issues were detected and MUST be fixed:\n" . $issueList,
            'Keep everything that already works; change only what is needed to resolve the issues. Preserve the design system tokens and layout.',
            $this->formatInstruction($request),
            "Design system:\n" . $designSystem->toMarkdown(),
            "Current code to fix:\n" . $content,
        ]);
    }
}
