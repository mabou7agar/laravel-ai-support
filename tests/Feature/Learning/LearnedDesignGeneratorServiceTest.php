<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Learning;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\LearnedDesignGenerationRequest;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Learning\LearnedDesignGeneratorService;
use LaravelAIEngine\Services\Learning\LearningService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class LearnedDesignGeneratorServiceTest extends TestCase
{
    public function test_it_generates_html_from_scoped_learned_design_context(): void
    {
        app(LearningService::class)->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'Design uses black canvas, white uppercase headings, sharp cards, hairline borders, and restrained red and blue accents. fontFamily: "AcmeDisplay, sans-serif"',
            type: 'design',
            title: 'Premium dashboard rules',
            workspaceId: 'workspace-design'
        ));

        $ai = Mockery::mock(AIEngineService::class);
        $this->app->instance(AIEngineService::class, $ai);

        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function (AIRequest $request): bool {
                $metadata = $request->getMetadata();

                return str_contains($request->getPrompt(), 'black canvas')
                    && str_contains($request->getPrompt(), 'fontFamily')
                    && str_contains($request->getPrompt(), 'AI invoice dashboard')
                    && $request->getMaxTokens() === 900
                    && $request->getTemperature() === 0.2
                    && ($metadata['learned_design_generation'] ?? false) === true
                    && ($metadata['learn_scope']['workspace_id'] ?? null) === 'workspace-design';
            }))
            ->andReturn(new AIResponse(
                content: "```html\n<html><body><main style=\"font-family: AcmeDisplay, sans-serif\">Generated learned design</main></body></html>\n```",
                engine: 'openai',
                model: 'gpt-4o-mini',
                tokensUsed: 321,
                creditsUsed: 12.5,
            ));

        $result = app(LearnedDesignGeneratorService::class)->generate(new LearnedDesignGenerationRequest(
            prompt: 'AI invoice dashboard',
            scope: ['workspace_id' => 'workspace-design'],
            format: 'html',
            maxTokens: 900,
            temperature: 0.2,
            composeHtml: false,
        ));

        $this->assertSame('html', $result->format);
        $this->assertStringStartsWith('<!doctype html>', $result->content);
        $this->assertStringContainsString('Generated learned design', $result->content);
        $this->assertStringNotContainsString('AcmeDisplay', $result->content);
        $this->assertStringContainsString('font-family: system-ui, sans-serif', $result->content);
        $this->assertCount(1, $result->matches);
        $this->assertSame(321, $result->tokensUsed);
        $this->assertSame(12.5, $result->creditsUsed);
    }

    public function test_ai_design_command_writes_package_generated_artifact(): void
    {
        app(LearningService::class)->ingest(new LearningSourceRequest(
            sourceType: 'text',
            source: 'Use dense financial cards, compact chat suggestions, and clear responsive stacking.',
            type: 'design',
            title: 'Finance app layout',
            workspaceId: 'workspace-command'
        ));

        $ai = Mockery::mock(AIEngineService::class);
        $this->app->instance(AIEngineService::class, $ai);

        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(new AIResponse(
                content: '<!doctype html><html><body><h1>Package generated design</h1></body></html>',
                engine: 'openai',
                model: 'gpt-4o-mini',
                tokensUsed: 100,
                creditsUsed: 4.0,
            ));

        $output = storage_path('framework/testing/learned-design-command.html');

        $this->artisan('ai:design', [
            'prompt' => 'Create a billing workspace dashboard',
            '--workspace' => 'workspace-command',
            '--media-url' => 'https://example.com/invoice-workspace.jpg',
            '--output' => $output,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertFileExists($output);
        $html = file_get_contents($output) ?: '';

        $this->assertStringContainsString('Package generated design', $html);
        $this->assertStringNotContainsString('Invoice Command', $html);
        $this->assertStringNotContainsString('Invoices Under Command', $html);
    }
}
