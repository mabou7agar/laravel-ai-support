<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\Services\EngineProxy;
use LaravelAIEngine\Services\UnifiedEngineManager;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Tools\Provider\CodeInterpreter;
use LaravelAIEngine\Tools\Provider\ComputerUse;
use LaravelAIEngine\Tools\Provider\FileSearch;
use LaravelAIEngine\Tools\Provider\GoogleMapsGrounding;
use LaravelAIEngine\Tools\Provider\McpServer;
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

    public function test_advanced_provider_tools_serialize_to_driver_payload_shape(): void
    {
        $code = (new CodeInterpreter())->withFiles(['file_1'])->memoryLimit('2g');
        $computer = (new ComputerUse())->display(1280, 720)->environment('browser');
        $mcp = new McpServer('github', 'https://api.githubcopilot.com/mcp/');
        $maps = (new GoogleMapsGrounding())->widget()->location(30.0444, 31.2357);

        $this->assertSame('code_interpreter', $code->toArray()['type']);
        $this->assertSame(['file_1'], $code->toArray()['file_ids']);
        $this->assertSame('computer_use', $computer->toArray()['type']);
        $this->assertSame(1280, $computer->toArray()['display_width']);
        $this->assertSame('mcp_server', $mcp->toArray()['type']);
        $this->assertSame('google_maps', $maps->toArray()['type']);
        $this->assertTrue($maps->toArray()['enable_widget']);
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
