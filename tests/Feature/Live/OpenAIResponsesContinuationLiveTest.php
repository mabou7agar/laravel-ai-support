<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Live;

use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;

class OpenAIResponsesContinuationLiveTest extends TestCase
{
    public function test_openai_responses_api_can_continue_from_remembered_response_when_enabled(): void
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_LIVE_TESTS')) {
            $this->markTestSkipped('Set AI_ENGINE_RUN_LIVE_TESTS=true to enable billed live OpenAI Responses checks.');
        }

        $apiKey = getenv('OPENAI_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            $this->markTestSkipped('Missing required live provider credential [OPENAI_API_KEY].');
        }

        config()->set('ai-engine.engines.openai.api_key', $apiKey);
        config()->set('ai-engine.provider_tools.lifecycle.enabled', false);

        $model = (string) (getenv('AI_ENGINE_LIVE_OPENAI_RESPONSES_MODEL') ?: EntityEnum::GPT_4O_MINI);
        $conversationId = 'live-openai-responses-' . bin2hex(random_bytes(6));
        $driver = new OpenAIEngineDriver([
            'api_key' => $apiKey,
            'base_url' => 'https://api.openai.com/v1',
            'timeout' => 45,
        ]);

        $first = (new AIRequest(
            prompt: 'Remember this code word for one turn: cobalt. Reply with only: remembered',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::from($model),
            conversationId: $conversationId
        ))
            ->withMetadata(['openai_responses_api' => true])
            ->withProviderOptions([
                'store' => true,
                'remember_response' => true,
                'max_output_tokens' => 12,
            ], 'openai');

        $second = (new AIRequest(
            prompt: 'What was the code word? Reply with only the code word.',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::from($model),
            conversationId: $conversationId
        ))
            ->withMetadata(['openai_responses_api' => true])
            ->withProviderOptions([
                'store' => true,
                'use_previous_response' => true,
                'max_output_tokens' => 12,
            ], 'openai');

        $firstResponse = $driver->generateText($first);
        $secondResponse = $driver->generateText($second);

        $this->assertTrue($firstResponse->isSuccessful(), $firstResponse->content);
        $this->assertTrue($secondResponse->isSuccessful(), $secondResponse->content);
        $this->assertNotEmpty($firstResponse->getMetadata()['openai_response_id'] ?? null);
        $this->assertSame(
            $firstResponse->getMetadata()['openai_response_id'],
            $secondResponse->getMetadata()['openai_previous_response_id'] ?? null
        );
        $this->assertStringContainsString('cobalt', strtolower($secondResponse->content));
    }

    private function readBoolEnv(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
