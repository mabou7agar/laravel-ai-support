<?php

declare(strict_types=1);

use LaravelAIEngine\Support\Config\AIEngineConfigDefaults;

$defaults = AIEngineConfigDefaults::defaults();

return array_replace_recursive($defaults, [
    /*
    |--------------------------------------------------------------------------
    | Engine Defaults
    |--------------------------------------------------------------------------
    |
    | These values are used when a request does not explicitly provide an
    | engine or model. Keep these stable for production applications and use
    | per-request options when a workflow needs a different provider.
    |
    */
    'default' => env('AI_ENGINE_DEFAULT', $defaults['default'] ?? 'openai'),
    'default_model' => env('AI_ENGINE_DEFAULT_MODEL', $defaults['default_model'] ?? 'gpt-4o'),

    /*
    |--------------------------------------------------------------------------
    | Token Estimation
    |--------------------------------------------------------------------------
    |
    | Provider usage payloads are preferred when available. These ratios are
    | used only for preflight estimates, fallback accounting, chunk sizing, and
    | providers that do not return usage. Values are characters per token.
    |
    */
    'token_estimation' => [
        'profiles' => [
            'latin' => (float) env('AI_ENGINE_TOKEN_CHARS_LATIN', 4.0),
            'code' => (float) env('AI_ENGINE_TOKEN_CHARS_CODE', 2.0),
            'cjk' => (float) env('AI_ENGINE_TOKEN_CHARS_CJK', 1.0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Engines
    |--------------------------------------------------------------------------
    |
    | Configure provider credentials and provider-specific defaults. The full
    | nested structure is supplied by AIEngineConfigDefaults; this published
    | file exposes the keys most applications customize while preserving new
    | package defaults during upgrades.
    |
    */
    'engines' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
        ],
        'google_tts' => [
            'api_key' => env('GOOGLE_TTS_API_KEY', env('GOOGLE_API_KEY')),
            'access_token' => env('GOOGLE_TTS_ACCESS_TOKEN'),
            'base_url' => env('GOOGLE_TTS_BASE_URL', 'https://texttospeech.googleapis.com/v1'),
        ],
        'fal_ai' => [
            'api_key' => env('FAL_AI_API_KEY'),
        ],
        'eleven_labs' => [
            'api_key' => env('ELEVENLABS_API_KEY'),
        ],
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'default_model' => env('OPENROUTER_DEFAULT_MODEL', data_get($defaults, 'engines.openrouter.default_model')),
            'cost_optimization' => [
                'enabled' => env('OPENROUTER_COST_OPTIMIZATION_ENABLED', data_get($defaults, 'engines.openrouter.cost_optimization.enabled', false)),
                'mode' => env('OPENROUTER_COST_OPTIMIZATION_MODE', data_get($defaults, 'engines.openrouter.cost_optimization.mode', 'free_first')),
                'free_models' => array_filter(array_map('trim', preg_split('/[;,]+/', (string) env(
                    'OPENROUTER_FREE_MODELS',
                    implode(',', data_get($defaults, 'engines.openrouter.cost_optimization.free_models', []))
                )) ?: [])),
                'include_requested_model_fallback' => env('OPENROUTER_INCLUDE_REQUESTED_MODEL_FALLBACK', data_get($defaults, 'engines.openrouter.cost_optimization.include_requested_model_fallback', true)),
                'sort_by_price' => env('OPENROUTER_SORT_BY_PRICE', data_get($defaults, 'engines.openrouter.cost_optimization.sort_by_price', true)),
                'preferred_max_latency_p90' => env('OPENROUTER_PREFERRED_MAX_LATENCY_P90'),
                'max_price' => [
                    'prompt' => env('OPENROUTER_MAX_PROMPT_PRICE'),
                    'completion' => env('OPENROUTER_MAX_COMPLETION_PRICE'),
                ],
            ],
        ],
        'pexels' => [
            'api_key' => env('PEXELS_API_KEY'),
            'base_url' => env('PEXELS_BASE_URL', 'https://api.pexels.com'),
            'timeout' => env('PEXELS_TIMEOUT', 30),
        ],
        'local_audio' => [
            'api_key' => env('LOCAL_AUDIO_API_KEY'),
            'base_url' => env('LOCAL_AUDIO_BASE_URL', data_get($defaults, 'engines.local_audio.base_url', 'http://127.0.0.1:8880/v1')),
            'timeout' => env('LOCAL_AUDIO_TIMEOUT', data_get($defaults, 'engines.local_audio.timeout', 120)),
            'stt' => [
                'mode' => env('LOCAL_AUDIO_STT_MODE', data_get($defaults, 'engines.local_audio.stt.mode', 'openai_compatible')),
                'path' => env('LOCAL_AUDIO_STT_PATH', data_get($defaults, 'engines.local_audio.stt.path', '/audio/transcriptions')),
                'model' => env('LOCAL_AUDIO_STT_MODEL', data_get($defaults, 'engines.local_audio.stt.model', 'local-whisper')),
                'language' => env('LOCAL_AUDIO_STT_LANGUAGE', data_get($defaults, 'engines.local_audio.stt.language')),
                'prompt' => env('LOCAL_AUDIO_STT_PROMPT', data_get($defaults, 'engines.local_audio.stt.prompt')),
                'command' => data_get($defaults, 'engines.local_audio.stt.command', []),
                'output_path' => env('LOCAL_AUDIO_STT_OUTPUT_PATH', data_get($defaults, 'engines.local_audio.stt.output_path')),
            ],
            'tts' => [
                'mode' => env('LOCAL_AUDIO_TTS_MODE', data_get($defaults, 'engines.local_audio.tts.mode', 'openai_compatible')),
                'path' => env('LOCAL_AUDIO_TTS_PATH', data_get($defaults, 'engines.local_audio.tts.path', '/audio/speech')),
                'model' => env('LOCAL_AUDIO_TTS_MODEL', data_get($defaults, 'engines.local_audio.tts.model', 'local-tts')),
                'voice' => env('LOCAL_AUDIO_TTS_VOICE', data_get($defaults, 'engines.local_audio.tts.voice', 'default')),
                'response_format' => env('LOCAL_AUDIO_TTS_FORMAT', data_get($defaults, 'engines.local_audio.tts.response_format', 'mp3')),
                'command' => data_get($defaults, 'engines.local_audio.tts.command', []),
                'output_path' => env('LOCAL_AUDIO_TTS_OUTPUT_PATH', data_get($defaults, 'engines.local_audio.tts.output_path')),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Feature Flags
    |--------------------------------------------------------------------------
    */
    'debug' => env('AI_ENGINE_DEBUG', $defaults['debug'] ?? false),
    'cache' => [
        'enabled' => env('AI_ENGINE_CACHE_ENABLED', data_get($defaults, 'cache.enabled', true)),
    ],
    'credits' => [
        'enabled' => env('AI_ENGINE_CREDITS_ENABLED', data_get($defaults, 'credits.enabled', true)),
    ],
    'vector' => [
        'auto_index' => env('AI_ENGINE_VECTOR_AUTO_INDEX', data_get($defaults, 'vector.auto_index', false)),
        'driver' => env('AI_ENGINE_VECTOR_DRIVER', data_get($defaults, 'vector.driver', 'qdrant')),
        'embedding_model' => env('AI_ENGINE_VECTOR_EMBEDDING_MODEL', data_get($defaults, 'vector.embedding_model', 'text-embedding-3-large')),
    ],
    'learning' => [
        'enabled' => env('AI_ENGINE_LEARNING_ENABLED', data_get($defaults, 'learning.enabled', true)),
        'default_type' => env('AI_ENGINE_LEARNING_DEFAULT_TYPE', data_get($defaults, 'learning.default_type', 'general')),
        'vector_store_name' => env('AI_ENGINE_LEARNING_VECTOR_STORE_NAME', data_get($defaults, 'learning.vector_store_name', 'Learned Knowledge')),
        'include_global_in_scoped_search' => env('AI_ENGINE_LEARNING_INCLUDE_GLOBAL_IN_SCOPED_SEARCH', data_get($defaults, 'learning.include_global_in_scoped_search', false)),
        'min_search_score' => env('AI_ENGINE_LEARNING_MIN_SEARCH_SCORE', data_get($defaults, 'learning.min_search_score', 0.0)),
        'max_content_bytes' => env('AI_ENGINE_LEARNING_MAX_CONTENT_BYTES', data_get($defaults, 'learning.max_content_bytes', 1048576)),
        'allowed_content_types' => data_get($defaults, 'learning.allowed_content_types', []),
        'adapter_classes' => data_get($defaults, 'learning.adapter_classes', []),
        'tools' => [
            'agent_ingest_enabled' => env('AI_ENGINE_LEARNING_AGENT_INGEST_ENABLED', data_get($defaults, 'learning.tools.agent_ingest_enabled', false)),
        ],
        'adapters' => [
            'file' => [
                'enabled' => env('AI_ENGINE_LEARNING_FILE_ENABLED', data_get($defaults, 'learning.adapters.file.enabled', true)),
                'disk' => env('AI_ENGINE_LEARNING_FILE_DISK', data_get($defaults, 'learning.adapters.file.disk')),
                'allow_local_paths' => env('AI_ENGINE_LEARNING_FILE_ALLOW_LOCAL_PATHS', data_get($defaults, 'learning.adapters.file.allow_local_paths', false)),
                'allowed_paths' => data_get($defaults, 'learning.adapters.file.allowed_paths', []),
            ],
            'getdesign' => [
                'api_url' => env('GETDESIGN_API_URL', data_get($defaults, 'learning.adapters.getdesign.api_url', 'https://api.getdesign.app/')),
                'timeout' => env('GETDESIGN_TIMEOUT', data_get($defaults, 'learning.adapters.getdesign.timeout', 45)),
                'allow_cli' => env('GETDESIGN_ALLOW_CLI', data_get($defaults, 'learning.adapters.getdesign.allow_cli', false)),
                'command' => data_get($defaults, 'learning.adapters.getdesign.command', []),
                'output_file' => env('GETDESIGN_OUTPUT_FILE', data_get($defaults, 'learning.adapters.getdesign.output_file', 'DESIGN.md')),
            ],
        ],
    ],
    'media' => [
        'transcription_normalization' => [
            'enabled' => env('AI_ENGINE_TRANSCRIPTION_NORMALIZATION_ENABLED', data_get($defaults, 'media.transcription_normalization.enabled', false)),
            'engine' => env('AI_ENGINE_TRANSCRIPTION_NORMALIZATION_ENGINE', data_get($defaults, 'media.transcription_normalization.engine', 'openai')),
            'model' => env('AI_ENGINE_TRANSCRIPTION_NORMALIZATION_MODEL', data_get($defaults, 'media.transcription_normalization.model', 'gpt-4o-mini')),
            'max_tokens' => env('AI_ENGINE_TRANSCRIPTION_NORMALIZATION_MAX_TOKENS', data_get($defaults, 'media.transcription_normalization.max_tokens', 500)),
            'temperature' => env('AI_ENGINE_TRANSCRIPTION_NORMALIZATION_TEMPERATURE', data_get($defaults, 'media.transcription_normalization.temperature', 0.0)),
            'system_prompt' => env('AI_ENGINE_TRANSCRIPTION_NORMALIZATION_SYSTEM_PROMPT', data_get($defaults, 'media.transcription_normalization.system_prompt')),
        ],
    ],
    'realtime' => [
        'default_provider' => env('AI_ENGINE_REALTIME_PROVIDER', data_get($defaults, 'realtime.default_provider', 'openai')),
        'default_model' => env('AI_ENGINE_REALTIME_MODEL', data_get($defaults, 'realtime.default_model', 'gpt-realtime')),
        'default_transport' => env('AI_ENGINE_REALTIME_TRANSPORT', data_get($defaults, 'realtime.default_transport', 'webrtc')),
        'default_voice' => env('AI_ENGINE_REALTIME_VOICE', data_get($defaults, 'realtime.default_voice', 'marin')),
        'input_audio_format' => env('AI_ENGINE_REALTIME_INPUT_AUDIO_FORMAT', data_get($defaults, 'realtime.input_audio_format', 'pcm16')),
        'output_audio_format' => env('AI_ENGINE_REALTIME_OUTPUT_AUDIO_FORMAT', data_get($defaults, 'realtime.output_audio_format', 'pcm16')),
        'timeout' => env('AI_ENGINE_REALTIME_TIMEOUT', data_get($defaults, 'realtime.timeout', 30)),
        'turn_detection' => [
            'type' => env('AI_ENGINE_REALTIME_TURN_DETECTION', data_get($defaults, 'realtime.turn_detection.type', 'server_vad')),
        ],
        'openai' => [
            'client_secrets_path' => env('AI_ENGINE_OPENAI_REALTIME_CLIENT_SECRETS_PATH', data_get($defaults, 'realtime.openai.client_secrets_path', '/realtime/client_secrets')),
            'calls_path' => env('AI_ENGINE_OPENAI_REALTIME_CALLS_PATH', data_get($defaults, 'realtime.openai.calls_path', '/realtime/calls')),
            'websocket_url' => env('AI_ENGINE_OPENAI_REALTIME_WEBSOCKET_URL', data_get($defaults, 'realtime.openai.websocket_url', 'wss://api.openai.com/v1/realtime')),
            'transcription_model' => env('AI_ENGINE_OPENAI_REALTIME_TRANSCRIPTION_MODEL', data_get($defaults, 'realtime.openai.transcription_model', 'gpt-realtime-whisper')),
        ],
        'gemini' => [
            'websocket_url' => env('AI_ENGINE_GEMINI_LIVE_WEBSOCKET_URL', data_get($defaults, 'realtime.gemini.websocket_url', 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent')),
            'default_model' => env('AI_ENGINE_GEMINI_LIVE_MODEL', data_get($defaults, 'realtime.gemini.default_model', 'gemini-live-2.5-flash-preview')),
        ],
        'livekit' => [
            'url' => env('LIVEKIT_URL', env('LIVEKIT_WS_URL', data_get($defaults, 'realtime.livekit.url'))),
            'api_key' => env('LIVEKIT_API_KEY', data_get($defaults, 'realtime.livekit.api_key')),
            'api_secret' => env('LIVEKIT_API_SECRET', data_get($defaults, 'realtime.livekit.api_secret')),
            'default_room' => env('LIVEKIT_DEFAULT_ROOM', data_get($defaults, 'realtime.livekit.default_room', 'ai-engine-voice')),
            'default_agent_name' => env('LIVEKIT_AGENT_NAME', data_get($defaults, 'realtime.livekit.default_agent_name', 'laravel-ai-engine')),
            'token_ttl' => env('LIVEKIT_TOKEN_TTL', data_get($defaults, 'realtime.livekit.token_ttl', 3600)),
            'token_endpoint' => env('LIVEKIT_TOKEN_ENDPOINT', data_get($defaults, 'realtime.livekit.token_endpoint', '/api/v1/ai/realtime/sessions')),
        ],
        'fallback_pipeline' => data_get($defaults, 'realtime.fallback_pipeline', []),
    ],
    'testing' => [
        'root_app_path' => env('AI_ENGINE_TEST_ROOT_APP_PATH', data_get($defaults, 'testing.root_app_path')),
        'live_provider_matrix' => [
            'text' => env('AI_ENGINE_LIVE_TEXT_PROVIDER_MATRIX', data_get($defaults, 'testing.live_provider_matrix.text')),
            'agent' => env('AI_ENGINE_LIVE_AGENT_PROVIDER_MATRIX', data_get($defaults, 'testing.live_provider_matrix.agent')),
            'image' => env('AI_ENGINE_LIVE_IMAGE_PROVIDER_MATRIX', data_get($defaults, 'testing.live_provider_matrix.image')),
            'video' => env('AI_ENGINE_LIVE_VIDEO_PROVIDER_MATRIX', data_get($defaults, 'testing.live_provider_matrix.video')),
            'tts' => env('AI_ENGINE_LIVE_TTS_PROVIDER_MATRIX', data_get($defaults, 'testing.live_provider_matrix.tts')),
            'transcribe' => env('AI_ENGINE_LIVE_TRANSCRIBE_PROVIDER_MATRIX', data_get($defaults, 'testing.live_provider_matrix.transcribe')),
        ],
    ],
]);
