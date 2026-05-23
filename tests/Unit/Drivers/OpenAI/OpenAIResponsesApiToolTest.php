<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Tools\Provider\CodeInterpreter;
use Mockery;

class OpenAIResponsesApiToolTest extends UnitTestCase
{
    public function test_openai_hosted_tools_use_responses_api_payload(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with('https://api.openai.test/v1/responses', Mockery::on(function (array $options): bool {
                return ($options['json']['tools'][0]['type'] ?? null) === 'code_interpreter'
                    && ($options['json']['input'][0]['role'] ?? null) === 'user';
            }))
            ->andReturn(new Response(200, [], json_encode([
                'id' => 'resp_1',
                'output_text' => 'done',
            ])));

        $driver = new OpenAIEngineDriver([
            'api_key' => 'test',
            'base_url' => 'https://api.openai.test/v1',
        ], $client);

        $response = $driver->generateText(
            (new AIRequest('Analyze this file', EngineEnum::OPENAI, EntityEnum::GPT_4O))
                ->withFunctions([(new CodeInterpreter())->toArray()])
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('done', $response->content);
    }

    public function test_openai_responses_api_merges_provider_options_and_remembers_response_state(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->twice()
            ->with('https://api.openai.test/v1/responses', Mockery::on(function (array $options): bool {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    return ($options['json']['background'] ?? null) === true
                        && ($options['json']['include'] ?? null) === ['reasoning.encrypted_content']
                        && !isset($options['json']['previous_response_id']);
                }

                return ($options['json']['previous_response_id'] ?? null) === 'resp_first'
                    && ($options['json']['store'] ?? null) === true;
            }))
            ->andReturn(
                new Response(200, [], json_encode(['id' => 'resp_first', 'output_text' => 'first'])),
                new Response(200, [], json_encode(['id' => 'resp_second', 'output_text' => 'second']))
            );

        $driver = new OpenAIEngineDriver([
            'api_key' => 'test',
            'base_url' => 'https://api.openai.test/v1',
        ], $client);

        $first = (new AIRequest('First', EngineEnum::OPENAI, EntityEnum::GPT_4O, conversationId: 'thread-1'))
            ->withMetadata(['openai_responses_api' => true])
            ->withProviderOptions([
                'background' => true,
                'include' => ['reasoning.encrypted_content'],
                'remember_response' => true,
            ], 'openai');

        $second = (new AIRequest('Second', EngineEnum::OPENAI, EntityEnum::GPT_4O, conversationId: 'thread-1'))
            ->withMetadata(['openai_responses_api' => true])
            ->withProviderOptions([
                'store' => true,
                'use_previous_response' => true,
            ], 'openai');

        $firstResponse = $driver->generateText($first);
        $secondResponse = $driver->generateText($second);

        $this->assertSame('resp_first', $firstResponse->getMetadata()['openai_response_id']);
        $this->assertSame('resp_second', $secondResponse->getMetadata()['openai_response_id']);
        $this->assertSame('resp_first', $secondResponse->getMetadata()['openai_previous_response_id']);
    }

    public function test_openai_responses_api_does_not_forward_internal_or_non_string_metadata(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with('https://api.openai.test/v1/responses', Mockery::on(function (array $options): bool {
                $metadata = $options['json']['metadata'] ?? [];

                return !array_key_exists('openai_responses_api', $metadata)
                    && ($metadata['trace_id'] ?? null) === 'trace-1'
                    && ($metadata['approved'] ?? null) === 'true'
                    && ($metadata['attempt'] ?? null) === '2';
            }))
            ->andReturn(new Response(200, [], json_encode([
                'id' => 'resp_1',
                'output_text' => 'ok',
            ])));

        $driver = new OpenAIEngineDriver([
            'api_key' => 'test',
            'base_url' => 'https://api.openai.test/v1',
        ], $client);

        $response = $driver->generateText(
            (new AIRequest('Use responses', EngineEnum::OPENAI, EntityEnum::GPT_4O))
                ->withMetadata([
                    'openai_responses_api' => true,
                    'trace_id' => 'trace-1',
                    'approved' => true,
                    'attempt' => 2,
                ])
        );

        $this->assertTrue($response->isSuccessful());
    }
}
