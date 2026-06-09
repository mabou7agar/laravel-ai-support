<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Design;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\WebsiteGenerationRequest;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Design\WebsiteBuilderService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class WebsiteModificationTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function baseHtml(): string
    {
        return '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>:root{--color-primary:#1E40AF}body{font-family:"Fira Sans"}h1{font-family:"Fira Code"}'
            . '.b{cursor:pointer;transition:opacity 200ms}.b:focus{outline:2px solid}'
            . '@media (prefers-reduced-motion:reduce){*{transition:none}}</style></head>'
            . '<body><main><h1 style="color:#1E40AF">Acme</h1><button class="b">Buy</button></main></body></html>';
    }

    public function test_modification_edits_existing_document(): void
    {
        $base = $this->baseHtml();
        $modified = str_replace('</main>', '<section id="pricing">Pricing</section></main>', $base);

        $captured = null;
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()->andReturnUsing(function (AIRequest $req) use (&$captured, $modified): AIResponse {
            $captured = $req->getPrompt();

            return AIResponse::success($modified, 'openai', 'gpt-4o');
        });
        $this->instance(AIEngineService::class, $ai);

        $request = new WebsiteGenerationRequest(
            prompt: 'Add a pricing section',
            projectName: 'Acme',
            stack: 'html',
            baseContent: $base,
        );

        $this->assertTrue($request->isModification());

        $result = app(WebsiteBuilderService::class)->build($request);

        // The edit prompt carries the change instruction and the current document.
        $this->assertStringContainsString('editing an existing website', $captured);
        $this->assertStringContainsString('Add a pricing section', $captured);
        $this->assertStringContainsString('<h1 style="color:#1E40AF">Acme</h1>', $captured);

        // The result is the edited document, marked as a modify.
        $this->assertStringContainsString('id="pricing"', $result->content);
        $this->assertSame('modify', $result->metadata['mode']);
        $this->assertSame([], $result->qualityReview['remaining_issues']);
    }

    public function test_fresh_generation_is_create_mode(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $captured = null;
        $ai->shouldReceive('generate')->once()->andReturnUsing(function (AIRequest $req) use (&$captured): AIResponse {
            $captured = $req->getPrompt();

            return AIResponse::success($this->baseHtml(), 'openai', 'gpt-4o');
        });
        $this->instance(AIEngineService::class, $ai);

        $result = app(WebsiteBuilderService::class)->build(new WebsiteGenerationRequest(
            prompt: 'SaaS analytics dashboard modern minimal',
            stack: 'html',
        ));

        $this->assertStringNotContainsString('editing an existing website', (string) $captured);
        $this->assertSame('create', $result->metadata['mode']);
    }
}
