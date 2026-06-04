<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\DTOs\RealtimeSessionConfig;
use LaravelAIEngine\Exceptions\RealtimeRoomNotAllowedException;
use LaravelAIEngine\Http\Requests\CreateRealtimeSessionRequest;
use LaravelAIEngine\Http\Requests\RealtimeSdpExchangeRequest;
use LaravelAIEngine\Services\SDK\RealtimeSessionService;

class RealtimeController extends Controller
{
    public function __construct(
        protected RealtimeSessionService $sessions
    ) {}

    public function session(CreateRealtimeSessionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $config = $this->config($validated);

        try {
            $descriptor = (bool) ($validated['mint_client_secret'] ?? false)
                ? $this->sessions->createClientSecret($config)
                : $this->sessions->create($config);
        } catch (RealtimeRoomNotAllowedException $e) {
            return $this->roomNotAllowed($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Realtime session descriptor created.',
            'data' => [
                'session' => $descriptor,
            ],
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    public function sdp(RealtimeSdpExchangeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $descriptor = $this->sessions->exchangeWebRtcSdp(
                $this->config($validated),
                (string) $validated['sdp']
            );
        } catch (RealtimeRoomNotAllowedException $e) {
            return $this->roomNotAllowed($e);
        }

        return response()->json([
            'success' => (bool) ($descriptor['sdp']['answer'] ?? false),
            'message' => 'Realtime WebRTC SDP exchange completed.',
            'data' => [
                'session' => $descriptor,
            ],
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1'],
        ]);
    }

    protected function roomNotAllowed(RealtimeRoomNotAllowedException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
            'error' => ['message' => $e->getMessage(), 'room' => $e->room],
            'meta' => ['schema' => 'ai-engine.v1'],
        ], 422);
    }

    protected function config(array $validated): RealtimeSessionConfig
    {
        $provider = (string) ($validated['provider'] ?? config('ai-engine.realtime.default_provider', 'openai'));
        $model = (string) ($validated['model']
            ?? config("ai-engine.realtime.{$provider}.default_model")
            ?? config('ai-engine.realtime.default_model', 'gpt-realtime'));
        $transport = (string) ($validated['transport'] ?? config('ai-engine.realtime.default_transport', 'webrtc'));
        $isWebRtc = $transport === 'webrtc';
        $inputAudioFormat = array_key_exists('input_audio_format', $validated)
            ? $validated['input_audio_format']
            : ($isWebRtc ? null : config('ai-engine.realtime.input_audio_format'));
        $outputAudioFormat = array_key_exists('output_audio_format', $validated)
            ? $validated['output_audio_format']
            : ($isWebRtc ? null : config('ai-engine.realtime.output_audio_format'));

        return new RealtimeSessionConfig(
            provider: $provider,
            model: $model,
            modalities: (array) ($validated['modalities'] ?? ['audio']),
            voice: $validated['voice'] ?? config('ai-engine.realtime.default_voice'),
            instructions: $validated['instructions'] ?? null,
            tools: (array) ($validated['tools'] ?? []),
            metadata: (array) ($validated['metadata'] ?? []),
            inputAudioFormat: $inputAudioFormat,
            outputAudioFormat: $outputAudioFormat,
            turnDetection: (array) ($validated['turn_detection'] ?? config('ai-engine.realtime.turn_detection', [])),
            temperature: isset($validated['temperature']) ? (float) $validated['temperature'] : null,
            maxResponseOutputTokens: $validated['max_response_output_tokens'] ?? null,
            providerOptions: (array) ($validated['provider_options'] ?? []),
            mode: (string) ($validated['mode'] ?? 'voice_chat'),
            transport: $transport,
            inputAudioTranscription: (array) ($validated['input_audio_transcription'] ?? []),
            fallbackPipeline: (array) ($validated['fallback_pipeline'] ?? [])
        );
    }
}
