<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Learning;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Services\Learning\Adapters\CrawlLearningAdapter;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class CrawlLearningAdapterTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai-engine.learning.adapters.crawl.enabled', true);
    }

    private function page(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/html'], $body);
    }

    public function test_it_crawls_same_domain_pages_strips_boilerplate_and_skips_off_domain(): void
    {
        $home = <<<'HTML'
        <html><head><style>.x{color:red}</style></head>
        <body>
            <header><nav><a href="/about">About</a></nav></header>
            <main><h1>Welcome Home</h1><p>Home page body text.</p></main>
            <a href="/about">About us</a>
            <a href="https://other.example.org/external">Off domain</a>
            <a href="/logo.png">Logo image</a>
            <footer>Copyright footer noise</footer>
            <script>console.log('tracking');</script>
        </body></html>
        HTML;

        $about = <<<'HTML'
        <html><body>
            <header>Site Header</header>
            <main><h1>About Page</h1><p>About body text here.</p></main>
            <a href="/about">self loop</a>
            <a href="https://example.com/">Back home</a>
            <footer>footer</footer>
        </body></html>
        HTML;

        $client = Mockery::mock(Client::class);

        $client->shouldReceive('get')
            ->once()
            ->with('https://example.com/', Mockery::type('array'))
            ->andReturn($this->page($home));

        $client->shouldReceive('get')
            ->once()
            ->with('https://example.com/about', Mockery::type('array'))
            ->andReturn($this->page($about));

        // Off-domain, image, and self-loops must never be requested.
        $client->shouldNotReceive('get')->with('https://other.example.org/external', Mockery::any());
        $client->shouldNotReceive('get')->with('https://example.com/logo.png', Mockery::any());

        $payload = (new CrawlLearningAdapter($client))->fetch(new LearningSourceRequest(
            sourceType: 'crawl',
            source: 'https://example.com/',
            adapter: 'crawl',
        ));

        $this->assertSame('crawl', $payload->metadata['adapter']);
        $this->assertSame(2, $payload->metadata['pages_crawled']);
        $this->assertSame('example.com', $payload->title);

        // Main text is present from both pages.
        $this->assertStringContainsString('Welcome Home', $payload->content);
        $this->assertStringContainsString('Home page body text.', $payload->content);
        $this->assertStringContainsString('About Page', $payload->content);
        $this->assertStringContainsString('About body text here.', $payload->content);

        // Boilerplate stripped.
        $this->assertStringNotContainsString('Copyright footer noise', $payload->content);
        $this->assertStringNotContainsString('tracking', $payload->content);
        $this->assertStringNotContainsString('color:red', $payload->content);
        $this->assertStringNotContainsString('Site Header', $payload->content);

        // Off-domain link content never crawled.
        $this->assertStringNotContainsString('external', $payload->content);
    }

    public function test_it_caps_the_number_of_pages_crawled(): void
    {
        $client = Mockery::mock(Client::class);

        // Seed links to two more in-domain pages, but cap is 1.
        $client->shouldReceive('get')
            ->once()
            ->with('https://example.com/', Mockery::type('array'))
            ->andReturn($this->page('<main>Seed</main><a href="/a">a</a><a href="/b">b</a>'));

        // Only the seed page is fetched because max_pages = 1.
        $client->shouldNotReceive('get')->with('https://example.com/a', Mockery::any());
        $client->shouldNotReceive('get')->with('https://example.com/b', Mockery::any());

        $payload = (new CrawlLearningAdapter($client))->fetch(new LearningSourceRequest(
            sourceType: 'crawl',
            source: 'https://example.com/',
            adapter: 'crawl',
            metadata: ['max_pages' => 1],
        ));

        $this->assertSame(1, $payload->metadata['pages_crawled']);
        $this->assertStringContainsString('Seed', $payload->content);
    }

    public function test_supports_requires_the_adapter_to_be_enabled(): void
    {
        $request = new LearningSourceRequest(sourceType: 'crawl', source: 'https://example.com/', adapter: 'crawl');

        config()->set('ai-engine.learning.adapters.crawl.enabled', false);
        $this->assertFalse((new CrawlLearningAdapter(Mockery::mock(Client::class)))->supports($request));

        config()->set('ai-engine.learning.adapters.crawl.enabled', true);
        $this->assertTrue((new CrawlLearningAdapter(Mockery::mock(Client::class)))->supports($request));
    }
}
