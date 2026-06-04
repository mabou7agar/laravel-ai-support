<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\DTOs\RealtimeSessionConfig;

class RealtimeSessionService
{
    public function create(RealtimeSessionConfig|array $config): array
    {
        $provider = is_array($config)
            ? (string) ($config['provider'] ?? config('ai-engine.realtime.default_provider', 'openai'))
            : $config->provider;

        $config = $config instanceof RealtimeSessionConfig
            ? $config
            : new RealtimeSessionConfig(
                provider: $provider,
                model: (string) ($config['model'] ?? config("ai-engine.realtime.{$provider}.default_model") ?? config('ai-engine.realtime.default_model', 'gpt-realtime')),
                modalities: (array) ($config['modalities'] ?? ['text', 'audio']),
                voice: $config['voice'] ?? null,
                instructions: $config['instructions'] ?? null,
                tools: (array) ($config['tools'] ?? []),
                metadata: (array) ($config['metadata'] ?? []),
                inputAudioFormat: $config['input_audio_format'] ?? $config['inputAudioFormat'] ?? null,
                outputAudioFormat: $config['output_audio_format'] ?? $config['outputAudioFormat'] ?? null,
                turnDetection: (array) ($config['turn_detection'] ?? $config['turnDetection'] ?? []),
                temperature: isset($config['temperature']) ? (float) $config['temperature'] : null,
                maxResponseOutputTokens: $config['max_response_output_tokens'] ?? $config['maxResponseOutputTokens'] ?? null,
                providerOptions: (array) ($config['provider_options'] ?? $config['providerOptions'] ?? []),
                mode: (string) ($config['mode'] ?? 'voice_chat'),
                transport: (string) ($config['transport'] ?? 'webrtc'),
                inputAudioTranscription: (array) ($config['input_audio_transcription'] ?? $config['inputAudioTranscription'] ?? []),
                fallbackPipeline: (array) ($config['fallback_pipeline'] ?? $config['fallbackPipeline'] ?? [])
            );

        if (strtolower($config->provider) === 'livekit' || strtolower($config->transport) === 'livekit') {
            return $this->liveKitSession($config);
        }

        return match (strtolower($config->provider)) {
            'openai' => $this->openAISession($config),
            'gemini' => $this->geminiLiveSession($config),
            default => $this->fallbackPipelineSession($config),
        };
    }

    public function createClientSecret(RealtimeSessionConfig|array $config): array
    {
        $descriptor = $this->create($config);

        if (($descriptor['provider'] ?? null) !== 'openai') {
            return [
                'success' => false,
                'message' => 'Native client secrets are only available for OpenAI realtime sessions.',
                'descriptor' => $descriptor,
            ];
        }

        $apiKey = $this->openAIApiKey();
        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        $response = Http::timeout($this->timeout('openai'))
            ->withToken($apiKey)
            ->acceptJson()
            ->post($this->openAIUrl('client_secrets_path', '/realtime/client_secrets'), $descriptor['client_secret']['payload']);

        if (!$response->successful()) {
            throw new \RuntimeException('OpenAI realtime client secret request failed: ' . $response->body());
        }

        return array_replace_recursive($descriptor, [
            'client_secret' => [
                'response' => $response->json(),
            ],
        ]);
    }

    public function exchangeWebRtcSdp(RealtimeSessionConfig|array $config, string $sdp): array
    {
        $descriptor = $this->create($config);

        if (($descriptor['provider'] ?? null) !== 'openai') {
            return [
                'success' => false,
                'message' => 'WebRTC SDP exchange is currently native only for OpenAI realtime sessions.',
                'descriptor' => $descriptor,
            ];
        }

        $apiKey = $this->openAIApiKey();
        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI API key is not configured.');
        }

