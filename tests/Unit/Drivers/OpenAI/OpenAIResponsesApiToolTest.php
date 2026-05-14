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
}
