<?php

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Support\Fal\FalCharacterStore;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class GenerateTtsApiTest extends TestCase
{
    public function test_tts_endpoint_uses_saved_character_voice_profile(): void
    {
        app(FalCharacterStore::class)->save([
            'name' => 'Mina',
            'voice_id' => 'voice-mina',
            'voice_settings' => [
                'stability' => 0.22,
                'similarity_boost' => 0.88,
                'style' => 0.41,
                'use_speaker_boost' => false,
            ],
        ], 'mina');

        $service = Mockery::mock(AIEngineService::class);
        $service->shouldReceive('generateDirect')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame('eleven_labs', $request->getEngine()->value);
                $this->assertSame('eleven_multilingual_v2', $request->getModel()->value);
                $this->assertSame('voice-mina', $request->getParameters()['voice_id']);
                $this->assertSame(0.22, $request->getParameters()['stability']);
                $this->assertSame(0.88, $request->getParameters()['similarity_boost']);
                $this->assertSame(0.41, $request->getParameters()['style']);
                $this->assertFalse($request->getParameters()['use_speaker_boost']);

                return true;
            })
            ->andReturn(AIResponse::success('ok', 'eleven_labs', 'eleven_multilingual_v2'));

        $this->app->instance(AIEngineService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/tts', [
            'text' => 'Hello from Mina',
            'use_character' => 'mina',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.model', 'eleven_multilingual_v2');
    }

    public function test_tts_endpoint_allows_explicit_voice_options_to_override_saved_character_defaults(): void
    {
        app(FalCharacterStore::class)->save([
            'name' => 'Mina',
            'voice_id' => 'voice-mina',
            'voice_settings' => [
                'stability' => 0.22,
                'similarity_boost' => 0.88,
            ],
        ], 'mina');

        $service = Mockery::mock(AIEngineService::class);
        $service->shouldReceive('generateDirect')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame('voice-override', $request->getParameters()['voice_id']);
                $this->assertSame(0.55, $request->getParameters()['stability']);
                $this->assertSame(0.88, $request->getParameters()['similarity_boost']);

                return true;
            })
            ->andReturn(AIResponse::success('ok', 'eleven_labs', 'eleven_multilingual_v2'));

        $this->app->instance(AIEngineService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/tts', [
            'text' => 'Override the voice.',
            'use_character' => 'mina',
            'voice_id' => 'voice-override',
            'stability' => 0.55,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_tts_endpoint_can_use_last_saved_character_voice_profile(): void
    {
        app(FalCharacterStore::class)->save([
            'name' => 'Ray',
            'voice_id' => 'voice-ray',
        ], 'ray');

        $service = Mockery::mock(AIEngineService::class);
        $service->shouldReceive('generateDirect')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame('voice-ray', $request->getParameters()['voice_id']);

                return true;
            })
            ->andReturn(AIResponse::success('ok', 'eleven_labs', 'eleven_multilingual_v2'));

        $this->app->instance(AIEngineService::class, $service);

        $response = $this->postJson('/api/v1/ai/generate/tts', [
            'text' => 'Hello from Ray',
            'use_last_character' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_tts_endpoint_returns_422_when_saved_character_has_no_voice_metadata(): void
    {
        app(FalCharacterStore::class)->save([
            'name' => 'Silent Mina',
            'frontal_image_url' => 'https://example.com/mina.png',
        ], 'silent-mina');

        $response = $this->postJson('/api/v1/ai/generate/tts', [
            'text' => 'This should fail.',
            'use_character' => 'silent-mina',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.message', 'Saved character [silent-mina] does not have voice metadata.');
    }
}
