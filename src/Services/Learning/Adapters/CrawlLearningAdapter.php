<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning\Adapters;

use GuzzleHttp\Client;
use LaravelAIEngine\Contracts\Learning\LearningSourceAdapterInterface;
use LaravelAIEngine\DTOs\LearningSourcePayload;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Services\Learning\LearningSourceGuard;

/**
 * Recursively crawls a single domain starting from a seed URL, follows in-domain
 * links up to a configurable page cap, strips boilerplate (nav/header/footer/
 * script/style) and returns the combined main text as a learning payload.
 *
 * Crawl strategy mirrors MagicAI's App\Services\Chatbot\LinkCrawler: BFS over
 * absolute, same-domain links with a visited-set to avoid infinite loops, a hard
 * page cap, and skipping of asset/invalid paths.
 */
class CrawlLearningAdapter implements LearningSourceAdapterInterface
{
    /**
     * @var array<int, string> Path fragments that are never crawled.
     */
    protected array $invalidPaths = ['/cdn-cgi/'];

    /**
     * @var array<int, string> Asset extensions that are never crawled.
     */
    protected array $assetExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'apng', 'avif', 'svg', 'webp', 'ico', 'tiff',
        'css', 'js', 'json', 'xml', 'pdf', 'zip', 'mp4', 'mp3', 'woff', 'woff2', 'ttf',
    ];

    public function __construct(
        protected ?Client $client = null,
        protected ?LearningSourceGuard $guard = null,
    ) {
        $this->client ??= new Client();
        $this->guard ??= new LearningSourceGuard();
    }

    public function supports(LearningSourceRequest $request): bool
    {
        if (!(bool) config('ai-engine.learning.adapters.crawl.enabled', false)) {
            return false;
        }

        if ($request->adapter === 'crawl') {
            return true;
        }

        return in_array($request->sourceType, ['crawl', 'website', 'web_crawl'], true);
    }

    public function fetch(LearningSourceRequest $request): LearningSourcePayload
    {
        $baseUrl = trim($request->source);
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException("Crawl learning requires an absolute URL, got [{$request->source}].");
        }

        $maxPages = max(1, (int) ($request->metadata['max_pages']
            ?? config('ai-engine.learning.adapters.crawl.max_pages', 30)));
        $single = (bool) ($request->metadata['single_page'] ?? false);

        $contents = [];
        $visited = [];
        $queue = [$baseUrl];

        while ($queue !== [] && count($contents) < $maxPages) {
            $url = array_shift($queue);
            if (isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;

            try {
                $html = $this->fetchHtml($url, $request);
            } catch (\Throwable $e) {
                continue;
            }

            $contents[$url] = $this->stripBoilerplate($html);

            if ($single) {
                break;
            }

            foreach ($this->extractLinks($html, $baseUrl) as $link) {
                if (isset($visited[$link]) || in_array($link, $queue, true)) {
                    continue;
                }

                if (!$this->isSameDomain($link, $host) || $this->hasInvalidPath($link) || $this->isAsset($link)) {
                    continue;
                }

                $queue[] = $link;
            }
        }

        $combined = trim(implode("\n\n", array_filter($contents, static fn (string $text): bool => trim($text) !== '')));
        $this->guard->assertContentAllowed($combined, $request->source);

        return new LearningSourcePayload(
            content: $combined,
            title: $request->title ?? $host,
            metadata: [
                'adapter' => 'crawl',
                'source_type' => 'crawl',
                'url' => $baseUrl,
                'pages_crawled' => count($contents),
                'pages' => array_keys($contents),
            ],
        );
    }

    protected function fetchHtml(string $url, LearningSourceRequest $request): string
    {
        $response = $this->client->get($url, [
            'headers' => ['Accept' => 'text/html'],
            'timeout' => (int) config('ai-engine.learning.adapters.crawl.timeout', 30),
        ]);

        $this->guard->assertContentTypeAllowed($response->getHeaderLine('Content-Type'), $url);
        $contentLength = $response->getHeaderLine('Content-Length');
        $this->guard->assertContentLengthAllowed(is_numeric($contentLength) ? (int) $contentLength : null, $url);

        return $this->guard->readPsrStreamWithinLimit($response->getBody(), $url);
    }

    /**
     * @return array<int, string>
     */
    protected function extractLinks(string $html, string $baseUrl): array
    {
        preg_match_all('/<a\s+(?:[^>]*?\s+)?href="([^"]*)"/i', $html, $matches);

        $links = [];
        foreach ($matches[1] as $href) {
            $absolute = $this->makeAbsoluteUrl($href, $baseUrl);
            if ($absolute !== null) {
                $links[] = $absolute;
            }
        }

        return array_values(array_unique($links));
    }

    protected function makeAbsoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'javascript:')) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $this->stripFragment($url);
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $this->stripFragment($scheme . ':' . $url);
        }

        if (str_starts_with($url, '/')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($baseUrl, PHP_URL_HOST);
            if (!is_string($host) || $host === '') {
                return null;
            }

            return $this->stripFragment($scheme . '://' . $host . $url);
        }

        return null;
    }

    protected function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');

        return $pos === false ? $url : substr($url, 0, $pos);
    }

    protected function isSameDomain(string $url, string $host): bool
    {
        return parse_url($url, PHP_URL_HOST) === $host;
    }

    protected function hasInvalidPath(string $url): bool
    {
        foreach ($this->invalidPaths as $invalidPath) {
            if (str_contains($url, $invalidPath)) {
                return true;
            }
        }

        return false;
    }

    protected function isAsset(string $url): bool
    {
        $extension = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, $this->assetExtensions, true);
    }

    /**
     * Strip boilerplate and HTML tags, returning readable main text.
     */
    protected function stripBoilerplate(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<nav\b[^>]*>.*?<\/nav>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<header\b[^>]*>.*?<\/header>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<footer\b[^>]*>.*?<\/footer>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<aside\b[^>]*>.*?<\/aside>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<[^>]+class="[^"]*\bscreen-reader-text\b[^"]*"[^>]*>.*?<\/[^>]+>/is', ' ', $html) ?? $html;

        // Optional HTML-to-text upgrade when a converter is available.
        if (class_exists(\Html2Text\Html2Text::class)) {
            return $this->normalizeWhitespace((new \Html2Text\Html2Text($html))->getText());
        }

        if (class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            try {
                $converter = new \League\HTMLToMarkdown\HtmlConverter(['strip_tags' => true]);

                return $this->normalizeWhitespace($converter->convert($html));
            } catch (\Throwable $e) {
                // Fall through to the native strip below.
            }
        }

        $text = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;

        return $this->normalizeWhitespace(html_entity_decode($text, ENT_QUOTES | ENT_HTML5));
    }

    protected function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
