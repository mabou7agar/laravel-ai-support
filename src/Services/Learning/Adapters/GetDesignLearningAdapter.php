<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning\Adapters;

use GuzzleHttp\Client;
use LaravelAIEngine\Contracts\Learning\LearningSourceAdapterInterface;
use LaravelAIEngine\DTOs\LearningSourcePayload;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Services\Learning\GetDesignCliRunner;
use LaravelAIEngine\Services\Learning\LearningSourceGuard;

class GetDesignLearningAdapter implements LearningSourceAdapterInterface
{
    public function __construct(
        protected ?Client $client = null,
        protected ?GetDesignCliRunner $runner = null,
        protected ?LearningSourceGuard $guard = null,
    ) {
        $this->client ??= new Client();
        $this->runner ??= new GetDesignCliRunner();
        $this->guard ??= new LearningSourceGuard();
    }

    public function supports(LearningSourceRequest $request): bool
    {
        return $request->adapter === 'getdesign'
            || in_array($request->sourceType, ['getdesign_slug', 'design_slug'], true);
    }

    public function fetch(LearningSourceRequest $request): LearningSourcePayload
    {
        return match ($request->sourceType) {
            'getdesign_slug', 'design_slug' => $this->fetchSlug($request),
            'url' => $this->fetchUrl($request),
            default => throw new \InvalidArgumentException("getdesign cannot learn from source type [{$request->sourceType}]."),
        };
    }

    protected function fetchUrl(LearningSourceRequest $request): LearningSourcePayload
    {
        $response = $this->client->get((string) config('ai-engine.learning.adapters.getdesign.api_url', 'https://api.getdesign.app/'), [
            'headers' => ['Accept' => 'text/markdown'],
            'query' => ['url' => $request->source],
            'timeout' => (int) config('ai-engine.learning.adapters.getdesign.timeout', 45),
        ]);

        $this->guard->assertContentTypeAllowed($response->getHeaderLine('Content-Type'), $request->source);
        $contentLength = $response->getHeaderLine('Content-Length');
        $this->guard->assertContentLengthAllowed(is_numeric($contentLength) ? (int) $contentLength : null, $request->source);
        $content = $this->guard->readPsrStreamWithinLimit($response->getBody(), $request->source);
        $this->guard->assertContentAllowed($content, $request->source);

        return new LearningSourcePayload(
            content: $content,
            title: $request->title ?? parse_url($request->source, PHP_URL_HOST) ?: $request->source,
            metadata: [
                'adapter' => 'getdesign',
                'source_type' => 'url',
                'url' => $request->source,
            ],
        );
    }

    protected function fetchSlug(LearningSourceRequest $request): LearningSourcePayload
    {
        $slug = trim($request->source);
        $content = $this->runner->add($slug);
        $this->guard->assertContentAllowed($content, $request->source);

        return new LearningSourcePayload(
            content: $content,
            title: $request->title ?? $slug,
            metadata: [
                'adapter' => 'getdesign',
                'source_type' => 'getdesign_slug',
                'slug' => $slug,
            ],
        );
    }
}
