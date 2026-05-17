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
