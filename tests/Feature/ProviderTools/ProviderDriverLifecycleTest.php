<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\ProviderTools;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver;
use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;

class ProviderDriverLifecycleTest extends TestCase
{
    public function test_openai_responses_tools_stop_for_approval_before_http_execution(): void
    {
        $driver = new OpenAIEngineDriver(config('ai-engine.engines.openai'), $this->emptyHttpClient());

        $response = $driver->generateText(new AIRequest(
            prompt: 'Open the browser and inspect the page.',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            functions: [[
                'type' => 'computer_use',
                'display_width' => 1024,
                'display_height' => 768,
                'environment' => 'browser',
            ]]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Provider tool run requires approval before execution.', $response->getContent());
        $this->assertDatabaseHas('ai_provider_tool_approvals', [
            'provider' => 'openai',
            'tool_name' => 'computer_use',
            'status' => 'pending',
        ]);
    }

    public function test_anthropic_provider_tools_stop_for_approval_before_http_execution(): void
    {
        $driver = new AnthropicEngineDriver(config('ai-engine.engines.anthropic'), $this->emptyHttpClient());

        $response = $driver->generateText(new AIRequest(
            prompt: 'Use the MCP server for this task.',
            engine: EngineEnum::ANTHROPIC,
            model: EntityEnum::CLAUDE_3_5_SONNET,
            functions: [[
                'type' => 'mcp_server',
                'label' => 'test-mcp',
                'url' => 'https://mcp.example.test',
            ]]
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Provider tool run requires approval before execution.', $response->getContent());
        $this->assertDatabaseHas('ai_provider_tool_approvals', [
            'provider' => 'anthropic',
            'tool_name' => 'mcp_server',
            'status' => 'pending',
        ]);
    }

    private function emptyHttpClient(): Client
    {
        return new Client([
            'handler' => HandlerStack::create(new MockHandler([])),
        ]);
    }
}
