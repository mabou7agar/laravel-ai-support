<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Design;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\Tools\GenerateWebsiteTool;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class GenerateWebsiteToolTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function compliantHtml(): string
    {
        return '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>:root{--color-primary:#1E40AF}body{font-family:"Fira Sans"}h1{font-family:"Fira Code"}'
            . '.b{cursor:pointer;transition:opacity 200ms}.b:focus{outline:2px solid}'
            . '@media (prefers-reduced-motion:reduce){*{transition:none}}</style></head>'
            . '<body><main><h1 style="color:#1E40AF">Acme</h1><button class="b">Go</button></main></body></html>';
    }

    private function context(): UnifiedActionContext
    {
        return new UnifiedActionContext(sessionId: 'test-session', userId: null);
    }

    public function test_tool_generates_a_website(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->andReturn(AIResponse::success($this->compliantHtml(), 'openai', 'gpt-4o'));
        $this->instance(AIEngineService::class, $ai);

        $result = app(GenerateWebsiteTool::class)->execute(
            ['prompt' => 'SaaS analytics dashboard modern minimal', 'stack' => 'html'],
            $this->context()
        );

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Generated', $result->message);
        $this->assertStringContainsString('#1E40AF', $result->data['content']);
    }

    public function test_tool_edits_an_existing_website(): void
    {
        $base = $this->compliantHtml();
        $edited = str_replace('</main>', '<section id="pricing">Pricing</section></main>', $base);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->andReturn(AIResponse::success($edited, 'openai', 'gpt-4o'));
        $this->instance(AIEngineService::class, $ai);

        $result = app(GenerateWebsiteTool::class)->execute(
            ['prompt' => 'Add a pricing section', 'stack' => 'html', 'base_content' => $base],
            $this->context()
        );

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Updated', $result->message);
        $this->assertStringContainsString('id="pricing"', $result->data['content']);
    }
}
