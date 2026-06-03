<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\ProviderTools;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Models\AIProviderToolArtifact;
use LaravelAIEngine\Services\ProviderTools\ProviderFileDownloadService;
use LaravelAIEngine\Tests\TestCase;

class ProviderFileDownloadServiceTest extends TestCase
{
    private function artifact(array $attributes): AIProviderToolArtifact
    {
        $artifact = new AIProviderToolArtifact();
        $artifact->forceFill(array_merge(['uuid' => 'artifact-uuid'], $attributes));

        return $artifact;
    }

    public function test_downloads_openai_file_with_bearer_auth(): void
    {
        Config::set('ai-engine.engines.openai.api_key', 'sk-test');
        Http::fake([
            'https://api.openai.com/*' => Http::response('OPENAI-BYTES', 200, ['Content-Type' => 'text/plain']),
        ]);

        $result = app(ProviderFileDownloadService::class)->download($this->artifact([
            'provider' => 'openai',
            'provider_file_id' => 'file-123',
            'name' => 'report.txt',
        ]));

        $this->assertSame('OPENAI-BYTES', $result['contents']);
        $this->assertSame('report.txt', $result['file_name']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/files/file-123/content'
                && $request->hasHeader('Authorization', 'Bearer sk-test');
        });
    }

    public function test_downloads_anthropic_file_with_x_api_key_and_version_headers(): void
    {
        Config::set('ai-engine.engines.anthropic.api_key', 'ant-test');
        Http::fake([
            'https://api.anthropic.com/*' => Http::response('ANTHROPIC-BYTES', 200),
        ]);

        $result = app(ProviderFileDownloadService::class)->download($this->artifact([
            'provider' => 'anthropic',
            'provider_file_id' => 'file_abc',
        ]));

        $this->assertSame('ANTHROPIC-BYTES', $result['contents']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/files/file_abc/content'
                && $request->hasHeader('x-api-key', 'ant-test')
                && $request->hasHeader('anthropic-version')
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_unconfigured_provider_with_file_id_falls_through_to_download_url(): void
    {
        Http::fake([
            'https://cdn.example.test/*' => Http::response('URL-BYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $result = app(ProviderFileDownloadService::class)->download($this->artifact([
            'provider' => 'some_new_provider',
            'provider_file_id' => 'xyz',
            'download_url' => 'https://cdn.example.test/asset.png',
        ]));

        $this->assertSame('URL-BYTES', $result['contents']);
    }

    public function test_throws_when_provider_api_key_missing(): void
    {
        Config::set('ai-engine.engines.openai.api_key', '');

        $this->expectException(AIEngineException::class);
        $this->expectExceptionMessage('Missing API key');

        app(ProviderFileDownloadService::class)->download($this->artifact([
            'provider' => 'openai',
            'provider_file_id' => 'file-123',
        ]));
    }

    public function test_throws_when_artifact_is_not_downloadable(): void
    {
        $this->expectException(AIEngineException::class);
        $this->expectExceptionMessage('not downloadable');

        app(ProviderFileDownloadService::class)->download($this->artifact([
            'provider' => 'openai',
        ]));
    }
}
