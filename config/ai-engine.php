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
]);
