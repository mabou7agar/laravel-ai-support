<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Design;

use LaravelAIEngine\DTOs\DesignSystem;
use LaravelAIEngine\DTOs\WebsiteGenerationRequest;
use LaravelAIEngine\Services\Design\WebsiteQualityReviewer;
use LaravelAIEngine\Tests\UnitTestCase;

class WebsiteQualityReviewerTest extends UnitTestCase
{
    private function reviewer(): WebsiteQualityReviewer
    {
        // review() is deterministic and never touches the AI/composer collaborators.
        return (new \ReflectionClass(WebsiteQualityReviewer::class))->newInstanceWithoutConstructor();
    }

    private function designSystem(): DesignSystem
    {
        return new DesignSystem(
            projectName: 'T',
            category: 'SaaS',
            pattern: ['name' => '', 'sections' => '', 'cta_placement' => '', 'color_strategy' => '', 'conversion' => ''],
            style: ['name' => 'Flat Design', 'type' => '', 'effects' => '', 'keywords' => '', 'best_for' => '', 'performance' => '', 'accessibility' => '', 'light_mode' => '', 'dark_mode' => ''],
            colors: ['primary' => '#1E40AF', 'on_primary' => '#FFF', 'secondary' => '', 'accent' => '', 'background' => '', 'foreground' => '', 'muted' => '', 'border' => '', 'destructive' => '', 'ring' => '', 'notes' => ''],
            typography: ['heading' => 'Inter', 'body' => 'Inter', 'mood' => '', 'best_for' => '', 'google_fonts_url' => '', 'css_import' => ''],
            keyEffects: '',
            antiPatterns: '',
            decisionRules: [],
            severity: 'LOW',
        );
    }

    private function htmlShell(string $bodyExtra): string
    {
        return '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>:root{--color-primary:#1E40AF}.b{cursor:pointer;transition:opacity 200ms}.b:focus{outline:2px solid}'
            . '@media (prefers-reduced-motion:reduce){*{transition:none}}</style></head>'
            . '<body><main>' . $bodyExtra . '<button class="b" style="color:#1E40AF">Go</button></main></body></html>';
    }

    public function test_flags_external_image_asset(): void
    {
        $html = $this->htmlShell('<img src="assets/hero.png" alt="hero">');

        $issues = $this->reviewer()->review($html, new WebsiteGenerationRequest(prompt: 'x', stack: 'html'), $this->designSystem());

        $this->assertTrue(
            (bool) array_filter($issues, fn (string $i) => str_contains($i, 'non-inline assets')),
            'Expected a non-inline image asset issue. Got: ' . json_encode($issues)
        );
    }

    public function test_flags_empty_image_src(): void
    {
        $html = $this->htmlShell('<img src="" alt="broken">');

        $issues = $this->reviewer()->review($html, new WebsiteGenerationRequest(prompt: 'x', stack: 'html'), $this->designSystem());

        $this->assertTrue((bool) array_filter($issues, fn (string $i) => str_contains($i, 'non-inline assets')));
    }

    public function test_flags_tiny_placeholder_base64_raster(): void
    {
        // 1x1 transparent PNG — the kind of empty stub a model emits for a mockup.
        $stub = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $html = $this->htmlShell('<img class="device-mockup" src="data:image/png;base64,' . $stub . '" alt="App on device">');

        $issues = $this->reviewer()->review($html, new WebsiteGenerationRequest(prompt: 'x', stack: 'html'), $this->designSystem());

        $this->assertTrue(
            (bool) array_filter($issues, fn (string $i) => str_contains($i, 'placeholder base64 raster')),
            'Expected a placeholder-raster issue. Got: ' . json_encode($issues)
        );
    }

    public function test_passes_inline_svg_and_data_uri_images(): void
    {
        $html = $this->htmlShell(
            '<svg viewBox="0 0 24 24"><path d="M3 3v18h18"/></svg>'
            . '<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=" alt="ok">'
        );

        $issues = $this->reviewer()->review($html, new WebsiteGenerationRequest(prompt: 'x', stack: 'html'), $this->designSystem());

        $this->assertSame([], $issues);
    }
}
