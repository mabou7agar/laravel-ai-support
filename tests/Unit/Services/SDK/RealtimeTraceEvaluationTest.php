<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\DTOs\RealtimeSessionConfig;
use LaravelAIEngine\Services\SDK\EvaluationService;
use LaravelAIEngine\Services\SDK\RealtimeSessionService;
use LaravelAIEngine\Services\SDK\TraceRecorderService;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Tools\Provider\WebSearch;

class RealtimeTraceEvaluationTest extends UnitTestCase
{
    public function test_realtime_service_builds_openai_and_gemini_session_descriptors(): void
    {
        $service = new RealtimeSessionService();

        $openai = $service->create(
            RealtimeSessionConfig::make('openai', 'gpt-4o-realtime-preview')
                ->withVoice('alloy')
                ->withAudioFormats('pcm16', 'pcm16')
                ->withTurnDetection(['type' => 'server_vad'])
                ->withTemperature(0.2)
                ->withMaxResponseOutputTokens(512)
                ->withTools([(new WebSearch())->toArray()])
        );

        $gemini = $service->create([
            'provider' => 'gemini',
            'model' => 'gemini-live-2.5-flash-preview',
            'modalities' => ['audio'],
        ]);

        $this->assertSame('/realtime/client_secrets', $openai['endpoint']);
        $this->assertSame('realtime', $openai['session']['type']);
        $this->assertSame(['audio'], $openai['session']['output_modalities']);
        $this->assertSame('alloy', $openai['session']['audio']['output']['voice']);
        $this->assertSame('alloy', $openai['payload']['voice']);
        $this->assertSame('pcm16', $openai['payload']['input_audio_format']);
        $this->assertSame('server_vad', $openai['payload']['turn_detection']['type']);
        $this->assertSame(0.2, $openai['payload']['temperature']);
        $this->assertSame(512, $openai['payload']['max_response_output_tokens']);
        $this->assertStringContainsString('BidiGenerateContent', $gemini['endpoint']);
        $this->assertSame(['audio'], $gemini['payload']['responseModalities']);
    }

    public function test_realtime_service_builds_openai_transcription_session(): void
    {
        config()->set('ai-engine.realtime.openai.transcription_model', 'gpt-realtime-whisper');

        $session = (new RealtimeSessionService())->create(
            RealtimeSessionConfig::make('openai', 'gpt-realtime')
                ->transcription()
                ->withInputAudioTranscription(['language' => 'ar', 'delay' => 'low'])
                ->withAudioFormats('pcm16')
        );

        $this->assertSame('transcription', $session['session']['type']);
        $this->assertSame('gpt-realtime-whisper', $session['session']['audio']['input']['transcription']['model']);
        $this->assertSame('ar', $session['session']['audio']['input']['transcription']['language']);
        $this->assertSame('low', $session['session']['audio']['input']['transcription']['delay']);
        $this->assertSame('audio/pcm', $session['session']['audio']['input']['format']['type']);
    }

    public function test_realtime_service_returns_fallback_pipeline_for_non_native_provider(): void
    {
        $session = (new RealtimeSessionService())->create([
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'fallback_pipeline' => [
                'tts' => ['provider' => 'eleven_labs', 'model' => 'eleven_multilingual_v2'],
            ],
        ]);

        $this->assertFalse($session['native_realtime']);
        $this->assertSame('application_pipeline', $session['connect']['recommended']);
        $this->assertSame('eleven_labs', $session['pipeline']['tts']['provider']);
    }

