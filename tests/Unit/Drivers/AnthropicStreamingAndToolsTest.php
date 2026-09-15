<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;
use Psr\Http\Message\StreamInterface;

final class AnthropicStreamingAndToolsTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private function driver(Response ...$responses): AnthropicEngineDriver
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new AnthropicEngineDriver([
            'api_key' => 'test-key',
            'base_url' => 'https://api.anthropic.com',
        ], new Client(['handler' => $stack]));
    }

    private function sentPayload(int $index = 0): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    private function sse(array $events): string
    {
        $body = '';
        foreach ($events as $event) {
            $body .= 'event: ' . $event['type'] . "\n" . 'data: ' . json_encode($event) . "\n\n";
        }

        return $body;
    }

    private function toolStreamBody(): string
    {
        return $this->sse([
            ['type' => 'message_start', 'message' => ['id' => 'msg_stream', 'usage' => ['input_tokens' => 42, 'cache_read_input_tokens' => 30]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Looking up ']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'the invoice.']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'find_invoice', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"number": "IN']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'V-7", "include_lines": true}']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 17]],
            ['type' => 'message_stop'],
        ]);
    }

    public function test_stream_parses_events_split_across_tiny_chunks_and_surfaces_tool_calls(): void
    {
        $driver = $this->driver(new Response(200, ['Content-Type' => 'text/event-stream'], new TinyChunkStream($this->toolStreamBody(), 7)));

        $request = (new AIRequest('Find INV-7', EngineEnum::Anthropic, EntityEnum::CLAUDE_SONNET_5))
            ->withFunctions([[
                'name' => 'find_invoice',
                'description' => 'Find an invoice',
                'parameters' => ['type' => 'object', 'properties' => ['number' => ['type' => 'string']]],
            ]]);

        $stream = $driver->stream($request);
        $chunks = iterator_to_array($stream, false);
        /** @var AIResponse $response */
        $response = $stream->getReturn();

        self::assertSame(['Looking up ', 'the invoice.'], $chunks);
        self::assertInstanceOf(AIResponse::class, $response);
        self::assertSame('Looking up the invoice.', $response->getContent());
        self::assertSame('tool_use', $response->getFinishReason());

        $toolCalls = $response->getMetadata()['tool_calls'];
        self::assertCount(1, $toolCalls);
        self::assertSame('toolu_1', $toolCalls[0]['id']);
        self::assertSame('function', $toolCalls[0]['type']);
        self::assertSame('find_invoice', $toolCalls[0]['function']['name']);
        self::assertSame(['number' => 'INV-7', 'include_lines' => true], json_decode($toolCalls[0]['function']['arguments'], true));
        self::assertSame('find_invoice', $response->getFunctionCall()['name']);
        self::assertSame(['number' => 'INV-7', 'include_lines' => true], $response->getFunctionCall()['arguments']);

        $usage = $response->getUsage();
        self::assertSame(42, $usage['prompt_tokens']);
        self::assertSame(17, $usage['completion_tokens']);
        self::assertSame(30, $usage['cached_tokens']);

        // Streaming requests now carry tools too.
        $payload = $this->sentPayload();
        self::assertTrue($payload['stream']);
        self::assertSame('find_invoice', $payload['tools'][0]['name']);
        self::assertSame('object', $payload['tools'][0]['input_schema']['type']);
    }

    public function test_stream_handles_events_without_trailing_newline(): void
    {
        $body = rtrim($this->sse([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'tail']],
        ]));
        $driver = $this->driver(new Response(200, [], new TinyChunkStream($body, 1024)));

        $chunks = iterator_to_array($driver->stream(new AIRequest('hi', EngineEnum::Anthropic, EntityEnum::CLAUDE_SONNET_5)), false);

        self::assertSame(['tail'], $chunks);
    }

    public function test_non_streaming_response_surfaces_tool_use_blocks_and_all_text(): void
    {
        $driver = $this->driver(new Response(200, [], json_encode([
            'id' => 'msg_tools',
            'content' => [
                ['type' => 'text', 'text' => 'Sure. '],
                ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'find_invoice', 'input' => ['number' => 'INV-1']],
                ['type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'find_customer', 'input' => []],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
        ])));

        $response = $driver->generateText(new AIRequest('hi', EngineEnum::Anthropic, EntityEnum::CLAUDE_SONNET_5));

        self::assertSame('Sure. ', $response->getContent());
        $calls = $response->getMetadata()['tool_calls'];
        self::assertSame(['find_invoice', 'find_customer'], array_map(static fn (array $c): string => $c['function']['name'], $calls));
        self::assertSame('{}', $calls[1]['function']['arguments']);
        self::assertSame(['number' => 'INV-1'], $response->getFunctionCall()['arguments']);
    }

    public function test_payload_converts_functions_to_anthropic_tools_with_cache_breakpoints_and_tool_choice(): void
    {
        $driver = $this->driver(new Response(200, [], json_encode([
            'id' => 'msg', 'content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $request = (new AIRequest('hi', EngineEnum::Anthropic, EntityEnum::CLAUDE_SONNET_5))
            ->withSystemPrompt('Stable instructions.')
            ->withParameters(['parallel_tool_calls' => false])
            ->withFunctions([
                ['name' => 'alpha', 'description' => 'A', 'parameters' => ['type' => 'object', 'properties' => []]],
                ['type' => 'function', 'function' => ['name' => 'beta', 'parameters' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]]],
            ], 'auto');

        $driver->generateText($request);
        $payload = $this->sentPayload();

        self::assertArrayNotHasKey('functions', $payload);
        self::assertArrayNotHasKey('function_call', $payload);
        self::assertSame(['alpha', 'beta'], array_column($payload['tools'], 'name'));
        self::assertArrayNotHasKey('cache_control', $payload['tools'][0]);
        self::assertSame(['type' => 'ephemeral'], $payload['tools'][1]['cache_control']);
        self::assertSame('ephemeral', $payload['system'][0]['cache_control']['type']);
        self::assertSame(['type' => 'auto', 'disable_parallel_tool_use' => true], $payload['tool_choice']);
        self::assertIsInt($payload['max_tokens']);
        // Sonnet 5 rejects sampling parameters.
        self::assertArrayNotHasKey('temperature', $payload);
        // Stable prefix order: tools and system before messages.
        self::assertSame(['model', 'messages', 'max_tokens', 'system', 'tools', 'tool_choice'], array_keys($payload));
    }

    public function test_tool_cache_breakpoint_respects_prompt_caching_flag_and_legacy_models_keep_temperature(): void
    {
        config()->set('ai-engine.engines.anthropic.prompt_caching', false);
        $driver = $this->driver(new Response(200, [], json_encode([
            'id' => 'msg', 'content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $driver->generateText(
            (new AIRequest('hi', EngineEnum::Anthropic, EntityEnum::CLAUDE_3_5_SONNET))
                ->withFunctions([['name' => 'alpha', 'parameters' => ['type' => 'object', 'properties' => []]]], ['name' => 'alpha'])
        );
        $payload = $this->sentPayload();

        self::assertArrayNotHasKey('cache_control', $payload['tools'][0]);
        self::assertSame(['type' => 'tool', 'name' => 'alpha'], $payload['tool_choice']);
        self::assertSame(0.7, $payload['temperature']);
    }

    public function test_current_claude_models_are_the_defaults(): void
    {
        self::assertSame('claude-opus-5', EntityEnum::CLAUDE_OPUS_5);
        self::assertSame('claude-sonnet-5', EntityEnum::CLAUDE_SONNET_5);
        self::assertSame('claude-haiku-4-5-20251001', EntityEnum::CLAUDE_HAIKU_4_5);
        // Old ids stay available for hosts that reference them.
        self::assertSame('claude-3-5-sonnet-20240620', EntityEnum::CLAUDE_3_5_SONNET);

        self::assertSame('claude-sonnet-5', config('ai-engine.engines.anthropic.default_model'));
        self::assertSame('claude-sonnet-5', EngineEnum::Anthropic->getDefaultModels()[0]->value);

        $model = EntityEnum::from(EntityEnum::CLAUDE_OPUS_5);
        self::assertSame(EngineEnum::Anthropic, $model->engine());
        self::assertSame('text', $model->getContentType());

        $ids = array_column((new AnthropicEngineDriver(['api_key' => 'k']))->getAvailableModels(), 'id');
        self::assertContains('claude-opus-5', $ids);
        self::assertContains('claude-sonnet-5', $ids);
        self::assertContains('claude-haiku-4-5-20251001', $ids);
    }
}

/**
 * A stream that returns at most $size bytes per read regardless of the
 * requested length, simulating SSE events split across network reads.
 */
final class TinyChunkStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private string $contents, private int $size)
    {
    }

    public function __toString(): string { return $this->contents; }
    public function close(): void {}
    public function detach() { return null; }
    public function getSize(): ?int { return strlen($this->contents); }
    public function tell(): int { return $this->position; }
    public function eof(): bool { return $this->position >= strlen($this->contents); }
    public function isSeekable(): bool { return false; }
    public function seek(int $offset, int $whence = SEEK_SET): void { throw new \RuntimeException('not seekable'); }
    public function rewind(): void { throw new \RuntimeException('not seekable'); }
    public function isWritable(): bool { return false; }
    public function write(string $string): int { throw new \RuntimeException('not writable'); }
    public function isReadable(): bool { return true; }
    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, min($length, $this->size));
        $this->position += strlen($chunk);

        return $chunk;
    }
    public function getContents(): string
    {
        $rest = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);

        return $rest;
    }
    public function getMetadata(?string $key = null) { return $key === null ? [] : null; }
}
