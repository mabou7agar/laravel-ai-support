<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable debug logging for AI Engine operations. When enabled, detailed
    | logs will be written to the ai-engine log channel.
    |
    */
    'debug' => env('AI_ENGINE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Demo Routes
    |--------------------------------------------------------------------------
    |
    | Control whether demo routes are enabled. Set AI_ENGINE_ENABLE_DEMO_ROUTES=true
    | in your .env file to enable demo routes.
    |
    */
    'enable_demo_routes' => env('AI_ENGINE_ENABLE_DEMO_ROUTES', false),
    'demo_route_prefix' => env('AI_ENGINE_DEMO_PREFIX', 'ai-demo'),
    'demo_route_middleware' => ['web'],

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
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for AI requests to prevent abuse and control costs.
    |
    */
    'rate_limiting' => [
        'enabled' => env('AI_ENGINE_RATE_LIMIT_ENABLED', true),
        'max_requests_per_minute' => env('AI_ENGINE_RATE_LIMIT_PER_MINUTE', 60),
        'max_requests_per_hour' => env('AI_ENGINE_RATE_LIMIT_PER_HOUR', 1000),
    ],

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
    | Supported drivers: database, redis, file, mongodb
    |
    */
    'memory' => [
        'enabled' => env('AI_MEMORY_ENABLED', true),
        'default_driver' => env('AI_MEMORY_DRIVER', 'database'),
        'max_messages' => env('AI_MEMORY_MAX_MESSAGES', 50),

        // Memory Optimization Settings
        'optimization' => [
            'enabled' => env('AI_MEMORY_OPTIMIZATION_ENABLED', true),
            'window_size' => env('AI_MEMORY_WINDOW_SIZE', 10), // Recent messages to keep
            'summary_threshold' => env('AI_MEMORY_SUMMARY_THRESHOLD', 20), // When to start summarizing
            'cache_ttl' => env('AI_MEMORY_CACHE_TTL', 300), // 5 minutes
        ],

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

        'mongodb' => [
            'connection_string' => env('AI_MEMORY_MONGODB_CONNECTION', 'mongodb://localhost:27017'),
            'database' => env('AI_MEMORY_MONGODB_DATABASE', 'ai_engine'),
            'max_messages' => env('AI_MEMORY_MONGODB_MAX_MESSAGES', 1000),
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

    /*
    |--------------------------------------------------------------------------
    | Enterprise Features Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for advanced enterprise features including failover,
    | streaming, and analytics
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Automatic Failover Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic failover between AI providers for reliability
    |
    */
    'failover' => [
        'enabled' => env('AI_FAILOVER_ENABLED', true),
        'strategy' => env('AI_FAILOVER_STRATEGY', 'priority'), // priority, round_robin
        'circuit_breaker' => [
            'failure_threshold' => env('AI_FAILOVER_FAILURE_THRESHOLD', 5),
            'timeout' => env('AI_FAILOVER_TIMEOUT', 60), // seconds
            'retry_timeout' => env('AI_FAILOVER_RETRY_TIMEOUT', 300), // seconds
        ],
        'providers' => [
            'openai' => [
                'priority' => 1,
                'timeout' => 30,
                'retry_attempts' => 3,
            ],
            'anthropic' => [
                'priority' => 2,
                'timeout' => 30,
                'retry_attempts' => 3,
            ],
            'gemini' => [
                'priority' => 3,
                'timeout' => 30,
                'retry_attempts' => 3,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WebSocket Streaming Configuration
    |--------------------------------------------------------------------------
    |
    | Configure real-time AI response streaming via WebSockets
    |
    */
    'streaming' => [
        'enabled' => env('AI_STREAMING_ENABLED', true),
        'websocket' => [
            'host' => env('AI_WEBSOCKET_HOST', '0.0.0.0'),
            'port' => env('AI_WEBSOCKET_PORT', 8080),
            'max_connections' => env('AI_WEBSOCKET_MAX_CONNECTIONS', 1000),
            'heartbeat_interval' => env('AI_WEBSOCKET_HEARTBEAT', 30), // seconds
        ],
        'events' => [
            'response_chunk' => 'ai.response.chunk',
            'response_complete' => 'ai.response.complete',
            'action_triggered' => 'ai.action.triggered',
            'error' => 'ai.error',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics and Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure comprehensive analytics and monitoring system
    |
    */
    'analytics' => [
        'enabled' => env('AI_ANALYTICS_ENABLED', true),
        'driver' => env('AI_ANALYTICS_DRIVER', 'database'), // database, redis
        'retention_days' => env('AI_ANALYTICS_RETENTION_DAYS', 90),
        'real_time_metrics' => env('AI_ANALYTICS_REAL_TIME', true),

        'drivers' => [
            'database' => [
                'table_prefix' => 'ai_analytics_',
                'batch_size' => 100,
            ],
            'redis' => [
                'prefix' => 'ai_analytics:',
                'ttl' => 7776000, // 90 days in seconds
            ],
        ],

        'metrics' => [
            'track_requests' => true,
            'track_streaming' => true,
            'track_actions' => true,
            'track_errors' => true,
            'track_performance' => true,
            'track_costs' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Interactive Actions Configuration
    |--------------------------------------------------------------------------
    |
    | Configure interactive actions system for AI responses
    |
    */
    'actions' => [
        'enabled' => env('AI_ACTIONS_ENABLED', true),
        'max_actions_per_response' => env('AI_ACTIONS_MAX_PER_RESPONSE', 10),
        'validation' => [
            'strict_mode' => env('AI_ACTIONS_STRICT_VALIDATION', true),
            'allowed_domains' => env('AI_ACTIONS_ALLOWED_DOMAINS', ''),
        ],
        'handlers' => [
            'button' => \LaravelAIEngine\Services\ActionHandlers\ButtonActionHandler::class,
            'quick_reply' => \LaravelAIEngine\Services\ActionHandlers\QuickReplyActionHandler::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector Search & Embeddings Configuration
    |--------------------------------------------------------------------------
    |
    | Configure vector database, embeddings, and semantic search features
    |
    */
    'vector' => [
        // Default vector database driver
        'default_driver' => env('VECTOR_DB_DRIVER', 'qdrant'),

        // Vector database drivers
        'drivers' => [
            'qdrant' => [
                'host' => env('QDRANT_HOST', 'http://localhost:6333'),
                'api_key' => env('QDRANT_API_KEY'),
                'timeout' => env('QDRANT_TIMEOUT', 30),
            ],

            'pinecone' => [
                'api_key' => env('PINECONE_API_KEY'),
                'environment' => env('PINECONE_ENVIRONMENT', 'us-west1-gcp'),
                'timeout' => env('PINECONE_TIMEOUT', 30),
            ],
        ],

        // Embedding configuration
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'embedding_dimensions' => env('OPENAI_EMBEDDING_DIMENSIONS', 3072),

        // Collection settings
        'collection_prefix' => env('VECTOR_COLLECTION_PREFIX', 'vec_'),

        // Caching
        'cache_embeddings' => env('VECTOR_CACHE_EMBEDDINGS', true),
        'cache_ttl' => env('VECTOR_CACHE_TTL', 86400), // 24 hours

        // Batch processing
        'batch_size' => env('VECTOR_BATCH_SIZE', 100),

        // Auto-indexing
        'auto_index' => env('VECTOR_AUTO_INDEX', true),
        'auto_delete' => env('VECTOR_AUTO_DELETE', true),

        // Queue configuration
        'queue' => [
            'enabled' => env('VECTOR_QUEUE_ENABLED', true),
            'connection' => env('VECTOR_QUEUE_CONNECTION', 'redis'),
            'queue_name' => env('VECTOR_QUEUE_NAME', 'vector-indexing'),
        ],

        // Search defaults
        'search' => [
            'default_limit' => env('VECTOR_SEARCH_LIMIT', 20),
            'default_threshold' => env('VECTOR_SEARCH_THRESHOLD', 0.3),
        ],

        // Chunking configuration for large texts
        'chunking' => [
            'enabled' => env('VECTOR_CHUNKING_ENABLED', true),
            'chunk_size' => env('VECTOR_CHUNK_SIZE', 1000),
            'chunk_overlap' => env('VECTOR_CHUNK_OVERLAP', 200),
            'min_chunk_size' => env('VECTOR_MIN_CHUNK_SIZE', 100),
        ],

        // Media embedding configuration
        'media' => [
            'enabled' => env('VECTOR_MEDIA_ENABLED', true),
            'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o'),
            'whisper_model' => env('OPENAI_WHISPER_MODEL', 'whisper-1'),

            // Supported file formats
            'supported_formats' => [
                'images' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'heic', 'heif'],
                'documents' => ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'ppt', 'pptx', 'csv'],
                'audio' => ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'wma'],
                'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v'],
            ],
        ],

        // RAG (Retrieval Augmented Generation) configuration
        'rag' => [
            'enabled' => env('VECTOR_RAG_ENABLED', true),
            'max_context_items' => env('VECTOR_RAG_MAX_CONTEXT', 5),
            'include_sources' => env('VECTOR_RAG_INCLUDE_SOURCES', true),
            'min_relevance_score' => env('VECTOR_RAG_MIN_SCORE', 0.5),
        ],

        // Authorization & Security
        'authorization' => [
            'enabled' => env('VECTOR_AUTHORIZATION_ENABLED', true),
            'default_allow' => env('VECTOR_AUTH_DEFAULT_ALLOW', true),
            'default_allow_indexing' => env('VECTOR_AUTH_DEFAULT_ALLOW_INDEXING', true),
            'default_allow_deletion' => env('VECTOR_AUTH_DEFAULT_ALLOW_DELETION', true),
            'filter_by_user' => env('VECTOR_AUTH_FILTER_BY_USER', false),
            'filter_by_visibility' => env('VECTOR_AUTH_FILTER_BY_VISIBILITY', true),
            'filter_by_status' => env('VECTOR_AUTH_FILTER_BY_STATUS', true),
            'log_events' => env('VECTOR_AUTH_LOG_EVENTS', false),

            // Row-level security rules
            'row_level_security' => [
                // Example: ['field' => 'user_id', 'operator' => '==', 'value' => '{user_id}']
            ],

            // Collection access control
            'restricted_collections' => [],
            'accessible_collections' => [],
            'collection_access' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Intelligent RAG Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Intelligent RAG (Retrieval-Augmented Generation).
    | The AI automatically decides when to search the knowledge base.
    |
    */
    'intelligent_rag' => [
        'enabled' => env('INTELLIGENT_RAG_ENABLED', true),

        // Default collections to search (model class names)
        // If empty, the system will AUTO-DISCOVER all models with:
        // - Vectorizable trait
        // - RAGgable trait
        //
        // Simply add 'use Vectorizable;' or 'use RAGgable;' to your models!
        'default_collections' => env('INTELLIGENT_RAG_COLLECTIONS')
            ? explode(',', env('INTELLIGENT_RAG_COLLECTIONS'))
            : [
                // Leave empty for auto-discovery, or specify manually:
                // 'App\\Models\\Document',
                // 'App\\Models\\Post',
                // 'App\\Models\\Email',
                // 'App\\Models\\Article',
            ],

        // Auto-discovery settings
        'auto_discover' => env('INTELLIGENT_RAG_AUTO_DISCOVER', true),
        'discovery_cache_ttl' => env('INTELLIGENT_RAG_DISCOVERY_CACHE', 3600), // 1 hour

        // Maximum context items to retrieve
        'max_context_items' => env('INTELLIGENT_RAG_MAX_CONTEXT', 5),

        // Minimum relevance score (0-1) - Lower = more results, Higher = more precise
        // 0.3 = balanced (recommended), 0.5 = moderate, 0.7+ = strict/precise
        'min_relevance_score' => env('INTELLIGENT_RAG_MIN_SCORE', 0.3),

        // Fallback threshold when no results found (0.0 = return anything, null = no fallback)
        'fallback_threshold' => env('INTELLIGENT_RAG_FALLBACK_THRESHOLD', 0.0),

        // Model to use for query analysis (can use gpt-4o, gpt-4o-mini, or any available model)
        'analysis_model' => env('INTELLIGENT_RAG_ANALYSIS_MODEL', 'gpt-4o'),

        // Include source citations in response
        'include_sources' => env('INTELLIGENT_RAG_INCLUDE_SOURCES', true),
    ],
];
