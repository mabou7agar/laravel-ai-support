<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Services\SDK\ProviderToolPayloadMapper;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Tools\Provider\FileSearch;
use LaravelAIEngine\Tools\Provider\WebFetch;
use LaravelAIEngine\Tools\Provider\WebSearch;

class ProviderToolPayloadMapperTest extends UnitTestCase
{
    public function test_maps_openai_provider_tools_without_losing_custom_functions(): void
    {
        $mapper = new ProviderToolPayloadMapper();

        $split = $mapper->splitForProvider(EngineEnum::OPENAI, [
            (new WebSearch())->location(country: 'US')->toArray(),
            (new FileSearch(['store_1']))->where(['status' => 'published'])->toArray(),
            [
                'name' => 'lookup_order',
                'description' => 'Lookup an order',
                'parameters' => ['type' => 'object'],
            ],
        ]);

        $this->assertSame('lookup_order', $split['functions'][0]['name']);
        $this->assertSame('web_search_preview', $split['tools'][0]['type']);
        $this->assertSame('file_search', $split['tools'][1]['type']);
        $this->assertSame(['store_1'], $split['tools'][1]['vector_store_ids']);
    }

    public function test_maps_anthropic_and_gemini_native_tool_shapes(): void
    {
        $mapper = new ProviderToolPayloadMapper();

        $anthropic = $mapper->splitForProvider(EngineEnum::ANTHROPIC, [
            (new WebSearch())->max(2)->allow(['laravel.com'])->toArray(),
            (new WebFetch())->allow(['docs.laravel.com'])->toArray(),
        ]);

        $this->assertSame('web_search_20250305', $anthropic['tools'][0]['type']);
        $this->assertSame('web_fetch', $anthropic['tools'][1]['type']);

        $gemini = $mapper->splitForProvider(EngineEnum::GEMINI, [
            (new WebSearch())->toArray(),
            (new WebFetch())->toArray(),
        ]);

        $this->assertArrayHasKey('googleSearch', $gemini['tools'][0]);
        $this->assertArrayHasKey('urlContext', $gemini['tools'][1]);
    }
}
