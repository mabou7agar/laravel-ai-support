<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class GenerateWebsiteTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * A clean, design-system-compliant HTML document so the deterministic QC
     * pass finds no issues and no fix call is needed.
     */
    private function compliantHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DemoSaaS</title>
<style>
:root { --color-primary: #1E40AF; --color-accent: #D97706; }
body { font-family: "Fira Sans", sans-serif; color: #1E3A8A; }
h1, h2 { font-family: "Fira Code", monospace; }
.btn { background: var(--color-primary); cursor: pointer; transition: opacity 200ms ease; }
.btn:focus { outline: 2px solid var(--color-accent); }
@media (prefers-reduced-motion: reduce) { * { transition: none; } }
</style>
</head>
<body>
<a href="#main">Skip to content</a>
<main id="main"><h1>DemoSaaS Analytics</h1><button class="btn">Start free</button></main>
</body>
</html>
HTML;
    }

    public function test_generates_a_grounded_website(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->andReturn(AIResponse::success($this->compliantHtml(), 'openai', 'gpt-4o'));
        $this->instance(AIEngineService::class, $ai);

        $response = $this->postJson(route('ai-engine.generate.api.website'), [
            'prompt' => 'SaaS analytics dashboard for product teams, modern and minimal',
            'project_name' => 'DemoSaaS',
            'stack' => 'html',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.stack', 'html');
        $response->assertJsonPath('data.format', 'html');
        $response->assertJsonPath('data.design_system.colors.primary', '#1E40AF');
        $this->assertNotEmpty($response->json('data.design_system.style.name'));
        $this->assertStringContainsString('SaaS', (string) $response->json('data.design_system.category'));
        $response->assertJsonPath('data.quality_review.auto_fixed', false);
        $response->assertJsonPath('data.quality_review.issues_found', []);

        $content = $response->json('data.content');
        $this->assertIsString($content);
        $this->assertStringContainsString('#1E40AF', $content);
        $this->assertStringStartsWith('<!doctype html>', strtolower($content));
    }

    public function test_streams_generation_as_sse_events(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('stream')->once()->andReturnUsing(function (): \Generator {
            yield $this->compliantHtml();
        });
        $this->instance(AIEngineService::class, $ai);

        $response = $this->postJson(route('ai-engine.generate.api.website.stream'), [
            'prompt' => 'SaaS analytics dashboard for product teams, modern and minimal',
            'project_name' => 'DemoSaaS',
            'stack' => 'html',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('content-type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('event: design_system', $body);
        $this->assertStringContainsString('event: content', $body);
        $this->assertStringContainsString('event: quality_review', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringContainsString('#1E40AF', $body);
    }

    public function test_validation_requires_a_prompt(): void
    {
        $response = $this->postJson(route('ai-engine.generate.api.website'), [
            'project_name' => 'NoPrompt',
        ]);

        $response->assertStatus(422);
    }

    public function test_quality_review_triggers_a_fix_pass_for_noncompliant_output(): void
    {
        // First call returns markup that fails QC (missing tokens/viewport/etc.);
        // second call (the fix pass) returns compliant markup.
        $bad = '<html><body><h1>Plain</h1><p>no design system here</p></body></html>';

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->twice()
            ->andReturn(
                AIResponse::success($bad, 'openai', 'gpt-4o'),
                AIResponse::success($this->compliantHtml(), 'openai', 'gpt-4o')
            );
        $this->instance(AIEngineService::class, $ai);

        $response = $this->postJson(route('ai-engine.generate.api.website'), [
            'prompt' => 'SaaS analytics dashboard for product teams, modern and minimal',
            'project_name' => 'DemoSaaS',
            'stack' => 'html',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.quality_review.auto_fixed', true);
        $this->assertNotEmpty($response->json('data.quality_review.issues_found'));
        $this->assertSame([], $response->json('data.quality_review.remaining_issues'));
    }
}
