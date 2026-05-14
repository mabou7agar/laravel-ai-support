<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Media;

use Mockery;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\AudioContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Audio\TranscriptionResponse;
use OpenAI\Responses\Chat\CreateResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Media\AudioService;
use LaravelAIEngine\Services\Media\VisionService;
use LaravelAIEngine\Tests\TestCase;

class MediaCreditAccountingTest extends TestCase
{
    public function test_audio_transcription_deducts_whisper_credits_through_credit_manager(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'whisper-1' => ['balance' => 100.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $audio = Mockery::mock(AudioContract::class);
        $audio->shouldReceive('transcribe')
            ->once()
            ->andReturn(TranscriptionResponse::fake(['text' => 'transcribed audio']));

        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('audio')->once()->andReturn($audio);

        $path = $this->temporaryFile('media-credit-test.mp3', str_repeat('a', 1024));

        $service = new AudioService($client, app(CreditManager::class));
        $this->assertSame('transcribed audio', $service->transcribe($path, (string) $user->id));

        $credits = app(CreditManager::class)->getUserCredits((string) $user->id, EngineEnum::OPENAI, EntityEnum::WHISPER_1);
        $this->assertEqualsWithDelta(99.966666, $credits['balance'], 0.0001);
    }

    public function test_audio_transcription_checks_credits_before_provider_call(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'whisper-1' => ['balance' => 0.01, 'is_unlimited' => false],
                ],
            ],
        ]);

        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('audio')->never();

        $path = $this->temporaryFile('media-credit-low.mp3', str_repeat('a', 1024));

        $this->expectException(InsufficientCreditsException::class);

        (new AudioService($client, app(CreditManager::class)))->transcribe($path, (string) $user->id);
    }

    public function test_audio_transcription_does_not_deduct_credits_when_provider_fails(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'whisper-1' => ['balance' => 100.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $audio = Mockery::mock(AudioContract::class);
        $audio->shouldReceive('transcribe')
            ->once()
            ->andThrow(new \RuntimeException('provider failed'));

        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('audio')->once()->andReturn($audio);

        $path = $this->temporaryFile('media-credit-provider-fail.mp3', str_repeat('a', 1024));

        try {
            (new AudioService($client, app(CreditManager::class)))->transcribe($path, (string) $user->id);
            $this->fail('Expected provider failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('provider failed', $exception->getMessage());
        }

        $credits = app(CreditManager::class)->getUserCredits((string) $user->id, EngineEnum::OPENAI, EntityEnum::WHISPER_1);
        $this->assertSame(100.0, $credits['balance']);
    }

    public function test_audio_language_detection_rethrows_insufficient_credits_before_provider_call(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'whisper-1' => ['balance' => 0.01, 'is_unlimited' => false],
                ],
            ],
        ]);

        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('audio')->never();

        $path = $this->temporaryFile('media-credit-language-low.mp3', str_repeat('a', 1024));

        $this->expectException(InsufficientCreditsException::class);

        (new AudioService($client, app(CreditManager::class)))->detectLanguage($path, (string) $user->id);
    }

    public function test_vision_analysis_deducts_usage_credits_through_credit_manager(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o' => ['balance' => 100.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $chat = Mockery::mock(ChatContract::class);
        $chat->shouldReceive('create')
            ->once()
            ->andReturn(CreateResponse::fake([
                'choices' => [
                    ['message' => ['content' => 'A small red square.']],
                ],
                'usage' => [
                    'prompt_tokens' => 2,
                    'completion_tokens' => 3,
                    'total_tokens' => 5,
                ],
            ]));

        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('chat')->once()->andReturn($chat);

        $path = $this->temporaryFile(
            'media-credit-test.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true)
        );

        $service = new VisionService($client, app(CreditManager::class));
        $this->assertSame('A small red square.', $service->analyzeImage($path, (string) $user->id));

        $credits = app(CreditManager::class)->getUserCredits((string) $user->id, EngineEnum::OPENAI, EntityEnum::GPT_4O);
        $this->assertSame(80.0, $credits['balance']);
    }

    public function test_vision_analysis_checks_credits_before_provider_call(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o' => ['balance' => 1.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('chat')->never();

        $path = $this->temporaryFile(
            'media-credit-low.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true)
        );

        $this->expectException(InsufficientCreditsException::class);

        (new VisionService($client, app(CreditManager::class)))->analyzeImage($path, (string) $user->id);
    }

    public function test_vision_comparison_checks_two_image_preflight_before_provider_call(): void
    {
        $user = $this->createTestUser([
            'entity_credits' => [
                'openai' => [
                    'gpt-4o' => ['balance' => 6.0, 'is_unlimited' => false],
                ],
            ],
        ]);

        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('chat')->never();

        $path1 = $this->temporaryFile(
            'media-credit-compare-low-1.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true)
        );
        $path2 = $this->temporaryFile(
            'media-credit-compare-low-2.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true)
        );

        $this->expectException(InsufficientCreditsException::class);

        (new VisionService($client, app(CreditManager::class)))->compareImages($path1, $path2, (string) $user->id);
    }

    private function temporaryFile(string $name, string $contents): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
