<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Design;

use LaravelAIEngine\Services\Design\DesignKnowledgeBase;
use LaravelAIEngine\Services\Design\DesignSystemResolver;
use LaravelAIEngine\Tests\UnitTestCase;

class DesignSystemResolverTest extends UnitTestCase
{
    public function test_knowledge_base_ranks_product_rows(): void
    {
        $kb = new DesignKnowledgeBase();

        $results = $kb->search('product', 'SaaS analytics dashboard modern minimal', 1);

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('Product Type', $results[0]);
    }

    public function test_resolves_grounded_saas_design_system(): void
    {
        $resolver = app(DesignSystemResolver::class);

        $ds = $resolver->resolve('SaaS analytics dashboard modern minimal', 'DemoSaaS');

        // Matches the UI/UX Pro Max reference output exactly.
        $this->assertSame('DemoSaaS', $ds->projectName);
        $this->assertStringContainsString('SaaS', $ds->category);
        $this->assertSame('Flat Design', $ds->style['name']);
        $this->assertSame('#1E40AF', $ds->colors['primary']);
        $this->assertSame('#D97706', $ds->colors['accent']); // WCAG-corrected accent
        $this->assertSame('Fira Code', $ds->typography['heading']);
        $this->assertSame('Fira Sans', $ds->typography['body']);
        $this->assertNotSame('', $ds->antiPatterns);
        $this->assertNotEmpty($ds->antiPatternList());
    }

    public function test_markdown_brief_embeds_tokens_for_grounding(): void
    {
        $resolver = app(DesignSystemResolver::class);

        $markdown = $resolver->resolve('SaaS analytics dashboard modern minimal', 'DemoSaaS')->toMarkdown();

        $this->assertStringContainsString('#1E40AF', $markdown);
        $this->assertStringContainsString('--color-primary', $markdown);
        $this->assertStringContainsString('Fira Code', $markdown);
        $this->assertStringContainsString('Avoid (Anti-Patterns)', $markdown);
    }

    public function test_different_product_resolves_different_palette(): void
    {
        $resolver = app(DesignSystemResolver::class);

        $saas = $resolver->resolve('SaaS analytics dashboard modern minimal');
        $ecommerce = $resolver->resolve('luxury fashion e-commerce storefront');

        // Distinct products should produce distinct grounded color tokens.
        $this->assertNotSame($saas->colors['primary'], $ecommerce->colors['primary']);
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $ecommerce->colors['primary']);
    }
}