    public function test_realtime_service_returns_livekit_descriptor_when_configured(): void
    {
        config()->set('ai-engine.realtime.livekit', [
            'url' => 'wss://voice.example.test',
            'api_key' => 'lk_key',
            'api_secret' => 'lk_secret',
            'default_agent_name' => 'laravel-agent',
            'token_ttl' => 900,
        ]);

        $session = (new RealtimeSessionService())->create([
            'provider' => 'livekit',
            'model' => 'voice-pipeline',
            'transport' => 'livekit',
            'metadata' => [
                'room' => 'support-room',
                'participant_identity' => 'user-123',
                'participant_name' => 'User 123',
            ],
            'fallback_pipeline' => [
                'stt' => ['provider' => 'local_audio', 'model' => 'local-whisper'],
                'chat' => ['provider' => 'ollama', 'model' => 'gemma3:4b'],
                'tts' => ['provider' => 'local_audio', 'model' => 'local-tts'],
            ],
        ]);

        $this->assertSame('livekit', $session['provider']);
        $this->assertSame('livekit', $session['transport']);
        $this->assertSame('wss://voice.example.test', $session['connect']['livekit']['url']);
        $this->assertSame('support-room', $session['connect']['livekit']['room']);
        $this->assertSame('laravel-agent', $session['connect']['livekit']['agent_name']);
        $this->assertNotEmpty($session['connect']['livekit']['token']);
        $this->assertSame('local_audio', $session['pipeline']['stt']['provider']);
        $this->assertSame('ollama', $session['pipeline']['chat']['provider']);
        $this->assertSame('local_audio', $session['pipeline']['tts']['provider']);
        $this->assertTrue($session['capabilities']['webrtc']);
        $this->assertTrue($session['capabilities']['sip']);
    }

    public function test_realtime_service_can_mint_openai_client_secret(): void
    {
        config()->set('ai-engine.engines.openai.api_key', 'test-openai-key');

        Http::fake([
            'https://api.openai.com/v1/realtime/client_secrets' => Http::response([
                'value' => 'ephemeral-key',
                'expires_at' => 1234567890,
            ]),
        ]);

        $session = (new RealtimeSessionService())->createClientSecret(
            RealtimeSessionConfig::make('openai', 'gpt-realtime')
                ->withVoice('marin')
                ->withMetadata(['session_id' => 'local-session'])
        );

        $this->assertSame('ephemeral-key', $session['client_secret']['response']['value']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/realtime/client_secrets'
            && $request->data()['session']['audio']['output']['voice'] === 'marin'
            && !array_key_exists('metadata', $request->data()['session']));
    }

    public function test_realtime_service_can_exchange_openai_webrtc_sdp(): void
    {
        config()->set('ai-engine.engines.openai.api_key', 'test-openai-key');

        $service = new class extends RealtimeSessionService {
            public array $sent = [];

            protected function sendOpenAIRealtimeCall(string $url, string $apiKey, string $sdp, array $session): string
            {
                $this->sent = compact('url', 'apiKey', 'sdp', 'session');

                return 'answer-sdp';
            }
        };

        $session = $service->exchangeWebRtcSdp(
            RealtimeSessionConfig::make('openai', 'gpt-realtime')
                ->withVoice('marin')
                ->withMetadata(['session_id' => 'local-session']),
            'offer-sdp'
        );

        $this->assertSame('answer-sdp', $session['sdp']['answer']);
        $this->assertSame('local-session', $session['session']['metadata']['session_id']);
        $this->assertSame('https://api.openai.com/v1/realtime/calls', $service->sent['url']);
        $this->assertSame('test-openai-key', $service->sent['apiKey']);
        $this->assertSame("offer-sdp\r\n", $service->sent['sdp']);
        $this->assertSame('realtime', $service->sent['session']['type']);
        $this->assertArrayNotHasKey('metadata', $service->sent['session']);
    }

    public function test_trace_recorder_and_evaluation_services_record_results(): void
    {
        $traces = new TraceRecorderService();
        $spanId = $traces->start('agent.run', ['agent' => 'support']);
        $span = $traces->end($spanId, metadata: ['tokens' => 100]);

        $this->assertSame('agent.run', $span['name']);
        $this->assertSame('ok', $span['status']);
        $this->assertSame(100, $span['metadata']['tokens']);
        $this->assertCount(1, $traces->all());

        $evaluations = new EvaluationService();
        $run = $evaluations->evaluate('contains citation', 'Answer [1]', null, fn (string $actual): bool => str_contains($actual, '['));

        $this->assertTrue($run['passed']);
        $this->assertCount(1, $evaluations->runs());
    }
}
