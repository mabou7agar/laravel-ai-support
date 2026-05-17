<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Services\SDK\ProviderToolPayloadMapper;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Tools\Provider\ApplyPatchTool;
use LaravelAIEngine\Tools\Provider\CodeInterpreter;
use LaravelAIEngine\Tools\Provider\ComputerUse;
use LaravelAIEngine\Tools\Provider\FileSearch;
use LaravelAIEngine\Tools\Provider\GoogleMapsGrounding;
use LaravelAIEngine\Tools\Provider\HostedShell;
use LaravelAIEngine\Tools\Provider\McpServer;
use LaravelAIEngine\Tools\Provider\ProviderSkill;
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

    public function test_maps_code_computer_mcp_and_maps_tools(): void
    {
        $mapper = new ProviderToolPayloadMapper();

        $openai = $mapper->splitForProvider(EngineEnum::OPENAI, [
            (new CodeInterpreter())->withFiles(['file_1'])->toArray(),
            (new ComputerUse())->display(1440, 900)->toArray(),
            (new McpServer('github', 'https://api.githubcopilot.com/mcp/'))->toArray(),
        ]);

        $this->assertSame('code_interpreter', $openai['tools'][0]['type']);
        $this->assertSame(['file_1'], $openai['tools'][0]['container']['file_ids']);
        $this->assertSame('computer_use_preview', $openai['tools'][1]['type']);
        $this->assertSame('mcp', $openai['tools'][2]['type']);

        $anthropic = $mapper->splitForProvider(EngineEnum::ANTHROPIC, [
            (new CodeInterpreter())->toArray(),
            (new ComputerUse())->toArray(),
            (new McpServer('linear', 'https://mcp.linear.app/sse'))->authorizationToken('token')->toArray(),
        ]);

        $this->assertSame('code_execution_20250825', $anthropic['tools'][0]['type']);
        $this->assertSame('computer_20250124', $anthropic['tools'][1]['type']);
        $this->assertSame('linear', $anthropic['mcp_servers'][0]['name']);
        $this->assertContains('mcp-client-2025-04-04', $anthropic['beta_headers']);

        $gemini = $mapper->splitForProvider(EngineEnum::GEMINI, [
            (new GoogleMapsGrounding())->widget()->location(30.0444, 31.2357)->toArray(),
        ]);

        $this->assertArrayHasKey('googleMaps', $gemini['tools'][0]);
        $this->assertTrue($gemini['tools'][0]['googleMaps']['enableWidget']);
        $this->assertSame(30.0444, $gemini['tool_config']['toolConfig']['retrievalConfig']['latLng']['latitude']);
    }

    public function test_maps_new_provider_hosted_tool_shapes(): void
    {
        $mapper = new ProviderToolPayloadMapper();

        $openai = $mapper->splitForProvider(EngineEnum::OPENAI, [
            (new HostedShell())->container(['type' => 'auto', 'memory_limit' => '2g'])->toArray(),
            (new ApplyPatchTool())->workspace('/workspace/app')->toArray(),
            (new ProviderSkill('invoice_planner'))->version('v1')->inputSchema(['type' => 'object'])->toArray(),
        ]);

        $this->assertSame('hosted_shell', $openai['tools'][0]['type']);
        $this->assertSame('2g', $openai['tools'][0]['container']['memory_limit']);
        $this->assertSame('apply_patch', $openai['tools'][1]['type']);
        $this->assertSame('/workspace/app', $openai['tools'][1]['workspace']);
        $this->assertSame('skill', $openai['tools'][2]['type']);
        $this->assertSame('invoice_planner', $openai['tools'][2]['name']);

        $gemini = $mapper->splitForProvider(EngineEnum::GEMINI, [
            (new CodeInterpreter())->toArray(),
        ]);

        $this->assertArrayHasKey('codeExecution', $gemini['tools'][0]);
    }
}
