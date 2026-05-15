<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Support;

use Illuminate\Support\Facades\Config;
use LaravelAIEngine\Services\Media\AudioService;
use LaravelAIEngine\Services\Media\VisionService;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Tests\UnitTestCase;
use OpenAI\Contracts\ClientContract;

class CoreServiceRegistrarTest extends UnitTestCase
{
    public function test_openai_backed_services_resolve_without_openai_key(): void
    {
        Config::set('ai-engine.engines.openai.api_key', null);
        $this->app->forgetInstance(ClientContract::class);
        $this->app->forgetInstance(EmbeddingService::class);
        $this->app->forgetInstance(AudioService::class);
        $this->app->forgetInstance(VisionService::class);

        $this->assertInstanceOf(EmbeddingService::class, app(EmbeddingService::class));
        $this->assertInstanceOf(AudioService::class, app(AudioService::class));
        $this->assertInstanceOf(VisionService::class, app(VisionService::class));
    }

    public function test_missing_openai_key_fails_only_when_openai_client_is_used(): void
    {
        Config::set('ai-engine.engines.openai.api_key', null);
        $this->app->forgetInstance(ClientContract::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key is not configured');

        app(ClientContract::class)->chat();
    }
}
