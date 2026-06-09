<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * End-to-end coverage for the website builder: HTTP route → controller →
 * GenerateWebsiteService → WebsiteBuilderService → real DesignSystemResolver
 * (bundled CSV data) → WebsitePromptComposer → WebsiteQualityReviewer →
 * DesignSystemPersister. Only the LLM network boundary (AIEngineService) is
 * stubbed, per the package's no-live-LLM-in-CI rule.
 */
class WebsiteBuilderE2ETest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputDir = sys_get_temp_dir() . '/ai-engine-e2e-' . bin2hex(random_bytes(5));
        config()->set('ai-engine.design.output_path', $this->outputDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->outputDir);
        Mockery::close();
        parent::tearDown();
    }

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
body { font-family: "Fira Sans", sans-serif; }
h1 { font-family: "Fira Code", monospace; }
.btn { background: var(--color-primary); cursor: pointer; transition: opacity 200ms ease; }
.btn:focus { outline: 2px solid var(--color-accent); }
@media (prefers-reduced-motion: reduce) { * { transition: none; } }
</style>
</head>
<body><main id="main"><h1>DemoSaaS</h1><button class="btn">Start</button></main></body>
</html>
HTML;
    }

    public function test_full_pipeline_grounds_persists_and_returns_envelope(): void
    {
        $capturedPrompt = null;

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturnUsing(function (AIRequest $request) use (&$capturedPrompt): AIResponse {
                $capturedPrompt = $request->getPrompt();

                return AIResponse::success($this->compliantHtml(), 'openai', 'gpt-4o');
            });
        $this->instance(AIEngineService::class, $ai);

        $response = $this->postJson(route('ai-engine.generate.api.website'), [
            'prompt' => 'SaaS analytics dashboard for product teams, modern and minimal',
            'project_name' => 'DemoSaaS',
            'stack' => 'html',
            'persist' => true,
            'page' => 'dashboard',
        ]);

        // 1. HTTP envelope + generated artifact.
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.format', 'html');
        $this->assertStringContainsString('#1E40AF', (string) $response->json('data.content'));
        $this->assertSame([], $response->json('data.quality_review.issues_found'));

        // 2. The grounding actually reached the model: the composed prompt carries
        //    the resolved design-system tokens, not just the user's text.
        $this->assertIsString($capturedPrompt);
        $this->assertStringContainsString('SaaS analytics dashboard', $capturedPrompt);
        $this->assertStringContainsString('#1E40AF', $capturedPrompt);
        $this->assertStringContainsString('--color-primary', $capturedPrompt);
        $this->assertStringContainsString('Fira Code', $capturedPrompt);
        $this->assertStringContainsString('Target stack: HTML', $capturedPrompt);

        // 3. Multi-page persistence wrote MASTER + page override to disk.
        $master = $this->outputDir . '/design-system/demosaas/MASTER.md';
        $page = $this->outputDir . '/design-system/demosaas/pages/dashboard.md';
        $this->assertFileExists($master);
        $this->assertFileExists($page);
        $this->assertStringContainsString('#1E40AF', file_get_contents($master));
        $this->assertStringContainsString('Dashboard Page Overrides', file_get_contents($page));
    }

    public function test_react_stack_returns_component_code_not_html_document(): void
    {
        $component = <<<'JSX'
export default function Landing() {
  const styles = { ['--color-primary']: '#1E40AF', fontFamily: 'Fira Code' };
  return <main style={styles}><button style={{ cursor: 'pointer' }}>Start</button></main>;
}
JSX;

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()
            ->andReturn(AIResponse::success($component, 'openai', 'gpt-4o'));
        $this->instance(AIEngineService::class, $ai);

        $response = $this->postJson(route('ai-engine.generate.api.website'), [
            'prompt' => 'SaaS analytics dashboard modern minimal',
            'stack' => 'react',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.stack', 'react');
        $response->assertJsonPath('data.format', 'code');

        $content = (string) $response->json('data.content');
        $this->assertStringContainsString('export default function', $content);
        $this->assertStringNotContainsString('<!doctype html>', strtolower($content));
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
