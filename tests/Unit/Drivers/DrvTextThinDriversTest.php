<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Config;
use LaravelAIEngine\Drivers\Azure\AzureEngineDriver;
use LaravelAIEngine\Drivers\DeepSeek\DeepSeekEngineDriver;
use LaravelAIEngine\Drivers\Perplexity\PerplexityEngineDriver;
use LaravelAIEngine\Drivers\PlagiarismCheck\PlagiarismCheckEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;

/**
 * Functional tests (request building + response mapping) for thinly-tested
 * text/analysis drivers: Azure, DeepSeek, Perplexity, PlagiarismCheck.
 *
 * All HTTP is mocked with a Guzzle MockHandler — no real network calls.
 */
class DrvTextThinDriversTest extends UnitTestCase
{
    /**
     * Captured Guzzle history (one entry per request).
     *
     * @var array<int, array{request: PsrRequest}>
     */
    private array $drvTextHistory = [];

    /**
     * Build a Guzzle client backed by a MockHandler that returns the given
     * canned responses and records every outgoing request into history.
     *
     * @param  array<int, PsrResponse>  $responses
     */
    private function drvTextMockClient(array $responses): Client
    {
        $this->drvTextHistory = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->drvTextHistory));

        return new Client(['handler' => $stack]);
    }

    private function drvTextLastRequest(): PsrRequest
    {
        $last = end($this->drvTextHistory);

        return $last['request'];
    }

    /**
     * @return array<string, mixed>
     */
    private function drvTextLastRequestJson(): array
    {
        return json_decode((string) $this->drvTextLastRequest()->getBody(), true) ?? [];
    }

    // =====================================================================
    // DeepSeek
    // =====================================================================

    public function test_deepseek_generates_text_and_maps_usage_into_ai_response(): void
    {
        $driver = new DeepSeekEngineDriver([
            'api_key' => 'ds-key',
            'base_url' => 'https://api.deepseek.com',
        ]);

        $driver->setHttpClient($this->drvTextMockClient([
            new PsrResponse(200, [], json_encode([
                'id' => 'ds-chatcmpl-1',
                'model' => 'deepseek-chat',
                'choices' => [[
                    'message' => ['content' => 'Hello from DeepSeek.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
            ])),
        ]));

        $response = $driver->generateText(new AIRequest(
            prompt: 'Say hello.',
            engine: EngineEnum::DeepSeek,
            model: EntityEnum::from(EntityEnum::DEEPSEEK_CHAT),
            systemPrompt: 'You are concise.'
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Hello from DeepSeek.', $response->getContent());
        $this->assertSame('ds-chatcmpl-1', $response->getRequestId());
        $this->assertSame('stop', $response->getFinishReason());
        $this->assertSame(10, $response->getTokensUsed());

        $request = $this->drvTextLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/chat/completions', (string) $request->getUri());

        $payload = $this->drvTextLastRequestJson();
        $this->assertSame('deepseek-chat', $payload['model']);
        $this->assertFalse($payload['stream']);
        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertSame('You are concise.', $payload['messages'][0]['content']);
        $this->assertSame('user', $payload['messages'][1]['role']);
        $this->assertSame('Say hello.', $payload['messages'][1]['content']);
    }

    public function test_deepseek_returns_error_response_when_http_client_throws(): void
    {
        $driver = new DeepSeekEngineDriver([
            'api_key' => 'ds-key',
            'base_url' => 'https://api.deepseek.com',
        ]);

        $driver->setHttpClient($this->drvTextMockClient([
            new PsrResponse(500, [], json_encode(['error' => 'boom'])),
        ]));

        // handleApiError re-throws as RuntimeException for the failover layer.
        $this->expectException(\RuntimeException::class);

        $driver->generateText(new AIRequest(
            prompt: 'Say hello.',
            engine: EngineEnum::DeepSeek,
            model: EntityEnum::from(EntityEnum::DEEPSEEK_CHAT)
        ));
    }

    // =====================================================================
    // Perplexity
    // =====================================================================

    public function test_perplexity_generates_text_with_search_params_and_maps_response(): void
    {
        $driver = new PerplexityEngineDriver([
            'api_key' => 'pplx-key',
            'base_url' => 'https://api.perplexity.ai',
        ]);

        // No-citation path: maps content, finish_reason and usage correctly.
        $driver->setHttpClient($this->drvTextMockClient([
            new PsrResponse(200, [], json_encode([
                'id' => 'pplx-1',
                'model' => 'perplexity-sonar-large',
                'choices' => [[
                    'message' => ['content' => 'Researched answer.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 12, 'total_tokens' => 20],
            ])),
        ]));

        $response = $driver->generateText(new AIRequest(
            prompt: 'What is new?',
            engine: EngineEnum::Perplexity,
            model: EntityEnum::from(EntityEnum::PERPLEXITY_SONAR_LARGE)
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Researched answer.', $response->getContent());
        $this->assertSame('pplx-1', $response->getRequestId());
        $this->assertSame('stop', $response->getFinishReason());
        $this->assertSame(20, $response->getTokensUsed());

        $payload = $this->drvTextLastRequestJson();
        $this->assertSame('perplexity-sonar-large', $payload['model']);
        $this->assertFalse($payload['stream']);
        $this->assertTrue($payload['return_citations']);
        $this->assertSame('month', $payload['search_recency_filter']);
        $this->assertSame('user', $payload['messages'][0]['role']);
        $this->assertSame('What is new?', $payload['messages'][0]['content']);

        $request = $this->drvTextLastRequest();
        $this->assertStringContainsString('/chat/completions', (string) $request->getUri());
    }

    public function test_perplexity_merges_citations_into_usage_when_present(): void
    {
        $driver = new PerplexityEngineDriver([
            'api_key' => 'pplx-key',
            'base_url' => 'https://api.perplexity.ai',
        ]);

        // Citation path: previously crashed on the non-existent
        // AIResponse::getDetailedUsage(); the driver now reads getUsage() and
        // merges the citation metadata back via withDetailedUsage().
        $driver->setHttpClient($this->drvTextMockClient([
            new PsrResponse(200, [], json_encode([
                'id' => 'pplx-2',
                'model' => 'perplexity-sonar-large',
                'choices' => [[
                    'message' => ['content' => 'Cited answer.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 12, 'total_tokens' => 20],
                'citations' => [
                    'https://example.com/a',
                    'https://example.com/b',
                ],
            ])),
        ]));

        $response = $driver->generateText(new AIRequest(
            prompt: 'What is new?',
            engine: EngineEnum::Perplexity,
            model: EntityEnum::from(EntityEnum::PERPLEXITY_SONAR_LARGE)
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('Cited answer.', $response->getContent());

        $usage = $response->getUsage();
        $this->assertSame([
            'https://example.com/a',
            'https://example.com/b',
        ], $usage['citations']);
        $this->assertSame(2, $usage['sources_count']);
        $this->assertTrue($usage['search_performed']);
    }

    public function test_perplexity_web_search_returns_error_response_on_failure(): void
    {
        $driver = new PerplexityEngineDriver([
            'api_key' => 'pplx-key',
            'base_url' => 'https://api.perplexity.ai',
        ]);

        $driver->setHttpClient($this->drvTextMockClient([
            new PsrResponse(500, [], 'server error'),
        ]));

        // webSearch swallows the exception and returns an error AIResponse.
        $response = $driver->webSearch(new AIRequest(
            prompt: 'breaking news',
            engine: EngineEnum::Perplexity,
            model: EntityEnum::from(EntityEnum::PERPLEXITY_SONAR_LARGE)
        ));

        $this->assertFalse($response->isSuccessful());
        $this->assertStringContainsString('Perplexity web search error', (string) $response->getError());
    }

    // =====================================================================
    // Azure (config-constructed client swapped via reflection)
    // =====================================================================

    private function drvTextSwapClient(object $driver, Client $client): void
    {
        $ref = new \ReflectionProperty($driver, 'client');
        $ref->setAccessible(true);
        $ref->setValue($driver, $client);
    }

    public function test_azure_translator_builds_correct_request(): void
    {
        Config::set('ai-engine.engines.azure.api_key', 'azure-key');
        Config::set('ai-engine.engines.azure.region', 'eastus');

        $driver = new AzureEngineDriver();
        $this->drvTextSwapClient($driver, $this->drvTextMockClient([
            new PsrResponse(200, [], json_encode([
                [
                    'detectedLanguage' => ['language' => 'en', 'score' => 0.98],
                    'translations' => [
                        ['text' => 'Hola mundo', 'to' => 'es'],
                    ],
                ],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'Hello world',
            engine: EngineEnum::Azure,
            model: EntityEnum::from(EntityEnum::AZURE_TRANSLATOR),
            parameters: ['target_language' => 'es']
        ));
        $this->assertTrue($response->isSuccessful());

        $request = $this->drvTextLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $uri = (string) $request->getUri();
        $this->assertStringContainsString('/translator/text/v3.0/translate', $uri);
        $this->assertStringContainsString('to=es', $uri);

        $payload = json_decode((string) $request->getBody(), true);
        $this->assertSame('Hello world', $payload[0]['text']);
    }

    public function test_azure_text_analytics_sentiment_builds_correct_request(): void
    {
        Config::set('ai-engine.engines.azure.api_key', 'azure-key');
        Config::set('ai-engine.engines.azure.region', 'eastus');

        $driver = new AzureEngineDriver();
        $this->drvTextSwapClient($driver, $this->drvTextMockClient([
            new PsrResponse(200, [], json_encode([
                'documents' => [
                    ['id' => '1', 'sentiment' => 'positive', 'language' => 'en'],
                ],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'I love this',
            engine: EngineEnum::Azure,
            model: EntityEnum::from(EntityEnum::AZURE_TEXT_ANALYTICS),
            parameters: ['analysis_type' => 'sentiment']
        ));
        $this->assertTrue($response->isSuccessful());

        $request = $this->drvTextLastRequest();
        $this->assertStringContainsString('/text/analytics/v3.1/sentiment', (string) $request->getUri());

        $payload = json_decode((string) $request->getBody(), true);
        $this->assertSame('I love this', $payload['documents'][0]['text']);
        $this->assertSame('en', $payload['documents'][0]['language']);
    }

    public function test_azure_constructor_requires_api_key(): void
    {
        Config::set('ai-engine.engines.azure.api_key', null);

        $this->expectException(\LaravelAIEngine\Exceptions\AIEngineException::class);

        new AzureEngineDriver();
    }

    // =====================================================================
    // PlagiarismCheck (config-constructed client swapped via reflection)
    // =====================================================================

    public function test_plagiarism_basic_builds_correct_request(): void
    {
        Config::set('ai-engine.engines.plagiarism_check.api_key', 'plag-key');

        $driver = new PlagiarismCheckEngineDriver();
        $this->drvTextSwapClient($driver, $this->drvTextMockClient([
            new PsrResponse(200, [], json_encode([
                'similarity_percentage' => 30,
                'word_count' => 4,
                'character_count' => 20,
                'check_id' => 'check-123',
                'sources' => [
                    ['title' => 'Src A', 'url' => 'https://a.example', 'similarity' => 30, 'type' => 'web'],
                ],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'some sample text',
            engine: EngineEnum::PlagiarismCheck,
            model: EntityEnum::from(EntityEnum::PLAGIARISM_BASIC)
        ));
        $this->assertTrue($response->isSuccessful());

        $request = $this->drvTextLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/v1/check', (string) $request->getUri());

        $payload = json_decode((string) $request->getBody(), true);
        $this->assertSame('some sample text', $payload['text']);
        $this->assertSame('basic', $payload['check_type']);
        $this->assertSame('en', $payload['language']);
    }

    public function test_plagiarism_advanced_uses_advanced_endpoint_and_params(): void
    {
        Config::set('ai-engine.engines.plagiarism_check.api_key', 'plag-key');

        $driver = new PlagiarismCheckEngineDriver();
        $this->drvTextSwapClient($driver, $this->drvTextMockClient([
            new PsrResponse(200, [], json_encode([
                'similarity_percentage' => 5,
                'sources' => [],
            ])),
        ]));

        $response = $driver->generate(new AIRequest(
            prompt: 'unique original writing',
            engine: EngineEnum::PlagiarismCheck,
            model: EntityEnum::from(EntityEnum::PLAGIARISM_ADVANCED)
        ));
        $this->assertTrue($response->isSuccessful());

        $request = $this->drvTextLastRequest();
        $this->assertStringContainsString('/v1/check/advanced', (string) $request->getUri());

        $payload = json_decode((string) $request->getBody(), true);
        $this->assertSame('advanced', $payload['check_type']);
        $this->assertTrue($payload['check_paraphrasing']);
    }

    public function test_plagiarism_constructor_requires_api_key(): void
    {
        Config::set('ai-engine.engines.plagiarism_check.api_key', null);

        $this->expectException(\LaravelAIEngine\Exceptions\AIEngineException::class);

        new PlagiarismCheckEngineDriver();
    }
}
