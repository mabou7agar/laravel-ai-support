<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Engine
    |--------------------------------------------------------------------------
    |
    | This option controls the default AI engine that will be used when no
    | specific engine is requested. You can change this to any of the
    | supported engines defined in the engines array below.
    |
    */
    'default' => env('AI_ENGINE_DEFAULT', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | AI Engines Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the AI engines that your application uses.
    | Each engine can have multiple models and specific configurations.
    |
    */
    'engines' => [
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'organization' => env('OPENAI_ORGANIZATION'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => env('OPENAI_TIMEOUT', 30),
            'models' => [
                'gpt-4o' => ['enabled' => true, 'credit_index' => 2.0],
                'gpt-4o-mini' => ['enabled' => true, 'credit_index' => 0.5],
                'gpt-3.5-turbo' => ['enabled' => true, 'credit_index' => 0.3],
                'dall-e-3' => ['enabled' => true, 'credit_index' => 5.0],
                'dall-e-2' => ['enabled' => true, 'credit_index' => 3.0],
                'whisper-1' => ['enabled' => true, 'credit_index' => 1.0],
            ],
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'timeout' => env('ANTHROPIC_TIMEOUT', 30),
            'models' => [
                'claude-3-5-sonnet-20240620' => ['enabled' => true, 'credit_index' => 1.8],
                'claude-3-opus-20240229' => ['enabled' => true, 'credit_index' => 3.0],
                'claude-3-haiku-20240307' => ['enabled' => true, 'credit_index' => 0.8],
            ],
        ],

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'timeout' => env('GEMINI_TIMEOUT', 30),
            'models' => [
                'gemini-1.5-pro' => ['enabled' => true, 'credit_index' => 1.5],
                'gemini-1.5-flash' => ['enabled' => true, 'credit_index' => 0.4],
            ],
        ],

        'stable_diffusion' => [
            'driver' => 'stable_diffusion',
            'api_key' => env('STABILITY_API_KEY'),
            'base_url' => env('STABILITY_BASE_URL', 'https://api.stability.ai'),
            'timeout' => env('STABILITY_TIMEOUT', 60),
            'models' => [
                'sd3-large' => ['enabled' => true, 'credit_index' => 4.0],
                'sd3-medium' => ['enabled' => true, 'credit_index' => 3.0],
                'sdxl-1024-v1-0' => ['enabled' => true, 'credit_index' => 2.5],
            ],
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'site_url' => env('OPENROUTER_SITE_URL', env('APP_URL')),
            'site_name' => env('OPENROUTER_SITE_NAME', env('APP_NAME')),
            'timeout' => env('OPENROUTER_TIMEOUT', 30),
            'transforms' => [], // Optional: response transformations
            'route' => null, // Optional: specific routing preferences
            'models' => [
                // GPT-5 Models (Latest Generation - August 2025)
                'openai/gpt-5' => ['enabled' => true, 'credit_index' => 5.0],
                'openai/gpt-5-mini' => ['enabled' => true, 'credit_index' => 2.5],
                'openai/gpt-5-nano' => ['enabled' => true, 'credit_index' => 1.0],
                
                // GPT-4o Models
                'openai/gpt-4o' => ['enabled' => true, 'credit_index' => 2.2],
                'openai/gpt-4o-2024-11-20' => ['enabled' => true, 'credit_index' => 2.3],
                'openai/gpt-4o-mini' => ['enabled' => true, 'credit_index' => 0.6],
                'openai/gpt-4o-mini-2024-07-18' => ['enabled' => true, 'credit_index' => 0.6],
                
                // Claude 4 Models (Latest Generation)
                'anthropic/claude-4-opus' => ['enabled' => true, 'credit_index' => 4.5],
                'anthropic/claude-4-sonnet' => ['enabled' => true, 'credit_index' => 3.5],
                
                // Claude 3.5 Models
                'anthropic/claude-3.5-sonnet' => ['enabled' => true, 'credit_index' => 2.0],
                'anthropic/claude-3.5-sonnet-20241022' => ['enabled' => true, 'credit_index' => 2.1],
                'anthropic/claude-3.5-haiku' => ['enabled' => true, 'credit_index' => 1.0],
                
                // Claude 3 Models
                'anthropic/claude-3-opus' => ['enabled' => true, 'credit_index' => 3.2],
                'anthropic/claude-3-haiku' => ['enabled' => true, 'credit_index' => 0.9],
                
                // Gemini 2.5 Models (Latest Generation - March 2025)
                'google/gemini-2.5-pro' => ['enabled' => true, 'credit_index' => 3.0],
                'google/gemini-2.5-pro-experimental' => ['enabled' => true, 'credit_index' => 3.2],
                
                // Previous Google Models
                'google/gemini-pro' => ['enabled' => true, 'credit_index' => 1.7],
                'google/gemini-1.5-pro' => ['enabled' => true, 'credit_index' => 1.8],
                'google/gemini-2.0-flash' => ['enabled' => true, 'credit_index' => 1.9],
                
                // Meta Llama Models
                'meta-llama/llama-3.1-405b-instruct' => ['enabled' => true, 'credit_index' => 3.0],
                'meta-llama/llama-3.1-70b-instruct' => ['enabled' => true, 'credit_index' => 1.2],
                'meta-llama/llama-3.2-90b-instruct' => ['enabled' => true, 'credit_index' => 1.4],
                'meta-llama/llama-3.3-70b-instruct' => ['enabled' => true, 'credit_index' => 1.3],
                
                // Free Models (OpenRouter Free Tier - 0 Credits)
                'meta-llama/llama-3.1-8b-instruct:free' => ['enabled' => true, 'credit_index' => 0.0],
                'meta-llama/llama-3.2-3b-instruct:free' => ['enabled' => true, 'credit_index' => 0.0],
                'google/gemma-2-9b-it:free' => ['enabled' => true, 'credit_index' => 0.0],
                'mistralai/mistral-7b-instruct:free' => ['enabled' => true, 'credit_index' => 0.0],
                'qwen/qwen-2.5-7b-instruct:free' => ['enabled' => true, 'credit_index' => 0.0],
                'microsoft/phi-3-mini-128k-instruct:free' => ['enabled' => true, 'credit_index' => 0.0],
                'openchat/openchat-3.5-1210:free' => ['enabled' => true, 'credit_index' => 0.0],
                
                // Other Popular Models
                'mistralai/mixtral-8x7b-instruct' => ['enabled' => true, 'credit_index' => 0.8],
                'qwen/qwen-2.5-72b-instruct' => ['enabled' => true, 'credit_index' => 1.0],
                'deepseek/deepseek-chat' => ['enabled' => true, 'credit_index' => 0.3],
                'deepseek/deepseek-r1' => ['enabled' => true, 'credit_index' => 0.4],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit System Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the credit system for tracking and billing AI usage.
    |
    */
    'credits' => [
        'enabled' => env('AI_CREDITS_ENABLED', true),
        'default_balance' => env('AI_DEFAULT_CREDITS', 100.0),
        'low_balance_threshold' => env('AI_LOW_BALANCE_THRESHOLD', 10.0),
        'currency' => env('AI_CREDITS_CURRENCY', 'credits'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Configure response caching to reduce API calls and costs.
    |
    */
    'cache' => [
        'enabled' => env('AI_CACHE_ENABLED', true),
        'driver' => env('AI_CACHE_DRIVER', 'redis'),
        'ttl' => env('AI_CACHE_TTL', 3600), // 1 hour
        'semantic_enabled' => env('AI_SEMANTIC_CACHE_ENABLED', false),
        'semantic_similarity' => env('AI_SEMANTIC_SIMILARITY', 0.9),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting per engine to prevent API quota exhaustion.
    |
    */
    'rate_limiting' => [
        'enabled' => env('AI_RATE_LIMITING_ENABLED', true),
        'driver' => env('AI_RATE_LIMIT_DRIVER', 'redis'),
        'apply_to_jobs' => env('AI_RATE_LIMITING_APPLY_TO_JOBS', true),
        'per_engine' => [
            'openai' => ['requests' => 100, 'per_minute' => 1],
            'anthropic' => ['requests' => 50, 'per_minute' => 1],
            'gemini' => ['requests' => 60, 'per_minute' => 1],
            'stable_diffusion' => ['requests' => 20, 'per_minute' => 1],
            'openrouter' => ['requests' => 200, 'per_minute' => 1], // Higher limits due to unified API
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handling Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry mechanisms and fallback engines.
    |
    */
    'error_handling' => [
        'retry_attempts' => env('AI_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('AI_RETRY_DELAY', 1000), // milliseconds
        'backoff_strategy' => env('AI_BACKOFF_STRATEGY', 'exponential'), // linear, exponential
        'fallback_engines' => [
            'openai' => ['openrouter', 'anthropic', 'gemini'],
            'anthropic' => ['openrouter', 'openai', 'gemini'],
            'gemini' => ['openrouter', 'openai', 'anthropic'],
            'stable_diffusion' => ['openrouter'],
            'openrouter' => ['openai', 'anthropic', 'gemini'], // OpenRouter as fallback for others
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Safety Configuration
    |--------------------------------------------------------------------------
    |
    | Configure content moderation and safety filters.
    |
    */
    'content_safety' => [
        'enabled' => env('AI_CONTENT_SAFETY_ENABLED', true),
        'moderation_level' => env('AI_MODERATION_LEVEL', 'medium'), // strict, medium, relaxed
        'custom_filters' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics Configuration
    |--------------------------------------------------------------------------
    |
    | Configure usage analytics and monitoring.
    |
    */
    'analytics' => [
        'enabled' => env('AI_ANALYTICS_ENABLED', true),
        'driver' => env('AI_ANALYTICS_DRIVER', 'database'),
        'metrics' => ['usage', 'costs', 'latency', 'errors'],
        'retention_days' => env('AI_ANALYTICS_RETENTION', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure webhooks for async operation notifications.
    |
    */
    'webhooks' => [
        'enabled' => env('AI_WEBHOOKS_ENABLED', false),
        'endpoints' => [
            'completion' => env('AI_WEBHOOK_COMPLETION'),
            'error' => env('AI_WEBHOOK_ERROR'),
        ],
        'secret' => env('AI_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory Configuration
    |--------------------------------------------------------------------------
    |
    | Configure memory storage drivers for conversation persistence.
    | Supported drivers: database, redis, file
    |
    */
    'memory' => [
        'default_driver' => env('AI_MEMORY_DRIVER', 'database'),
        
        'database' => [
            'connection' => env('AI_MEMORY_DB_CONNECTION', null),
            'max_messages' => env('AI_MEMORY_DB_MAX_MESSAGES', 100),
        ],
        
        'redis' => [
            'connection' => env('AI_MEMORY_REDIS_CONNECTION', 'default'),
            'prefix' => env('AI_MEMORY_REDIS_PREFIX', 'ai_engine:'),
            'max_messages' => env('AI_MEMORY_REDIS_MAX_MESSAGES', 100),
        ],
        
        'file' => [
            'path' => env('AI_MEMORY_FILE_PATH', storage_path('ai-engine/conversations')),
            'max_messages' => env('AI_MEMORY_FILE_MAX_MESSAGES', 100),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Unified Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Configure default settings for the unified Engine facade.
    |
    */
    'default_engine' => env('AI_DEFAULT_ENGINE', 'openai'),
    'default_model' => env('AI_DEFAULT_MODEL', 'gpt-4o'),
];
