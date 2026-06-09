<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Design;

use LaravelAIEngine\DTOs\WebsiteGenerationRequest;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Design\WebsiteBuilderService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class WebsiteStreamTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array<int, string>
     */
    private function chunks(): array
    {
        return [
            "<!doctype html>\n<html lang=\"en\"><head>",
            '<meta name="viewport" content="width=device-width, initial-scale=1"><style>:root{--color-primary:#1E40AF}'
                . '.b{cursor:pointer;transition:opacity 200ms}.b:focus{outline:2px solid}'
                . '@media (prefers-reduced-motion:reduce){*{transition:none}}</style></head>',
            '<body><main><h1 style="font-family:\'Fira Code\'">Hi</h1>'
                . '<button class="b" style="color:#1E40AF">Go</button></main></body></html>',
        ];
    }

    public function test_stream_emits_ordered_events_and_full_content(): void
    {
        $chunks = $this->chunks();

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('stream')->once()->andReturnUsing(function () use ($chunks): \Generator {
            yield from $chunks;
        });
        $this->instance(AIEngineService::class, $ai);

        $events = [];
        foreach (app(WebsiteBuilderService::class)->stream(new WebsiteGenerationRequest(
            prompt: 'SaaS analytics dashboard modern minimal',
            projectName: 'DemoSaaS',
            stack: 'html',
        )) as $event) {
            $events[] = $event;
        }

        $names = array_column($events, 'event');

        // Ordered lifecycle: design_system first, then content deltas, QC, done last.
        $this->assertSame('design_system', $names[0]);
        $this->assertSame('done', $names[array_key_last($names)]);
        $this->assertContains('content', $names);
        $this->assertContains('quality_review', $names);

        // Deltas reconstruct exactly what the model streamed.
        $deltas = array_map(
            fn (array $e) => $e['data']['delta'],
            array_values(array_filter($events, fn (array $e) => $e['event'] === 'content'))
        );
        $this->assertSame(implode('', $chunks), implode('', $deltas));

        // design_system event carries the grounded tokens.
        $this->assertSame('#1E40AF', $events[0]['data']['colors']['primary']);

        // done event carries the normalized, QC-clean full document.
        $done = $events[array_key_last($events)]['data'];
        $this->assertStringContainsString('#1E40AF', $done['content']);
        $this->assertStringStartsWith('<!doctype html>', strtolower($done['content']));
        $this->assertSame([], $done['quality_review']['issues_found']);
        $this->assertTrue($done['metadata']['streamed']);
    }
}
