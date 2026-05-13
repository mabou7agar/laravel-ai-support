<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\Services\EngineProxy;
use LaravelAIEngine\Services\UnifiedEngineManager;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Tools\Provider\FileSearch;
use LaravelAIEngine\Tools\Provider\WebFetch;
use LaravelAIEngine\Tools\Provider\WebSearch;

class ProviderToolsTest extends UnitTestCase
{
    public function test_provider_tools_serialize_to_driver_payload_shape(): void
    {
        $search = (new WebSearch())->max(3)->allow(['laravel.com'])->location(country: 'US');
        $fetch = (new WebFetch())->max(2)->allow(['docs.laravel.com']);
        $files = (new FileSearch(['store_1']))->where(['status' => 'published']);

        $this->assertSame('web_search', $search->toArray()['type']);
        $this->assertSame(['laravel.com'], $search->toArray()['allow']);
        $this->assertSame('web_fetch', $fetch->toArray()['type']);
        $this->assertSame('file_search', $files->toArray()['type']);
        $this->assertSame(['status' => 'published'], $files->toArray()['where']);
    }

    public function test_engine_proxy_accepts_provider_tools_as_functions(): void
    {
        $fake = app(UnifiedEngineManager::class)->fake(['ok']);

        (new EngineProxy($fake))
            ->withTools([new WebSearch()])
            ->generate('Find current Laravel AI docs');

        $this->assertCount(1, $fake->requests());
        $fake->assertPrompted(function (array $request): bool {
            return ($request['options']['functions'][0]['type'] ?? null) === 'web_search';
        });
    }
}