        return array_replace_recursive($descriptor, [
            'sdp' => [
                'answer' => $this->sendOpenAIRealtimeCall(
                    $this->openAIUrl('calls_path', '/realtime/calls'),
                    $apiKey,
                    $this->normalizeSdpOffer($sdp),
                    $this->openAIProviderSessionPayload((array) $descriptor['session'])
                ),
            ],
        ]);
    }

    protected function openAISession(RealtimeSessionConfig $config): array
    {
        $session = $this->openAISessionPayload($config);

        return [
            'provider' => 'openai',
            'mode' => $config->mode,
            'transport' => $config->transport,
            'native_realtime' => true,
            'endpoint' => $this->openAIPath('client_secrets_path', '/realtime/client_secrets'),
            'connect' => [
                'recommended' => $config->transport,
                'webrtc' => [
                    'endpoint' => $this->openAIPath('calls_path', '/realtime/calls'),
                    'content_type' => 'application/sdp',
                ],
                'websocket' => [
                    'endpoint' => $this->openAIRealtimeWebSocketUrl($config->model),
                ],
            ],
            'capabilities' => [
                'voice_chat' => true,
                'realtime_transcription' => true,
                'stt' => true,
                'tts' => true,
                'tools' => true,
                'webrtc' => true,
                'websocket' => true,
                'sip' => true,
            ],
            'session' => $session,
            'client_secret' => [
                'endpoint' => $this->openAIPath('client_secrets_path', '/realtime/client_secrets'),
                'payload' => ['session' => $this->openAIProviderSessionPayload($session)],
            ],
            'payload' => array_filter([
                'model' => $config->model,
                'modalities' => $config->modalities,
                'voice' => $config->voice,
                'instructions' => $config->instructions,
                'tools' => $config->tools,
                'metadata' => $config->metadata,
                'input_audio_format' => $config->inputAudioFormat,
                'output_audio_format' => $config->outputAudioFormat,
                'turn_detection' => $config->turnDetection,
                'temperature' => $config->temperature,
                'max_response_output_tokens' => $config->maxResponseOutputTokens,
            ], static fn ($value): bool => $value !== null && $value !== []),
        ];
    }

    protected function geminiLiveSession(RealtimeSessionConfig $config): array
    {
        return [
            'provider' => 'gemini',
            'mode' => $config->mode,
            'transport' => $config->transport === 'webrtc' ? 'websocket' : $config->transport,
            'native_realtime' => true,
            'endpoint' => $this->geminiWebSocketUrl(),
            'connect' => [
                'recommended' => 'websocket',
                'websocket' => [
                    'endpoint' => $this->geminiWebSocketUrl(),
                    'auth' => 'api_key_or_ephemeral_token',
                ],
            ],
            'capabilities' => [
                'voice_chat' => true,
                'realtime_transcription' => $config->mode === 'transcription',
                'stt' => true,
                'tts' => true,
                'vision' => true,
                'tools' => true,
                'websocket' => true,
            ],
            'payload' => array_filter(array_replace_recursive([
                'model' => $config->model,
                'responseModalities' => $config->modalities,
                'systemInstruction' => $config->instructions !== null ? [
                    'parts' => [['text' => $config->instructions]],
                ] : null,
                'tools' => $config->tools,
                'metadata' => $config->metadata,
                'inputAudioFormat' => $config->inputAudioFormat,
                'outputAudioFormat' => $config->outputAudioFormat,
                'realtimeInputConfig' => $config->turnDetection !== [] ? [
                    'turnDetection' => $config->turnDetection,
                ] : null,
                'generationConfig' => array_filter([
                    'temperature' => $config->temperature,
                    'maxOutputTokens' => is_int($config->maxResponseOutputTokens) ? $config->maxResponseOutputTokens : null,
                ], static fn ($value): bool => $value !== null && $value !== []),
            ], $config->providerOptions), static fn ($value): bool => $value !== null && $value !== []),
        ];
    }

    protected function fallbackPipelineSession(RealtimeSessionConfig $config): array
    {
        $pipeline = array_replace_recursive((array) config('ai-engine.realtime.fallback_pipeline', []), $config->fallbackPipeline);

        return [
            'provider' => $config->provider,
            'mode' => $config->mode,
            'transport' => 'http_stream',
            'native_realtime' => false,
            'endpoint' => null,
            'connect' => [
                'recommended' => 'application_pipeline',
                'description' => 'Use package STT, chat/agent, and TTS endpoints when the provider has no native realtime voice API.',
            ],
            'capabilities' => [
                'voice_chat' => true,
                'realtime_transcription' => false,
                'stt' => true,
                'tts' => true,
                'tools' => true,
                'webrtc' => false,
                'websocket' => false,
            ],
            'pipeline' => array_filter([
                'stt' => $pipeline['stt'] ?? null,
                'chat' => $pipeline['chat'] ?? null,
                'tts' => $pipeline['tts'] ?? null,
                'session' => $config->toArray(),
            ], static fn ($value): bool => $value !== null && $value !== []),
        ];
    }

    protected function liveKitSession(RealtimeSessionConfig $config): array
    {
        $liveKit = (array) config('ai-engine.realtime.livekit', []);
        $pipeline = array_replace_recursive((array) config('ai-engine.realtime.fallback_pipeline', []), $config->fallbackPipeline);
        // The LiveKit token grants joining a specific room as a specific identity. Both the
        // room and the participant identity arrive from request metadata, so without a
        // server-side constraint a caller could mint a token for another user's room or
        // identity. Constraining is OPT-IN, mirroring provider-tools owner_resolver:
        //  - livekit.identity_resolver / livekit.room_resolver (closure or 'Class@method'):
        //    when they return a value it overrides the client-supplied one with a
        //    server-resolved value.
        //  - livekit.allowed_rooms: when non-empty, the requested room must be on the list
        //    (a room_resolver result is always honoured first), else the mint is refused.
        // With none configured (default), behaviour is unchanged.
        $room = $this->resolveLiveKitRoom(
            (string) ($config->metadata['room'] ?? $liveKit['default_room'] ?? 'ai-engine-voice'),
            $liveKit
        );
        $identity = $this->resolveLiveKitIdentity(
            (string) ($config->metadata['participant_identity'] ?? $config->metadata['user_id'] ?? 'ai-engine-user'),
            $liveKit
        );
        $participantName = (string) ($config->metadata['participant_name'] ?? $identity);
        $agentName = (string) ($config->providerOptions['agent_name'] ?? $config->metadata['agent_name'] ?? $liveKit['default_agent_name'] ?? 'laravel-ai-engine');
        $url = (string) ($config->providerOptions['url'] ?? $liveKit['url'] ?? '');

        return [
            'provider' => 'livekit',
            'mode' => $config->mode,
            'transport' => 'livekit',
            'native_realtime' => false,
            'endpoint' => $url,
            'connect' => [
                'recommended' => 'livekit',
                'description' => 'Connect the browser to a LiveKit room; a Python or Node LiveKit Agent can join the same room and run the configured STT, chat, and TTS pipeline.',
                'livekit' => array_filter([
                    'url' => $url,
                    'room' => $room,
                    'participant_identity' => $identity,
                    'participant_name' => $participantName,
                    'agent_name' => $agentName,
                    'token' => $this->liveKitToken($room, $identity, $participantName, $liveKit),
                    'token_endpoint' => $liveKit['token_endpoint'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== ''),
            ],
            'capabilities' => [
                'voice_chat' => true,
                'realtime_transcription' => true,
                'stt' => true,
                'tts' => true,
                'tools' => true,
                'webrtc' => true,
                'websocket' => true,
                'sip' => true,
                'rooms' => true,
            ],
            'pipeline' => array_filter([
                'stt' => $pipeline['stt'] ?? null,
                'chat' => $pipeline['chat'] ?? null,
                'tts' => $pipeline['tts'] ?? null,
                'agent' => [
                    'name' => $agentName,
                    'runtime' => $config->providerOptions['runtime'] ?? $config->metadata['runtime'] ?? 'livekit-agents',
                    'model' => $config->model,
                    'instructions' => $config->instructions,
                    'tools' => $config->tools,
                ],
                'session' => $config->toArray(),
            ], static fn ($value): bool => $value !== null && $value !== []),
        ];
    }

    protected function openAISessionPayload(RealtimeSessionConfig $config): array
    {
        if ($config->mode === 'transcription') {
            $audioInput = array_filter([
                'format' => $this->audioFormatPayload($config->inputAudioFormat),
                'transcription' => array_replace([
                    'model' => (string) config('ai-engine.realtime.openai.transcription_model', 'gpt-realtime-whisper'),
                ], $config->inputAudioTranscription),
                'turn_detection' => $config->turnDetection ?: null,
            ], static fn ($value): bool => $value !== null && $value !== []);

            return array_filter(array_replace_recursive([
                'type' => 'transcription',
                'audio' => [
                    'input' => $audioInput,
                ],
            ], $config->providerOptions), static fn ($value): bool => $value !== null && $value !== []);
        }

        $audioInput = array_filter([
            'format' => $this->audioFormatPayload($config->inputAudioFormat),
            'transcription' => $config->inputAudioTranscription ?: null,
            'turn_detection' => $config->turnDetection ?: null,
        ], static fn ($value): bool => $value !== null && $value !== []);
        $audioOutput = array_filter([
            'format' => $this->audioFormatPayload($config->outputAudioFormat),
            'voice' => $config->voice,
        ], static fn ($value): bool => $value !== null && $value !== []);

        return array_filter(array_replace_recursive([
            'type' => 'realtime',
            'model' => $config->model,
            'output_modalities' => in_array('audio', $config->modalities, true) ? ['audio'] : ['text'],
            'instructions' => $config->instructions,
            'tools' => $config->tools,
            'metadata' => $config->metadata,
            'audio' => array_filter([
                'input' => $audioInput ?: null,
                'output' => $audioOutput ?: null,
            ], static fn ($value): bool => $value !== null && $value !== []),
            'temperature' => $config->temperature,
            'max_output_tokens' => $config->maxResponseOutputTokens,
        ], $config->providerOptions), static fn ($value): bool => $value !== null && $value !== []);
    }

    protected function liveKitToken(string $room, string $identity, string $name, array $config): ?string
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $apiSecret = trim((string) ($config['api_secret'] ?? ''));

        if ($apiKey === '' || $apiSecret === '') {
            return null;
        }

        $now = time();
        $payload = [
            'iss' => $apiKey,
            'sub' => $identity,
            'name' => $name,
            'nbf' => $now - 10,
            'exp' => $now + max(60, (int) ($config['token_ttl'] ?? 3600)),
            'video' => [
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $segments[] = $this->base64UrlEncode(hash_hmac('sha256', implode('.', $segments), $apiSecret, true));

        return implode('.', $segments);
    }

    /**
     * Resolve the participant identity, preferring a server-resolved value when an
     * identity_resolver is configured so a client cannot mint a token for another identity.
     *
     * @param  array<string, mixed>  $liveKit
     */
    protected function resolveLiveKitIdentity(string $requested, array $liveKit): string
    {
        $resolved = $this->resolveServerValue($liveKit['identity_resolver'] ?? null);

        return $resolved ?? $requested;
    }

    /**
     * Resolve the room, preferring a server-resolved value (room_resolver) and otherwise
     * enforcing the allowed_rooms allow-list when one is configured.
     *
     * @param  array<string, mixed>  $liveKit
     */
    protected function resolveLiveKitRoom(string $requested, array $liveKit): string
    {
        $resolved = $this->resolveServerValue($liveKit['room_resolver'] ?? null);
        if ($resolved !== null) {
            return $resolved;
        }

        $allowed = array_values(array_filter(
            array_map(static fn ($room): string => (string) $room, (array) ($liveKit['allowed_rooms'] ?? [])),
            static fn (string $room): bool => $room !== ''
        ));

        if ($allowed !== [] && !in_array($requested, $allowed, true)) {
            throw new \InvalidArgumentException("LiveKit room [{$requested}] is not allowed.");
        }

        return $requested;
    }

    /**
     * Evaluate a resolver (closure, invokable, or 'Class@method' string) to a server-side
     * string value, mirroring the provider-tools owner_resolver contract. Returns null when
     * the resolver is unset or yields an empty value (caller falls back to the request).
     */
    protected function resolveServerValue(mixed $resolver): ?string
    {
        if ($resolver === null) {
            return null;
        }

        $value = null;

        if ($resolver instanceof \Closure || (is_object($resolver) && is_callable($resolver))) {
            $value = $resolver();
        } elseif (is_string($resolver) && $resolver !== '') {
            if (str_contains($resolver, '@')) {
                [$class, $method] = explode('@', $resolver, 2);
                if (class_exists($class)) {
                    $value = app($class)->{$method}();
                }
            } elseif (is_callable($resolver)) {
                $value = $resolver();
            } elseif (class_exists($resolver)) {
                $instance = app($resolver);
                if (is_callable($instance)) {
                    $value = $instance();
                }
            }
        }

        return ($value === null || $value === '') ? null : (string) $value;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function audioFormatPayload(?string $format): ?array
    {
        if ($format === null || $format === '') {
            return null;
        }

        if (str_contains($format, '/')) {
            return ['type' => $format];
        }

        return match ($format) {
            'pcm16' => ['type' => 'audio/pcm', 'rate' => 24000],
            default => ['type' => $format],
        };
    }

    protected function openAIApiKey(): string
    {
        return (string) config('ai-engine.engines.openai.api_key', '');
    }

    protected function timeout(string $provider): int
    {
        return (int) config("ai-engine.engines.{$provider}.timeout", config('ai-engine.realtime.timeout', 30));
    }

    protected function openAIUrl(string $key, string $default): string
    {
        return rtrim((string) config('ai-engine.engines.openai.base_url', 'https://api.openai.com/v1'), '/') . $this->openAIPath($key, $default);
    }

    protected function openAIPath(string $key, string $default): string
    {
        return (string) config("ai-engine.realtime.openai.{$key}", $default);
    }

    protected function openAIRealtimeWebSocketUrl(string $model): string
    {
        $base = rtrim((string) config('ai-engine.realtime.openai.websocket_url', 'wss://api.openai.com/v1/realtime'), '?');

        return $base . '?model=' . rawurlencode($model);
    }

    protected function sendOpenAIRealtimeCall(string $url, string $apiKey, string $sdp, array $session): string
    {
        try {
            $response = (new \GuzzleHttp\Client(['timeout' => $this->timeout('openai')]))
                ->request('POST', $url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                    ],
                    'multipart' => [
                        [
                            'name' => 'sdp',
                            'contents' => $sdp,
                        ],
                        [
                            'name' => 'session',
                            'contents' => json_encode($session, JSON_THROW_ON_ERROR),
                        ],
                    ],
                ]);

            return (string) $response->getBody();
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $body = $e->getResponse() !== null ? (string) $e->getResponse()->getBody() : $e->getMessage();

            throw new \RuntimeException('OpenAI realtime SDP exchange failed: ' . $body, 0, $e);
        }
    }

    protected function openAIProviderSessionPayload(array $session): array
    {
        unset($session['metadata']);

        return $session;
    }

    protected function normalizeSdpOffer(string $sdp): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $sdp);
        $normalized = str_replace("\n", "\r\n", $normalized);

        return str_ends_with($normalized, "\r\n") ? $normalized : $normalized . "\r\n";
    }

    protected function geminiWebSocketUrl(): string
    {
        return (string) config(
            'ai-engine.realtime.gemini.websocket_url',
            'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent'
        );
    }
}
