<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Learning;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Services\Learning\Adapters\GetDesignLearningAdapter;
use LaravelAIEngine\Services\Learning\GetDesignCliRunner;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class GetDesignLearningAdapterTest extends UnitTestCase
{
    public function test_it_fetches_design_markdown_for_url_through_getdesign_api(): void
    {
        $client = Mockery::mock(Client::class);
        $runner = Mockery::mock(GetDesignCliRunner::class);

        $client->shouldReceive('get')
            ->once()
            ->with('https://api.getdesign.app/', [
                'headers' => ['Accept' => 'text/markdown'],
                'query' => ['url' => 'https://example.com'],
                'timeout' => 45,
            ])
            ->andReturn(new Response(200, [], '# Example Design'));

        $payload = (new GetDesignLearningAdapter($client, $runner))->fetch(new LearningSourceRequest(
            sourceType: 'url',
            source: 'https://example.com',
            adapter: 'getdesign',
            type: 'design'
        ));

        $this->assertSame('# Example Design', $payload->content);
        $this->assertSame('getdesign', $payload->metadata['adapter']);
        $this->assertSame('url', $payload->metadata['source_type']);
    }

    public function test_it_fetches_curated_design_slug_through_cli_runner(): void
    {
        config()->set('ai-engine.learning.adapters.getdesign.allow_cli', true);

        $client = Mockery::mock(Client::class);
        $runner = Mockery::mock(GetDesignCliRunner::class);

        $runner->shouldReceive('add')
            ->once()
            ->with('bmw-m')
            ->andReturn('# BMW M Design');

        $payload = (new GetDesignLearningAdapter($client, $runner))->fetch(new LearningSourceRequest(
            sourceType: 'getdesign_slug',
            source: 'bmw-m',
            adapter: 'getdesign',
            type: 'design'
        ));

        $this->assertSame('# BMW M Design', $payload->content);
        $this->assertSame('bmw-m', $payload->metadata['slug']);
        $this->assertSame('getdesign', $payload->metadata['adapter']);
    }

    public function test_cli_runner_requires_explicit_enablement_for_slug_learning(): void
    {
        config()->set('ai-engine.learning.adapters.getdesign.allow_cli', false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('getdesign CLI learning is disabled');

        (new GetDesignCliRunner())->add('generic-design');
    }

    public function test_it_rejects_unsupported_getdesign_response_content_type(): void
    {
        $client = Mockery::mock(Client::class);
        $runner = Mockery::mock(GetDesignCliRunner::class);

        $client->shouldReceive('get')
            ->once()
            ->andReturn(new Response(200, ['Content-Type' => 'application/octet-stream'], '# Example Design'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported content type');

        (new GetDesignLearningAdapter($client, $runner))->fetch(new LearningSourceRequest(
            sourceType: 'url',
            source: 'https://example.com',
            adapter: 'getdesign',
            type: 'design'
        ));
    }

    public function test_it_rejects_getdesign_response_over_limit_without_content_length_header(): void
    {
        config()->set('ai-engine.learning.max_content_bytes', 8);

        $client = Mockery::mock(Client::class);
        $runner = Mockery::mock(GetDesignCliRunner::class);

        $client->shouldReceive('get')
            ->once()
            ->andReturn(new Response(200, ['Content-Type' => 'text/markdown'], '# Example Design'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the configured size limit');

        (new GetDesignLearningAdapter($client, $runner))->fetch(new LearningSourceRequest(
            sourceType: 'url',
            source: 'https://example.com',
            adapter: 'getdesign',
            type: 'design'
        ));
    }
}
