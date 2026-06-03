<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LaravelAIEngine\Drivers\DeepSeek\DeepSeekEngineDriver;
use LaravelAIEngine\Drivers\Perplexity\PerplexityEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\Support\Stubs\ChunkedStream;
use LaravelAIEngine\Tests\UnitTestCase;

class PerplexityDeepSeekStreamTest extends UnitTestCase
{
    /**
     * Build SSE chunks that are intentionally split across read boundaries:
     * lines are broken mid-way and multiple lines packed per chunk, so a
     * naive fixed-read parser would corrupt the json_decode of each event.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function splitSseChunks(): array
    {
        $events = [
            'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hello']]]]),
            'data: ' . json_encode(['choices' => [['delta' => ['content' => ', ']]]]),
            'data: ' . json_encode(['choices' => [['delta' => ['content' => 'world']]]]),
            'data: ' . json_encode(['choices' => [['delta' => ['content' => '!']]]]),
            'data: [DONE]',
        ];

        $raw = implode("\n", $events) . "\n";

        // Split the raw payload into awkward 7-byte chunks so individual SSE
        // events span multiple reads and several events share a single read.
        $chunks = str_split($raw, 7);

        return [$chunks, 'Hello, world!'];
    }

    public function test_perplexity_stream_yields_intact_content_across_split_reads(): void
    {
        [$chunks, $expected] = $this->splitSseChunks();

        $driver = new PerplexityEngineDriver(['api_key' => 'pplx-key']);
        $driver->setHttpClient($this->mockClient($chunks));

        $request = new AIRequest(
            prompt: 'Say hello.',
            engine: EngineEnum::Perplexity,
            model: EntityEnum::PERPLEXITY_SONAR_SMALL,
        );

        $output = '';
        foreach ($driver->generateTextStream($request) as $chunk) {
            $output .= $chunk;
        }

        $this->assertSame($expected, $output);
    }

    public function test_deepseek_stream_yields_intact_content_across_split_reads(): void
    {
        [$chunks, $expected] = $this->splitSseChunks();

        $driver = new DeepSeekEngineDriver(['api_key' => 'ds-key']);
        $driver->setHttpClient($this->mockClient($chunks));

        $request = new AIRequest(
            prompt: 'Say hello.',
            engine: EngineEnum::DeepSeek,
            model: EntityEnum::DEEPSEEK_CHAT,
        );

        $output = '';
        foreach ($driver->generateTextStream($request) as $chunk) {
            $output .= $chunk;
        }

        $this->assertSame($expected, $output);
    }

    /**
     * @param  list<string>  $chunks
     */
    private function mockClient(array $chunks): Client
    {
        $mock = new MockHandler([
            new GuzzleResponse(200, [], new ChunkedStream($chunks)),
        ]);

        return new Client(['handler' => HandlerStack::create($mock)]);
    }
}
