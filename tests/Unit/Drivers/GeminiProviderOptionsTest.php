<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Drivers\Gemini\GeminiEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class GeminiProviderOptionsTest extends UnitTestCase
{
    public function test_provider_options_are_merged_into_gemini_payload_and_query(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with('/v1beta/models/gemini-2.5-flash-preview-04-17:generateContent', Mockery::on(function (array $options): bool {
                return ($options['json']['generationConfig']['candidateCount'] ?? null) === 2
                    && ($options['json']['safetySettings'][0]['category'] ?? null) === 'HARM_CATEGORY_DANGEROUS_CONTENT'
                    && ($options['query']['key'] ?? null) === 'test-gemini-key'
                    && ($options['query']['alt'] ?? null) === 'json';
            }))
            ->andReturn(new Response(200, [], json_encode([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'ok']]],
                ]],
                'usageMetadata' => ['totalTokenCount' => 2],
            ])));

        $driver = new GeminiEngineDriver([
            'api_key' => 'test-gemini-key',
            'base_url' => 'https://generativelanguage.googleapis.com',
        ], $client);

        $response = $driver->generateText(
            (new AIRequest('Hello', EngineEnum::GEMINI, EntityEnum::GEMINI_2_5_FLASH))
                ->withProviderOptions([
                    'generationConfig' => ['candidateCount' => 2],
                    'safetySettings' => [[
                        'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                        'threshold' => 'BLOCK_ONLY_HIGH',
                    ]],
                    'query' => ['alt' => 'json'],
                ], 'gemini')
        );

        $this->assertTrue($response->isSuccessful());
    }
}
