<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Config;

class AIEngineConfigDefaults
{
    public static function defaults(): array
    {
        return [
    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable debug logging for AI Engine operations. When enabled, detailed
    | logs will be written to the ai-engine log channel including:
    | - Full prompts sent to AI
    | - Execution time for each request
    | - Response previews
    | - Token usage
    |
    */
    'debug' => env('AI_ENGINE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Orchestration Model
    |--------------------------------------------------------------------------
    |
    | The AI model used by the Laravel runtime for routing decisions.
    | This should be a fast, cost-effective model since it's called frequently.
    |
    */
    'orchestration_model' => env('AI_ORCHESTRATION_MODEL', 'gpt-4o-mini'),

    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    |
    | Configure how AI engine errors are handled and displayed to users.
    |
    */
    'error_handling' => [
        // Show detailed error messages to users (useful for debugging)
        'show_detailed_errors' => env('AI_ENGINE_SHOW_DETAILED_ERRORS', false),

        // Show API quota/billing errors to users
        'show_quota_errors' => env('AI_ENGINE_SHOW_QUOTA_ERRORS', true),

        // Fallback message when AI is unavailable
        'fallback_message' => env('AI_ENGINE_FALLBACK_MESSAGE', 'AI service is temporarily unavailable. Please try again later.'),

        // User-friendly error messages for common errors
        'error_messages' => [
            'quota_exceeded' => 'AI service quota has been exceeded. Please contact support or try again later.',
            'rate_limit' => 'Too many requests. Please wait a moment and try again.',
            'invalid_api_key' => 'AI service configuration error. Please contact support.',
            'network_error' => 'Unable to connect to AI service. Please check your connection and try again.',
            'timeout' => 'AI service request timed out. Please try again.',
            'model_not_found' => 'The requested AI model is not available. Please try a different model.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Response Contract
    |--------------------------------------------------------------------------
    |
    | Standardize JSON responses under one envelope:
    | success, message, data, error, meta.
    |
    */
    'api' => [
        'standardize_responses' => env('AI_ENGINE_STANDARDIZE_API_RESPONSES', true),
        'generate' => [
            'enabled' => env('AI_ENGINE_GENERATE_API_ENABLED', true),
            'prefix' => env('AI_ENGINE_GENERATE_API_PREFIX', 'api/v1/ai/generate'),
        ],
        'middleware' => [
            // Append middleware to all package v1 API route groups.
            // Use semicolon separators so middleware params can keep commas.
            // Example: "auth:sanctum;throttle:30,1"
            'append' => self::csvEnv('AI_ENGINE_API_APPEND_MIDDLEWARE'),

            // Append middleware to specific API groups only.
            'groups' => [
                'rag' => self::csvEnv('AI_ENGINE_API_RAG_MIDDLEWARE'),
                'actions' => self::csvEnv('AI_ENGINE_API_ACTIONS_MIDDLEWARE'),
                'modules' => self::csvEnv('AI_ENGINE_API_MODULES_MIDDLEWARE'),
                'generate' => self::csvEnv('AI_ENGINE_API_GENERATE_MIDDLEWARE'),
                'data_collector' => self::csvEnv('AI_ENGINE_API_DATA_COLLECTOR_MIDDLEWARE'),
                'autonomous_collector' => self::csvEnv('AI_ENGINE_API_AUTONOMOUS_COLLECTOR_MIDDLEWARE'),
                'demo' => self::csvEnv('AI_ENGINE_API_DEMO_MIDDLEWARE'),
            ],

            // Full replacement is available in published config:
            // 'replace' => ['rag' => ['api', 'auth:sanctum', ...], ...]
            'replace' => [
                'rag' => [],
                'actions' => [],
                'modules' => [],
                'generate' => [],
                'data_collector' => [],
                'autonomous_collector' => [],
                'demo' => [],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Media Library
    |--------------------------------------------------------------------------
    |
    | Persistent storage for generated images, video, and audio outputs.
    | Configure a cloud disk here if you want returned file URLs to use your
    | own storage domain instead of provider URLs.
    |
    */
    'media_library' => [
        'enabled' => env('AI_ENGINE_MEDIA_ENABLED', true),
        'persist_records' => env('AI_ENGINE_MEDIA_PERSIST_RECORDS', true),
        'disk' => env('AI_ENGINE_MEDIA_DISK', env('FILESYSTEM_DISK', 'public')),
        'directory' => env('AI_ENGINE_MEDIA_DIRECTORY', 'ai-generated'),
        'visibility' => env('AI_ENGINE_MEDIA_VISIBILITY', 'public'),
        'remote_timeout' => (int) env('AI_ENGINE_MEDIA_REMOTE_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Provider Routing
    |--------------------------------------------------------------------------
    |
    | Cost-aware media routing lets applications choose the cheapest, local,
    | fastest, or highest-quality provider for image, video, and audio jobs.
    | Costs are estimates only; keep provider billing as the source of truth.
    |
    */
    'media_routing' => [
        'enabled' => env('AI_ENGINE_MEDIA_ROUTING_ENABLED', true),
        'default_mode' => env('AI_ENGINE_MEDIA_ROUTING_MODE', 'balanced'),
        'providers' => [
            'comfyui' => [
                'enabled' => env('AI_ENGINE_MEDIA_ROUTE_COMFYUI', true),
                'models' => [
                    'image' => ['model' => 'comfyui/default-image', 'estimated_unit_cost' => 0.0, 'quality_score' => 2.0, 'latency_score' => 3.0, 'local' => true],
                    'video' => ['model' => 'comfyui/default-video', 'estimated_unit_cost' => 0.0, 'quality_score' => 2.0, 'latency_score' => 8.0, 'local' => true],
                ],
            ],
            'cloudflare_workers_ai' => [
                'enabled' => env('AI_ENGINE_MEDIA_ROUTE_CLOUDFLARE', true),
                'api_key_config' => ['ai-engine.engines.cloudflare_workers_ai.api_key', 'ai-engine.engines.cloudflare_workers_ai.account_id'],
                'models' => [
                    'image' => ['model' => '@cf/black-forest-labs/flux-1-schnell', 'estimated_unit_cost' => 0.001, 'quality_score' => 1.5, 'latency_score' => 1.0],
                    'audio_transcription' => ['model' => '@cf/openai/whisper', 'estimated_unit_cost' => 0.0005, 'quality_score' => 1.3, 'latency_score' => 1.0],
                    'audio_generation' => ['model' => '@cf/myshell-ai/melotts', 'estimated_unit_cost' => 0.0002, 'quality_score' => 1.0, 'latency_score' => 1.0],
                ],
            ],
            'huggingface' => [
                'enabled' => env('AI_ENGINE_MEDIA_ROUTE_HUGGINGFACE', true),
                'api_key_config' => 'ai-engine.engines.huggingface.api_key',
                'models' => [
                    'image' => ['model' => 'black-forest-labs/FLUX.1-schnell', 'estimated_unit_cost' => 0.003, 'quality_score' => 1.7, 'latency_score' => 3.0],
                    'audio_transcription' => ['model' => 'openai/whisper-large-v3', 'estimated_unit_cost' => 0.002, 'quality_score' => 1.6, 'latency_score' => 4.0],
                    'audio_generation' => ['model' => 'facebook/mms-tts', 'estimated_unit_cost' => 0.002, 'quality_score' => 1.1, 'latency_score' => 4.0],
                ],
            ],
            'replicate' => [
                'enabled' => env('AI_ENGINE_MEDIA_ROUTE_REPLICATE', true),
                'api_key_config' => 'ai-engine.engines.replicate.api_key',
                'models' => [
                    'image' => ['model' => 'black-forest-labs/flux-schnell', 'estimated_unit_cost' => 0.004, 'quality_score' => 1.7, 'latency_score' => 4.0],
                    'video' => ['model' => 'wavespeedai/wan-2.1-i2v-480p', 'estimated_unit_cost' => 0.09, 'quality_score' => 2.0, 'latency_score' => 8.0],
                ],
            ],
            'gemini' => [
                'enabled' => env('AI_ENGINE_MEDIA_ROUTE_GEMINI', true),
                'api_key_config' => 'ai-engine.engines.gemini.api_key',
                'models' => [
                    'image' => ['model' => 'imagen-4.0-fast-generate-001', 'estimated_unit_cost' => 0.02, 'quality_score' => 2.2, 'latency_score' => 2.0],
                    'video' => ['model' => 'veo-3.1-fast-generate-preview', 'estimated_unit_cost' => 0.05, 'quality_score' => 2.4, 'latency_score' => 8.0],
                    'audio_generation' => ['model' => 'lyria-002', 'estimated_unit_cost' => 0.04, 'quality_score' => 2.0, 'latency_score' => 6.0],
                ],
            ],
            'fal_ai' => [
                'enabled' => env('AI_ENGINE_MEDIA_ROUTE_FAL', true),
                'api_key_config' => 'ai-engine.engines.fal_ai.api_key',
                'models' => [
                    'image' => ['model' => 'fal-flux-schnell', 'estimated_unit_cost' => 0.003, 'quality_score' => 1.8, 'latency_score' => 2.0],
                    'video' => ['model' => 'bytedance/seedance-2.0/image-to-video', 'estimated_unit_cost' => 0.08, 'quality_score' => 2.2, 'latency_score' => 7.0],
                    'audio_generation' => ['model' => 'fal-ai/elevenlabs/tts', 'estimated_unit_cost' => 0.003, 'quality_score' => 1.8, 'latency_score' => 3.0],
                ],
            ],
            'openai' => [
                'enabled' => env('AI_ENGINE_MEDIA_ROUTE_OPENAI', true),
                'api_key_config' => 'ai-engine.engines.openai.api_key',
                'models' => [
                    'image' => ['model' => 'gpt-image-1-mini', 'estimated_unit_cost' => 0.02, 'quality_score' => 2.1, 'latency_score' => 2.0],
                    'audio_transcription' => ['model' => 'whisper-1', 'estimated_unit_cost' => 0.006, 'quality_score' => 1.8, 'latency_score' => 2.0],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Tool Lifecycle
    |--------------------------------------------------------------------------
    |
    | Tracks provider-hosted tools such as OpenAI/Anthropic computer use,
    | code execution, MCP tools, web/file search, and generated artifacts.
    |
    */
    'provider_tools' => [
        'lifecycle' => [
            'enabled' => env('AI_ENGINE_PROVIDER_TOOL_LIFECYCLE_ENABLED', true),
            'store_payloads' => env('AI_ENGINE_PROVIDER_TOOL_STORE_PAYLOADS', true),
        ],
        'approvals' => [
            'enabled' => env('AI_ENGINE_PROVIDER_TOOL_APPROVALS_ENABLED', true),
            'expires_after_minutes' => (int) env('AI_ENGINE_PROVIDER_TOOL_APPROVAL_EXPIRES_AFTER', 0),
            'require_for' => ['computer_use', 'mcp_server', 'code_interpreter'],
            'require_risk_level_at_or_above' => env('AI_ENGINE_PROVIDER_TOOL_APPROVAL_RISK_THRESHOLD'),
            'require_for_sensitive_payloads' => env('AI_ENGINE_PROVIDER_TOOL_APPROVAL_SENSITIVE_PAYLOADS', true),
            'sensitive_keys' => [
                'password',
                'secret',
                'token',
                'api_key',
                'authorization',
                'cookie',
                'private_key',
                'card_number',
                'ssn',
            ],
            'sensitive_patterns' => [
                '/sk-[A-Za-z0-9_\-]{16,}/',
                '/-----BEGIN (?:RSA |EC |OPENSSH |)PRIVATE KEY-----/',
                '/\b\d{13,19}\b/',
            ],
            'risk_levels' => [
                'computer_use' => 'high',
                'mcp_server' => 'medium',
                'code_interpreter' => 'medium',
                'file_search' => 'low',
                'web_search' => 'low',
            ],
        ],
        'audit' => [
            'enabled' => env('AI_ENGINE_PROVIDER_TOOL_AUDIT_ENABLED', true),
        ],
        'artifacts' => [
            'enabled' => env('AI_ENGINE_PROVIDER_TOOL_ARTIFACTS_ENABLED', true),
            'persist_remote_files' => env('AI_ENGINE_PROVIDER_TOOL_PERSIST_REMOTE_ARTIFACTS', true),
            'max_per_run' => (int) env('AI_ENGINE_PROVIDER_TOOL_MAX_ARTIFACTS', 100),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Controls request locale detection for package routes and UI translations.
    |
    */
    'localization' => [
        'enabled' => env('AI_ENGINE_LOCALIZATION_ENABLED', true),
        'query_parameter' => env('AI_ENGINE_LOCALE_QUERY_PARAMETER', 'locale'),
        'header' => env('AI_ENGINE_LOCALE_HEADER', 'X-Locale'),
        'fallback_locale' => env('AI_ENGINE_FALLBACK_LOCALE', 'en'),
        'detect_from_accept_language' => env('AI_ENGINE_DETECT_LOCALE_FROM_ACCEPT_LANGUAGE', true),
        'detect_from_user' => env('AI_ENGINE_DETECT_LOCALE_FROM_USER', true),
        'detect_from_message' => env('AI_ENGINE_DETECT_LOCALE_FROM_MESSAGE', true),
        'user_locale_keys' => ['locale', 'language'],
        'supported_locales' => array_values(array_filter(array_map(
            static fn ($locale): string => trim((string) $locale),
            explode(',', (string) env('AI_ENGINE_SUPPORTED_LOCALES', 'en,ar'))
        ))),
        'rtl_locales' => array_values(array_filter(array_map(
            static fn ($locale): string => trim((string) $locale),
            explode(',', (string) env('AI_ENGINE_RTL_LOCALES', 'ar'))
        ))),
        'script_detection' => [
            'ar' => '/[\x{0600}-\x{06FF}]/u',
            'zh' => '/[\x{4E00}-\x{9FFF}]/u',
            'ja' => '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u',
            'ko' => '/[\x{AC00}-\x{D7AF}]/u',
            'ru' => '/[\x{0400}-\x{04FF}]/u',
            'el' => '/[\x{0370}-\x{03FF}]/u',
            'he' => '/[\x{0590}-\x{05FF}]/u',
            'th' => '/[\x{0E00}-\x{0E7F}]/u',
            'hi' => '/[\x{0900}-\x{097F}]/u',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Templates
    |--------------------------------------------------------------------------
    |
    | Optional override path for localized prompt templates. When empty, the
    | package bundled templates in resources/prompts are used.
    |
    */
    'prompt_templates' => [
        'path' => env('AI_ENGINE_PROMPT_TEMPLATES_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Summaries
    |--------------------------------------------------------------------------
    |
    | Minimal polymorphic summary cache for list rendering. Summaries are
    | generated from model content and persisted in ai_entity_summaries.
    |
    */
    'entity_summaries' => [
        'enabled' => env('AI_ENGINE_ENTITY_SUMMARIES_ENABLED', true),
        'use_in_list_responses' => env('AI_ENGINE_ENTITY_SUMMARIES_USE_IN_LIST', true),
        'default_locale' => env('AI_ENGINE_ENTITY_SUMMARIES_DEFAULT_LOCALE', 'en'),
        'ttl_minutes' => (int) env('AI_ENGINE_ENTITY_SUMMARIES_TTL_MINUTES', 10080), // 7 days
        'max_chars' => (int) env('AI_ENGINE_ENTITY_SUMMARIES_MAX_CHARS', 420),
    ],

    /*
    |--------------------------------------------------------------------------
    | Infrastructure Hardening
    |--------------------------------------------------------------------------
    |
    | Runtime safeguards for remote node readiness, vector connectivity,
    | and optional startup health gating.
    |
    */
    'infrastructure' => [
        'remote_node_migration_guard' => [
            'enabled' => env('AI_ENGINE_REMOTE_NODE_MIGRATION_GUARD', true),
            'required_tables' => [
                'ai_conversations',
                'ai_messages',
            ],
            'status_code' => (int) env('AI_ENGINE_REMOTE_NODE_MIGRATION_GUARD_STATUS', 503),
        ],

        'qdrant_self_check' => [
            'enabled' => env('AI_ENGINE_QDRANT_SELF_CHECK_ENABLED', true),
            'timeout_seconds' => (float) env('AI_ENGINE_QDRANT_SELF_CHECK_TIMEOUT', 5),
            'endpoint' => env('AI_ENGINE_QDRANT_SELF_CHECK_ENDPOINT', '/collections'),
        ],

        'startup_health_gate' => [
            'enabled' => env('AI_ENGINE_STARTUP_HEALTH_GATE_ENABLED', true),
            'strict' => env('AI_ENGINE_STARTUP_HEALTH_GATE_STRICT', false),
            'skip_in_console' => env('AI_ENGINE_STARTUP_HEALTH_GATE_SKIP_CONSOLE', true),
            'cache_seconds' => (int) env('AI_ENGINE_STARTUP_HEALTH_GATE_CACHE_SECONDS', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Context Injection
    |--------------------------------------------------------------------------
    |
    | Automatically inject authenticated user context into AI conversations.
    | When enabled, the AI will know the user's ID, name, email, role, etc.
    | This allows the AI to search for user-specific data in RAG searches.
    |
    */
    'inject_user_context' => env('AI_ENGINE_INJECT_USER_CONTEXT', true),

    /*
    |--------------------------------------------------------------------------
    | Demo User ID
    |--------------------------------------------------------------------------
    |
    | Optional user ID for explicitly enabled demo workflows. Production
    | runtime paths do not fall back to this value.
    |
    */
    'demo_user_id' => env('AI_ENGINE_DEMO_USER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Project Context
    |--------------------------------------------------------------------------
    |
    | Provide context about your project/application to help the AI understand
    | the domain and make better decisions. This context is injected into AI
    | prompts to improve relevance and accuracy of responses.
    |
    | Example: "This is a CRM system for HR departments that manages employee
    | data, recruitment, payroll, and performance reviews."
    |
    */
    'project_context' => [
        // Enable/disable project context injection
        'enabled' => env('AI_ENGINE_PROJECT_CONTEXT_ENABLED', true),

        // Main project description - describe what your application does
        // This helps the AI understand the domain and make better decisions
        'description' => env('AI_ENGINE_PROJECT_DESCRIPTION', ''),

        // Industry/domain (e.g., 'healthcare', 'finance', 'e-commerce', 'hr', 'education')
        'industry' => env('AI_ENGINE_PROJECT_INDUSTRY', ''),

        // Key entities/concepts in your system
        // Example: ['employees', 'departments', 'payroll', 'leave requests', 'performance reviews']
        'key_entities' => [],

        // Business rules or constraints the AI should be aware of
        // Example: ['All employee data is confidential', 'Payroll calculations follow local tax laws']
        'business_rules' => [],

        // Terminology/glossary specific to your domain
        // Example: ['PTO' => 'Paid Time Off', 'FTE' => 'Full Time Employee']
        'terminology' => [],

        // Target users of the system
        // Example: 'HR managers, department heads, and employees'
        'target_users' => env('AI_ENGINE_PROJECT_TARGET_USERS', ''),

        // Data sensitivity level: 'public', 'internal', 'confidential', 'restricted'
        'data_sensitivity' => env('AI_ENGINE_PROJECT_DATA_SENSITIVITY', 'internal'),

        // Additional custom context (free-form text)
        'additional_context' => env('AI_ENGINE_PROJECT_ADDITIONAL_CONTEXT', ''),

        // Document Type Detection Rules (for messages >500 chars)
        // Used by intent analysis to suggest appropriate collections based on document content
        'document_type_detection' => [
            'enabled' => env('AI_ENGINE_DOCUMENT_TYPE_DETECTION', true),
            'min_length' => 500, // Minimum message length to trigger document analysis

            // Detection rules for different document types
            'rules' => [
                'bill' => [
                    'description' => 'Vendor bill/invoice received from suppliers (user is buyer)',
                    'indicators' => ['Vendor:', 'From:', 'Supplier:'],
                    'suggested_collection' => 'Bill',
                    'reasoning' => 'Document shows vendor information - user is receiving from vendor (buyer role)',
                ],
                'invoice' => [
                    'description' => 'Customer invoice sent to clients (user is seller)',
                    'indicators' => ['Bill To:', 'Customer:', 'Invoice To:'],
                    'suggested_collection' => 'Invoice',
                    'reasoning' => 'Document shows customer information - user is sending to customer (seller role)',
                ],
                'product' => [
                    'description' => 'Product information without vendor/customer context',
                    'indicators' => ['Product:', 'SKU:', 'Price:', 'Quantity:'],
                    'suggested_collection' => 'ProductService',
                    'reasoning' => 'Document contains product details only',
                ],
                'customer' => [
                    'description' => 'Customer/contact information',
                    'indicators' => ['Contact:', 'Email:', 'Phone:', 'Address:'],
                    'suggested_collection' => 'Customer',
                    'reasoning' => 'Document contains contact/customer information',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vectorization Settings
    |--------------------------------------------------------------------------
    |
    | Configure how content is vectorized for RAG and vector search.
    |
    */
    'vectorization' => [
        // Chunking strategy: 'split' or 'truncate'
        // - 'split': Creates multiple embeddings for large content (recommended for RAG)
        // - 'truncate': Truncates content to fit (faster, but loses information)
        'strategy' => env('AI_ENGINE_VECTORIZATION_STRATEGY', 'split'),

        // Maximum size per field before chunking (in bytes)
        // Fields larger than this will be intelligently chunked (70% beginning + 30% end)
        // This preserves context while staying within limits
        'max_field_size' => env('AI_ENGINE_MAX_FIELD_SIZE', 100000), // 100KB

        // Chunk size for split strategy (in characters)
        // Each chunk will be this size (respecting token limits)
        // Leave null to auto-calculate based on embedding model
        'chunk_size' => env('AI_ENGINE_CHUNK_SIZE', null),

        // Chunk overlap (in characters)
        // Overlap between chunks to maintain context
        'chunk_overlap' => env('AI_ENGINE_CHUNK_OVERLAP', 200),

        // Maximum total content length after combining all fields (in characters)
        // Leave null to auto-calculate based on embedding model token limits
        // Or set a specific value to override (e.g., 6000 for conservative limit)
        'max_content_length' => env('AI_ENGINE_MAX_CONTENT_LENGTH', null),

        // Embedding model to use for vectorization
        // Different models have different token limits:
        // - text-embedding-3-small: 8191 tokens
        // - text-embedding-3-large: 8191 tokens
        // - text-embedding-ada-002: 8191 tokens
        // - voyage-large-2: 16000 tokens
        // - cohere embed-*: 512 tokens
        // - mistral-embed: 8192 tokens
        'embedding_model' => env('AI_ENGINE_EMBEDDING_MODEL', 'text-embedding-3-small'),

        // Maximum media content size (in bytes)
        // Media content (image descriptions, transcriptions) larger than this will be truncated
        'max_media_content' => env('AI_ENGINE_MAX_MEDIA_CONTENT', 50000), // 50KB

        // Maximum media file size to download (in bytes)
        // Files larger than this will be skipped to prevent memory issues
        'max_media_file_size' => env('AI_ENGINE_MAX_MEDIA_FILE_SIZE', 10485760), // 10MB

        // Enable processing of large media files
        // When true, large videos/audio will be split into chunks and processed
        // When false, large files are skipped entirely
        'process_large_media' => env('AI_ENGINE_PROCESS_LARGE_MEDIA', false),

        // Chunk size for large media files (in seconds for video/audio)
        // Large media will be split into chunks of this duration
        'media_chunk_duration' => env('AI_ENGINE_MEDIA_CHUNK_DURATION', 60), // 60 seconds
    ],

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
    'legacy_chat_routes' => [
        'enabled' => env('AI_ENGINE_LEGACY_CHAT_ROUTES_ENABLED', false),
    ],
    'auth_routes' => [
        'enabled' => env('AI_ENGINE_AUTH_ROUTES_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin UI Routes
    |--------------------------------------------------------------------------
    |
    | Optional admin control-plane UI for diagnostics, manifest visibility,
    | and package bootstrap operations.
    |
    | Access is granted when either:
    | - Authenticated user matches allowed_user_ids / allowed_emails
    | - Request IP matches allowed_ips (supports CIDR)
    |
    | If user allowlists are empty, any authenticated user is allowed.
    |
    */
    'admin_ui' => [
        'enabled' => env('AI_ENGINE_ENABLE_ADMIN_UI', false),
        'route_prefix' => env('AI_ENGINE_ADMIN_PREFIX', 'ai-engine/admin'),
        'middleware' => ['web'],
        'access' => [
            'allowed_user_ids' => array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                explode(',', (string) env('AI_ENGINE_ADMIN_ALLOWED_USER_IDS', ''))
            ))),
            'allowed_emails' => array_values(array_filter(array_map(
                static fn ($value): string => strtolower(trim((string) $value)),
                explode(',', (string) env('AI_ENGINE_ADMIN_ALLOWED_EMAILS', ''))
            ))),
            'allowed_ips' => array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                explode(',', (string) env('AI_ENGINE_ADMIN_ALLOWED_IPS', ''))
            ))),
            'allow_localhost' => env('AI_ENGINE_ADMIN_ALLOW_LOCALHOST', true),
        ],
    ],

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
                'gpt-5-mini' => ['enabled' => true, 'credit_index' => 1.0],
                'gpt-4o-mini' => ['enabled' => true, 'credit_index' => 0.5],
                'gpt-4o' => ['enabled' => true, 'credit_index' => 2.0],
                'gpt-image-1.5' => ['enabled' => true, 'credit_index' => 6.0],
                'gpt-image-1' => ['enabled' => true, 'credit_index' => 5.0],
                'gpt-image-1-mini' => ['enabled' => true, 'credit_index' => 2.0],
                'dall-e-3' => ['enabled' => true, 'credit_index' => 5.0],
                'whisper-1' => ['enabled' => true, 'credit_index' => 1.0],
            ],
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'timeout' => env('ANTHROPIC_TIMEOUT', 30),
            'models' => [
                'claude-4-sonnet' => ['enabled' => true, 'credit_index' => 4.0],
                'claude-3-5-sonnet-20241022' => ['enabled' => true, 'credit_index' => 2.0],
                'claude-3-5-sonnet-20240620' => ['enabled' => true, 'credit_index' => 1.8],
                'claude-3-5-haiku-20241022' => ['enabled' => true, 'credit_index' => 0.5],
            ],
        ],

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'timeout' => env('GEMINI_TIMEOUT', 30),
            'models' => [
                'gemini-2.5-flash' => ['enabled' => true, 'credit_index' => 1.5],
                'gemini-2.5-pro' => ['enabled' => true, 'credit_index' => 4.0],
                'imagen-4.0-fast-generate-001' => ['enabled' => true, 'credit_index' => 0.8, 'content_type' => 'image'],
                'imagen-4.0-generate-001' => ['enabled' => true, 'credit_index' => 1.5, 'content_type' => 'image'],
                'veo-3.1-generate-preview' => ['enabled' => true, 'credit_index' => 8.0, 'content_type' => 'video'],
                'veo-3.1-fast-generate-preview' => ['enabled' => true, 'credit_index' => 4.0, 'content_type' => 'video'],
                'lyria-002' => ['enabled' => true, 'credit_index' => 1.0, 'content_type' => 'audio'],
                'gemini-1.5-flash' => ['enabled' => true, 'credit_index' => 0.4],
                'gemini-1.5-pro' => ['enabled' => true, 'credit_index' => 1.5],
            ],
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'api_key' => env('DEEPSEEK_API_KEY'),
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            'timeout' => env('DEEPSEEK_TIMEOUT', 60),
            'models' => [
                'deepseek-v3' => ['enabled' => true, 'credit_index' => 1.0],
                'deepseek-chat' => ['enabled' => true, 'credit_index' => 0.5],
                'deepseek-r1' => ['enabled' => true, 'credit_index' => 2.0],
            ],
        ],

        'stable_diffusion' => [
            'driver' => 'stable_diffusion',
            'api_key' => env('STABILITY_API_KEY'),
            'base_url' => env('STABILITY_BASE_URL', 'https://api.stability.ai'),
            'timeout' => env('STABILITY_TIMEOUT', 60),
            'models' => [
                'sd3-medium' => ['enabled' => true, 'credit_index' => 3.0],
                'sdxl-1024-v1-0' => ['enabled' => true, 'credit_index' => 2.5],
            ],
        ],

        'eleven_labs' => [
            'driver' => 'eleven_labs',
            'api_key' => env('ELEVENLABS_API_KEY'),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),
            'timeout' => env('ELEVENLABS_TIMEOUT', 60),
            'default_voice_id' => env('ELEVENLABS_VOICE_ID', 'pNInz6obpgDQGcFmaJgB'),
            'models' => [
                'eleven_multilingual_v2' => ['enabled' => true, 'credit_index' => 1.2],
                'eleven_turbo_v2' => ['enabled' => true, 'credit_index' => 1.0],
                'eleven_monolingual_v1' => ['enabled' => true, 'credit_index' => 0.9],
            ],
        ],

        'fal_ai' => [
            'driver' => 'fal_ai',
            'api_key' => env('FAL_AI_API_KEY', env('FAL_API_KEY', env('FALAI_API_KEY'))),
            'base_url' => env('FAL_BASE_URL', 'https://fal.run'),
            'queue_base_url' => env('FAL_QUEUE_BASE_URL', 'https://queue.fal.run'),
            'timeout' => env('FAL_TIMEOUT', 180),
            'character_generation' => [
                'queue' => env('FAL_CHARACTER_QUEUE', 'ai-media'),
            ],
            'reference_pack' => [
                'queue' => env('FAL_REFERENCE_PACK_QUEUE', env('FAL_CHARACTER_QUEUE', 'ai-media')),
            ],
            'async' => [
                'webhook_url' => env('FAL_ASYNC_WEBHOOK_URL'),
                'reference_pack_webhook_url' => env('FAL_REFERENCE_PACK_WEBHOOK_URL'),
            ],
            'catalog_sync' => [
                'limit' => env('FAL_CATALOG_SYNC_LIMIT', 100),
                'max_pages' => env('FAL_CATALOG_SYNC_MAX_PAGES', 50),
                'status' => env('FAL_CATALOG_SYNC_STATUS', 'active'),
            ],
            'catalog_execution' => [
                'validate_schema' => env('FAL_CATALOG_EXECUTION_VALIDATE_SCHEMA', true),
            ],
            'models' => [
                'fal-flux-pro' => ['enabled' => true, 'credit_index' => 3.5],
                'fal-flux-dev' => ['enabled' => true, 'credit_index' => 2.5],
                'fal-flux-schnell' => ['enabled' => true, 'credit_index' => 1.5],
                'fal-sdxl' => ['enabled' => true, 'credit_index' => 2.0],
                'fal-sd3-medium' => ['enabled' => true, 'credit_index' => 2.5],
                'fal-ai/nano-banana-2' => ['enabled' => true, 'credit_index' => 3.8],
                'fal-ai/nano-banana-2/edit' => ['enabled' => true, 'credit_index' => 3.9],
                'fal-ai/kling-video/o3/standard/image-to-video' => ['enabled' => true, 'credit_index' => 8.0],
                'fal-ai/kling-video/o3/standard/reference-to-video' => ['enabled' => true, 'credit_index' => 8.0],
                'bytedance/seedance-2.0/text-to-video' => ['enabled' => true, 'credit_index' => 7.5],
                'bytedance/seedance-2.0/image-to-video' => ['enabled' => true, 'credit_index' => 7.5],
                'bytedance/seedance-2.0/reference-to-video' => ['enabled' => true, 'credit_index' => 7.5],
            ],
        ],

        'cloudflare_workers_ai' => [
            'driver' => 'cloudflare_workers_ai',
            'api_key' => env('CLOUDFLARE_API_TOKEN', env('CLOUDFLARE_WORKERS_AI_API_TOKEN')),
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
            'base_url' => env('CLOUDFLARE_API_BASE_URL', 'https://api.cloudflare.com/client/v4'),
            'timeout' => env('CLOUDFLARE_WORKERS_AI_TIMEOUT', 60),
            'models' => [
                '@cf/black-forest-labs/flux-1-schnell' => ['enabled' => true, 'credit_index' => 0.4, 'content_type' => 'image'],
                '@cf/lykon/dreamshaper-8-lcm' => ['enabled' => true, 'credit_index' => 0.3, 'content_type' => 'image'],
                '@cf/openai/whisper' => ['enabled' => true, 'credit_index' => 0.2, 'content_type' => 'audio'],
                '@cf/myshell-ai/melotts' => ['enabled' => true, 'credit_index' => 0.2, 'content_type' => 'audio'],
            ],
        ],

        'huggingface' => [
            'driver' => 'huggingface',
            'api_key' => env('HUGGINGFACE_API_KEY', env('HF_TOKEN')),
            'base_url' => env('HUGGINGFACE_BASE_URL', 'https://api-inference.huggingface.co'),
            'provider' => env('HUGGINGFACE_INFERENCE_PROVIDER', 'auto'),
            'timeout' => env('HUGGINGFACE_TIMEOUT', 120),
            'models' => [
                'black-forest-labs/FLUX.1-schnell' => ['enabled' => true, 'credit_index' => 0.6, 'content_type' => 'image'],
                'openai/whisper-large-v3' => ['enabled' => true, 'credit_index' => 0.4, 'content_type' => 'audio'],
                'facebook/mms-tts' => ['enabled' => true, 'credit_index' => 0.4, 'content_type' => 'audio'],
            ],
        ],

        'replicate' => [
            'driver' => 'replicate',
            'api_key' => env('REPLICATE_API_TOKEN', env('REPLICATE_API_KEY')),
            'base_url' => env('REPLICATE_BASE_URL', 'https://api.replicate.com/v1'),
            'timeout' => env('REPLICATE_TIMEOUT', 180),
            'models' => [
                'black-forest-labs/flux-schnell' => ['enabled' => true, 'credit_index' => 0.8, 'content_type' => 'image'],
                'wavespeedai/wan-2.1-i2v-480p' => ['enabled' => true, 'credit_index' => 4.0, 'content_type' => 'video'],
            ],
        ],

        'comfyui' => [
            'driver' => 'comfyui',
            'base_url' => env('COMFYUI_BASE_URL', 'http://127.0.0.1:8188'),
            'timeout' => env('COMFYUI_TIMEOUT', 300),
            'default_workflow' => null,
            'models' => [
                'comfyui/default-image' => ['enabled' => true, 'credit_index' => 0.01, 'content_type' => 'image'],
                'comfyui/default-video' => ['enabled' => true, 'credit_index' => 0.01, 'content_type' => 'video'],
            ],
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'site_url' => env('OPENROUTER_SITE_URL', env('APP_URL')),
            'site_name' => env('OPENROUTER_SITE_NAME', env('APP_NAME')),
            'timeout' => env('OPENROUTER_TIMEOUT', 30),
            'transforms' => [],
            'route' => null,
            'models' => [
                'openai/gpt-5-mini' => ['enabled' => true, 'credit_index' => 2.5],
                'openai/gpt-4o-mini' => ['enabled' => true, 'credit_index' => 0.6],
                'anthropic/claude-3.5-sonnet' => ['enabled' => true, 'credit_index' => 2.0],
                'google/gemini-2.5-flash' => ['enabled' => true, 'credit_index' => 1.9],
                'meta-llama/llama-3.1-8b-instruct:free' => ['enabled' => true, 'credit_index' => 0.0],
            ],
        ],

        'nvidia_nim' => [
            'driver' => 'nvidia_nim',
            'api_key' => env('NVIDIA_NIM_API_KEY', env('NVIDIA_API_KEY')),
            'base_url' => env('NVIDIA_NIM_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
            'timeout' => env('NVIDIA_NIM_TIMEOUT', 60),
            'default_model' => env('NVIDIA_NIM_DEFAULT_MODEL', 'nvidia/llama-3.1-nemotron-70b-instruct'),
            'models' => [
                'nvidia/llama-3.1-nemotron-70b-instruct' => ['enabled' => true, 'credit_index' => 1.0],
                'meta/llama-3.1-70b-instruct' => ['enabled' => true, 'credit_index' => 0.8],
                'meta/llama-3.1-8b-instruct' => ['enabled' => true, 'credit_index' => 0.3],
            ],
        ],

        'ollama' => [
            'driver' => 'ollama',
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'timeout' => env('OLLAMA_TIMEOUT', 120),
            'default_model' => env('OLLAMA_DEFAULT_MODEL', 'llama3.2'),
            'models' => [
                'llama3.2' => ['enabled' => true, 'credit_index' => 0.0],
                'mistral' => ['enabled' => true, 'credit_index' => 0.0],
                'deepseek-coder' => ['enabled' => true, 'credit_index' => 0.0],
                'qwen' => ['enabled' => true, 'credit_index' => 0.0],
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
        'currency' => env('AI_CREDITS_CURRENCY', 'MyCredits'),

        // Credit Owner Model Configuration
        // Set this to your Tenant, Workspace, Organization, or User model
        // Examples:
        //   - 'App\\Models\\User' (default - user-based credits)
        //   - 'App\\Models\\Tenant' (tenant-based credits)
        //   - 'App\\Models\\Workspace' (workspace-based credits)
        //   - 'App\\Models\\Organization' (organization-based credits)
        'owner_model' => env('AI_CREDITS_OWNER_MODEL', 'App\\Models\\User'),

        // ID Column Name
        // The column name used to identify the credit owner
        // Examples: 'id', 'tenant_id', 'workspace_id', 'organization_id'
        'owner_id_column' => env('AI_CREDITS_OWNER_ID_COLUMN', 'id'),

        // Custom User ID Resolver (Multi-Tenant Support)
        // Specify a custom class to resolve the owner ID for credit management
        // This is useful for multi-tenant applications where credits are tied to
        // tenants, workspaces, or organizations instead of individual users.
        //
        // The resolver class should implement one of the following:
        //   - __invoke() method (callable)
        //   - resolve() method
        //   - static resolveUserId() method
        //
        // Example resolver that prioritizes tenant_id over workspace_id over user_id:
        //   'user_id_resolver' => \App\Services\AI\TenantUserIdResolver::class,
        //
        // Set to null to use default auth()->id() behavior
        'user_id_resolver' => env('AI_USER_ID_RESOLVER', null),

        // Custom Query Resolver (Advanced)
        // Specify a custom class to override how the owner model is queried
        // This allows you to add custom conditions, eager loading, or complex logic
        // based on owner type or other factors.
        //
        // The resolver class should implement one of the following:
        //   - __invoke($ownerId) method - returns Model instance
        //   - resolve($ownerId) method - returns Model instance
        //   - query($ownerId) method - returns Query Builder instance
        //
        // Example use cases:
        //   - Add where conditions: ->where('status', 'active')
        //   - Eager load relationships: ->with('subscription')
        //   - Different queries per owner type
        //   - Soft delete filtering
        //
        // Example:
        //   'query_resolver' => \App\Services\AI\TenantQueryResolver::class,
        //
        // Set to null to use default query: Model::where($column, $ownerId)->firstOrFail()
        'query_resolver' => env('AI_QUERY_RESOLVER', null),

        // Custom Credit Lifecycle Handler (Advanced)
        // Specify a custom class to override credit management logic
        // This allows you to implement custom credit expiration, validation,
        // deduction strategies, and other business rules.
        //
        // The handler class must implement CreditLifecycleInterface with methods:
        //   - hasCredits(Model $owner, AIRequest $request): bool
        //   - deductCredits(Model $owner, AIRequest $request, float $credits): bool
        //   - addCredits(Model $owner, float $credits, array $metadata): bool
        //   - getAvailableCredits(Model $owner): float
        //   - hasLowCredits(Model $owner): bool
        //
        // Example use cases:
        //   - Credit expiration dates
        //   - Priority-based credit consumption
        //   - Credit packages with different rates
        //   - Subscription-based unlimited credits
        //   - Credit history tracking
        //
        // Example:
        //   'lifecycle_handler' => \LaravelAIEngine\Handlers\ExpiringCreditHandler::class,
        //
        // The package includes ExpiringCreditHandler for credit expiration support.
        // To use it, publish migrations: php artisan vendor:publish --tag=ai-engine-migrations
        //
        // Set to null to use default credit management
        'lifecycle_handler' => env('AI_LIFECYCLE_HANDLER', null),

        // Engine conversion rates: MyCredits to Engine Credits
        // Example: 'openai' => 2.0 means 100 MyCredits = 50 OpenAI credits
        'engine_rates' => [
            'openai' => env('AI_OPENAI_RATE', 2.0),      // 2:1 ratio
            'anthropic' => env('AI_ANTHROPIC_RATE', 3.0), // 3:1 ratio
            'gemini' => env('AI_GEMINI_RATE', 1.2),       // 20% margin
            'fal_ai' => env('AI_FAL_AI_RATE', 1.3),        // 30% margin
            'cloudflare_workers_ai' => env('AI_CLOUDFLARE_WORKERS_AI_RATE', 1.2),
            'huggingface' => env('AI_HUGGINGFACE_RATE', 1.2),
            'replicate' => env('AI_REPLICATE_RATE', 1.25),
            'comfyui' => env('AI_COMFYUI_RATE', 1.0),
            'openrouter' => env('AI_OPENROUTER_RATE', 2.5), // 2.5:1 ratio
            'nvidia_nim' => env('AI_NVIDIA_NIM_RATE', 1.5), // 1.5:1 ratio
        ],

        // Fixed extra engine-credit charges for provider input media that are not
        // the primary billable output unit. Keep these fixed per input unit so
        // reference/start/end images do not scale unpredictably with output cost.
        'additional_input_unit_rates' => [
            'fal_ai' => [
                'default' => [
                    'image' => env('AI_FAL_INPUT_IMAGE_CREDIT_RATE', 0.25),
                ],
                'models' => [
                    'fal-ai/nano-banana-2/edit' => [
                        'image' => env('AI_FAL_EDIT_INPUT_IMAGE_CREDIT_RATE', 0.5),
                    ],
                    'fal-ai/kling-video/o3/standard/image-to-video' => [
                        'image' => env('AI_FAL_VIDEO_INPUT_IMAGE_CREDIT_RATE', 0.5),
                    ],
                    'fal-ai/kling-video/o3/standard/reference-to-video' => [
                        'image' => env('AI_FAL_VIDEO_REFERENCE_IMAGE_CREDIT_RATE', 0.5),
                    ],
                    'bytedance/seedance-2.0/image-to-video' => [
                        'image' => env('AI_FAL_SEEDANCE_INPUT_IMAGE_CREDIT_RATE', 0.75),
                    ],
                    'bytedance/seedance-2.0/reference-to-video' => [
                        'image' => env('AI_FAL_SEEDANCE_REFERENCE_IMAGE_CREDIT_RATE', 0.75),
                    ],
                ],
            ],
        ],
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
            'cloudflare_workers_ai' => ['requests' => 60, 'per_minute' => 1],
            'huggingface' => ['requests' => 60, 'per_minute' => 1],
            'replicate' => ['requests' => 30, 'per_minute' => 1],
            'comfyui' => ['requests' => 60, 'per_minute' => 1],
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
            'stable_diffusion' => ['cloudflare_workers_ai', 'huggingface', 'replicate', 'comfyui', 'openrouter'],
            'cloudflare_workers_ai' => ['huggingface', 'replicate', 'fal_ai', 'comfyui'],
            'huggingface' => ['cloudflare_workers_ai', 'replicate', 'fal_ai', 'comfyui'],
            'replicate' => ['huggingface', 'fal_ai', 'cloudflare_workers_ai', 'comfyui'],
            'comfyui' => ['cloudflare_workers_ai', 'huggingface', 'replicate', 'fal_ai'],
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
    | Canonical defaults for direct requests and provider selection.
    |
    */
    'default_model' => env('AI_ENGINE_DEFAULT_MODEL', 'gpt-4o'),

    /*
    |--------------------------------------------------------------------------
    | Direct Request Routing
    |--------------------------------------------------------------------------
    |
    | Controls provider selection for direct generation requests when callers
    | omit `engine` and pass only a model or defaults. Use the special
    | `native` token to prefer the model's direct provider before aggregator
    | providers such as OpenRouter.
    |
    */
    'request_routing' => [
        'provider_priority' => self::csvEnv(
            'AI_ENGINE_REQUEST_PROVIDER_PRIORITY',
            'native;openrouter;anthropic;gemini;deepseek;nvidia_nim;ollama'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scope Resolution
    |--------------------------------------------------------------------------
    |
    | Resolves trusted tenant/workspace context once and injects it into
    | AI, RAG, and agent requests. Explicit per-request options still win.
    |
    */
    'scope' => [
        'auto_inject' => env('AI_ENGINE_SCOPE_AUTO_INJECT', true),
        'resolver' => env('AI_ENGINE_SCOPE_RESOLVER', null),
        'tenant_user_fields' => ['tenant_id', 'organization_id', 'company_id', 'team_id'],
        'workspace_user_fields' => ['workspace_id', 'current_workspace_id'],
    ],

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
    | Plural Rules Configuration
    |--------------------------------------------------------------------------
    |
    | Define custom plural rules ONLY for words that don't follow standard
    | English pluralization rules or need special handling in your domain.
    |
    | Standard rules are automatically applied:
    | - Words ending in 'y' (consonant + y) → 'ies' (city → cities)
    | - Words ending in 'ss', 'sh', 'ch', 'x', 'z' → add 'es' (box → boxes)
    | - Words ending in 'f' or 'fe' → 'ves' (knife → knives)
    | - Most other words → add 's' (product → products)
    |
    | Only add rules here if:
    | 1. The word has an irregular plural (person → people)
    | 2. You need domain-specific pluralization
    | 3. The standard rules don't work for your use case
    |
    | Format: 'singular' => 'plural'
    |
    | Examples:
    | 'person' => 'people',
    | 'analysis' => 'analyses',
    | 'datum' => 'data',
    |
    */
    'plural_rules' => [
        // Add your custom plural rules here
        // Leave empty to use standard English pluralization rules
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

        // AI-Driven Thresholds and Limits
        'thresholds' => [
            // Confidence threshold for auto-executing actions without confirmation
            'auto_execute_confidence' => env('AI_AUTO_EXECUTE_CONFIDENCE', 0.95),

            // Conversation history limits
            'conversation_threshold' => env('AI_CONVERSATION_THRESHOLD', 21),
            'recent_messages_count' => env('AI_RECENT_MESSAGES_COUNT', 5),

            // Token limits
            'max_tokens' => env('AI_MAX_TOKENS', 2000),
            'max_tokens_rag' => env('AI_MAX_TOKENS_RAG', 4000),
        ],

        // Intent Analysis - AI-powered message analysis for smarter responses
        // Analyzes user intent (confirm, reject, modify, provide_data, question, new_request)
        // and enhances AI prompts with context for more intelligent responses
        'intent_analysis' => env('AI_INTENT_ANALYSIS_ENABLED', true),

        // Intent Analysis Model - Use faster/cheaper model for simple intent classification
        // Options: 'gpt-3.5-turbo' (fastest/cheapest), 'gpt-4o-mini' (more accurate)
        // Intent analysis is a simple task, gpt-3.5-turbo is recommended for performance
        'intent_model' => env('AI_INTENT_MODEL', 'gpt-3.5-turbo'),

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

        // Testing utilities (disable external embedding API calls when enabled)
        'testing' => [
            'use_fake_embeddings' => env('AI_ENGINE_USE_FAKE_EMBEDDINGS', false),
        ],

        // Collection settings
        'collection_prefix' => env('VECTOR_COLLECTION_PREFIX', 'vec_'),

        // Payload index fields - fields that will be indexed for filtering
        // Types are auto-detected from database schema
        // These are automatically created when collections are created
        'payload_index_fields' => [
            'user_id',
            'tenant_id',
            'workspace_id',
            'model_id',
            'status',
            'visibility',
            'type',
        ],

        // Caching
        'cache_embeddings' => env('VECTOR_CACHE_EMBEDDINGS', true),
        'cache_ttl' => env('VECTOR_CACHE_TTL', 86400), // 24 hours

        // Batch processing
        'batch_size' => env('VECTOR_BATCH_SIZE', 100),

        // Maximum models to hydrate from search results (prevents memory exhaustion)
        'max_hydrate_results' => env('VECTOR_MAX_HYDRATE_RESULTS', 50),

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

        // Maximum content size for embedding (content larger than this will be chunked)
        // OpenAI text-embedding-3-* has 8191 token limit ≈ ~6000 chars (avg 1.3 chars/token)
        // Using 5500 chars as safe default to account for special characters and encoding
        'max_content_size' => env('VECTOR_MAX_CONTENT_SIZE', 5500),

        // Multi-chunk indexing: create multiple vector points for large documents
        // When enabled, large content is split into chunks, each with its own embedding
        // This provides better semantic coverage for large documents
        'multi_chunk_enabled' => env('VECTOR_MULTI_CHUNK_ENABLED', true),

        // Overlap between chunks (in characters) for context continuity
        'chunk_overlap' => env('VECTOR_CHUNK_OVERLAP', 200),

        // Health check configuration
        'health_check' => [
            'enabled' => env('VECTOR_HEALTH_CHECK_ENABLED', true),
            'interval_minutes' => env('VECTOR_HEALTH_CHECK_INTERVAL', 10),
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
            'max_context_item_length' => env('VECTOR_RAG_MAX_ITEM_LENGTH', 2000), // chars per item
            'include_sources' => env('VECTOR_RAG_INCLUDE_SOURCES', true),
            'min_relevance_score' => env('VECTOR_RAG_MIN_SCORE', 0.5),
            'user_scope_fields' => ['user_id', 'created_by', 'owner_id'],
            'tenant_scope_fields' => ['tenant_id', 'organization_id', 'company_id', 'team_id'],
            'workspace_scope_fields' => ['workspace_id', 'current_workspace_id'],
            'require_structured_scope' => env('AI_ENGINE_RAG_REQUIRE_STRUCTURED_SCOPE', true),

            // Query Analysis Model
            // The model used for analyzing queries and determining search strategy
            // null = use default model from engines.openai.model
            // Driver automatically handles model-specific parameters (GPT-5, o1, etc.)
            'analysis_model' => env('AI_ENGINE_RAG_ANALYSIS_MODEL', null),

            // Dynamic Context Limitations
            'auto_update_limitations' => env('RAG_AUTO_UPDATE_LIMITATIONS', true),
            'limitations_cache_ttl' => env('RAG_LIMITATIONS_CACHE_TTL', 300), // 5 minutes

            // Access Level Limits
            'access_levels' => [
                'admin' => [
                    'max_results' => 20,
                    'max_tokens' => 8000,
                    'time_range_days' => null, // unlimited
                ],
                'premium' => [
                    'max_results' => 15,
                    'max_tokens' => 6000,
                    'time_range_days' => null,
                ],
                'basic' => [
                    'max_results' => 10,
                    'max_tokens' => 4000,
                    'time_range_days' => 30,
                ],
                'guest' => [
                    'max_results' => 5,
                    'max_tokens' => 2000,
                    'time_range_days' => 7,
                ],
            ],

            // Data Volume Adjustments
            'volume_thresholds' => [
                'low' => 100,      // < 100 records
                'medium' => 1000,  // < 1,000 records
                'high' => 10000,   // < 10,000 records
                // >= 10,000 = very_high
            ],
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
    | RAG Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for RAG (Retrieval-Augmented Generation).
    | The AI automatically decides when to search the knowledge base.
    |
    */
    'rag' => [
        'enabled' => env('AI_ENGINE_RAG_ENABLED', true),
        'user_scope_fields' => ['user_id', 'created_by', 'owner_id'],
        'tenant_scope_fields' => ['tenant_id', 'organization_id', 'company_id', 'team_id'],
        'workspace_scope_fields' => ['workspace_id', 'current_workspace_id'],
        'require_structured_scope' => env('AI_ENGINE_RAG_REQUIRE_STRUCTURED_SCOPE', true),
        'hybrid' => [
            'enabled' => env('AI_ENGINE_HYBRID_RAG_ENABLED', false),
            'strategy' => env('AI_ENGINE_HYBRID_RAG_STRATEGY', 'vector_then_graph'),
            'vector_driver' => env('AI_ENGINE_HYBRID_RAG_VECTOR_DRIVER', env('VECTOR_DB_DRIVER', 'qdrant')),
            'graph_driver' => env('AI_ENGINE_HYBRID_RAG_GRAPH_DRIVER', env('AI_ENGINE_GRAPH_BACKEND', 'neo4j')),
            'vector_limit' => (int) env('AI_ENGINE_HYBRID_RAG_VECTOR_LIMIT', 10),
            'graph_limit' => (int) env('AI_ENGINE_HYBRID_RAG_GRAPH_LIMIT', 10),
            'vector_weight' => (float) env('AI_ENGINE_HYBRID_RAG_VECTOR_WEIGHT', 0.6),
            'graph_weight' => (float) env('AI_ENGINE_HYBRID_RAG_GRAPH_WEIGHT', 0.4),
            'rrf_k' => (int) env('AI_ENGINE_HYBRID_RAG_RRF_K', 60),
            'disable_graph_only_fallback' => env('AI_ENGINE_HYBRID_RAG_DISABLE_GRAPH_ONLY_FALLBACK', false),
        ],

        // Default collections to search (model class names)
        // If empty, the system will AUTO-DISCOVER all models with:
        // - Vectorizable trait
        // - RAGgable trait
        //
        // Simply add 'use Vectorizable;' or 'use RAGgable;' to your models!
        'default_collections' => env('AI_ENGINE_RAG_COLLECTIONS')
            ? explode(',', env('AI_ENGINE_RAG_COLLECTIONS'))
            : [
                // Leave empty for auto-discovery, or specify manually:
                // 'App\\Models\\Document',
                // 'App\\Models\\Post',
                // 'App\\Models\\Email',
                // 'App\\Models\\Article',
            ],

        // Auto-discovery settings
        'auto_discover' => env('AI_ENGINE_RAG_AUTO_DISCOVER', true),
        'discovery_cache_ttl' => env('AI_ENGINE_RAG_DISCOVERY_CACHE', 3600), // 1 hour

        // Discovery paths - where to look for models with Vectorizable trait
        // Namespaces are auto-detected from the actual PHP files
        // Supports glob patterns for modular architectures
        'discovery_paths' => [
            app_path('Models'),
            // Add custom paths here, for example:
            // base_path('modules/*/Models'),
            // base_path('packages/*/src/Models'),
            // base_path('src/Domain/*/Models'),
        ],

        // Maximum context items to retrieve
        'max_context_items' => env('AI_ENGINE_RAG_MAX_CONTEXT', 5),

        // Minimum relevance score (0-1) - Lower = more results, Higher = more precise
        // 0.3 = balanced (recommended), 0.5 = moderate, 0.7+ = strict/precise
        'min_relevance_score' => env('AI_ENGINE_RAG_MIN_SCORE', 0.3),

        // Fallback threshold when no results found (0.0 = return anything, null = no fallback)
        'fallback_threshold' => env('AI_ENGINE_RAG_FALLBACK_THRESHOLD', 0.0),

        // Autonomous mode: Single AI call decides everything (search, get_by_id, count, answer_from_context)
        // AI chooses fastest method: db_query (50ms) vs vector_search (5s)
        // Enabled by default for better performance
        'autonomous_mode' => env('AI_ENGINE_RAG_AUTONOMOUS_MODE', true),

        // Model to use for query analysis (fast model recommended: gpt-4o-mini, gpt-4o)
        // GPT-5 models are slower due to reasoning overhead - not recommended for analysis
        'analysis_model' => env('AI_ENGINE_RAG_ANALYSIS_MODEL', 'gpt-4o-mini'),

        // Model to use for final response generation
        // gpt-4o-mini is faster and cheaper, gpt-4o is more capable
        'response_model' => env('AI_ENGINE_RAG_RESPONSE_MODEL', 'gpt-5-mini'),

        // Include source citations in response
        'include_sources' => env('AI_ENGINE_RAG_INCLUDE_SOURCES', true),

        // Decision prompt configuration (tool-selection phase)
        'decision' => [
            // Optional custom template path. If empty, package default template is used.
            'template_path' => env('AI_ENGINE_RAG_DECISION_TEMPLATE_PATH'),

            // Language control strategy for decision-time deterministic guards:
            // - ai_first: rely on model reasoning, keep only minimal technical normalization
            // - hybrid: blend AI reasoning with locale lexicon guards (recommended)
            // - strict: enforce strongest lexicon-based disambiguation across supported locales
            'language_mode' => env('AI_ENGINE_RAG_DECISION_LANGUAGE_MODE', 'hybrid'),

            // Keep decision prompt compact to reduce token noise.
            'prompt_limits' => [
                'models' => (int) env('AI_ENGINE_RAG_DECISION_PROMPT_MODELS', 12),
                'model_fields' => (int) env('AI_ENGINE_RAG_DECISION_PROMPT_MODEL_FIELDS', 12),
                'model_tools' => (int) env('AI_ENGINE_RAG_DECISION_PROMPT_MODEL_TOOLS', 8),
                'nodes' => (int) env('AI_ENGINE_RAG_DECISION_PROMPT_NODES', 8),
                'node_collections' => (int) env('AI_ENGINE_RAG_DECISION_PROMPT_NODE_COLLECTIONS', 10),
            ],

            // Optional business profile for adaptive prompt instructions
            'business_context' => [
                'domain' => env('AI_ENGINE_BUSINESS_DOMAIN', ''),
                'priorities' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('AI_ENGINE_BUSINESS_PRIORITIES', ''))
                ))),
                'known_issues' => array_values(array_filter(array_map(
                    'trim',
                    explode('|', (string) env('AI_ENGINE_BUSINESS_KNOWN_ISSUES', ''))
                ))),
                'instructions' => array_values(array_filter(array_map(
                    'trim',
                    explode('|', (string) env('AI_ENGINE_BUSINESS_PROMPT_INSTRUCTIONS', ''))
                ))),
            ],

            // Lightweight adaptive feedback loop for prompt hints
            'adaptive_feedback' => [
                'enabled' => env('AI_ENGINE_RAG_DECISION_ADAPTIVE_FEEDBACK', true),
                'cache_key' => env('AI_ENGINE_RAG_DECISION_FEEDBACK_CACHE_KEY', 'ai_engine:rag_decision_feedback'),
                'window_hours' => env('AI_ENGINE_RAG_DECISION_FEEDBACK_WINDOW_HOURS', 48),
                'max_hints' => env('AI_ENGINE_RAG_DECISION_MAX_HINTS', 4),
                'persistence' => [
                    'enabled' => env('AI_ENGINE_RAG_DECISION_FEEDBACK_PERSISTENCE', true),
                    'table' => env('AI_ENGINE_RAG_DECISION_FEEDBACK_TABLE', 'ai_prompt_feedback_events'),
                ],
            ],

            // Versioned prompt policies (active/canary/shadow)
            'policy_store' => [
                'enabled' => env('AI_ENGINE_RAG_DECISION_POLICY_STORE_ENABLED', true),
                'table' => env('AI_ENGINE_RAG_DECISION_POLICY_TABLE', 'ai_prompt_policy_versions'),
                'default_key' => env('AI_ENGINE_RAG_DECISION_POLICY_KEY', 'decision'),
                'auto_seed_default' => env('AI_ENGINE_RAG_DECISION_POLICY_AUTO_SEED', true),
                'evaluation' => [
                    'window_hours' => env('AI_ENGINE_RAG_DECISION_POLICY_EVAL_WINDOW_HOURS', 168),
                    'min_samples' => env('AI_ENGINE_RAG_DECISION_POLICY_EVAL_MIN_SAMPLES', 30),
                    'min_score_delta' => env('AI_ENGINE_RAG_DECISION_POLICY_EVAL_MIN_SCORE_DELTA', 1.0),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Node Management (Master-Node Architecture)
    |--------------------------------------------------------------------------
    |
    | Configure the distributed master-node architecture for scaling across
    | multiple applications and services.
    |
    */
    'graph' => [
        'enabled' => env('AI_ENGINE_GRAPH_ENABLED', true),
        'backend' => env('AI_ENGINE_GRAPH_BACKEND', 'neo4j'),
        'reads_prefer_central_graph' => env('AI_ENGINE_GRAPH_READS_PREFER_CENTRAL', true),
        'sync_on_index' => env('AI_ENGINE_GRAPH_SYNC_ON_INDEX', false),
        'neighbor_limit' => (int) env('AI_ENGINE_GRAPH_NEIGHBOR_LIMIT', 3),
        'neighbor_result_limit' => (int) env('AI_ENGINE_GRAPH_NEIGHBOR_RESULT_LIMIT', 2),
        'attach_neighbor_context' => env('AI_ENGINE_GRAPH_ATTACH_NEIGHBOR_CONTEXT', true),
        'max_traversal_hops' => (int) env('AI_ENGINE_GRAPH_MAX_TRAVERSAL_HOPS', 2),
        'relation_result_boost' => (float) env('AI_ENGINE_GRAPH_RELATION_RESULT_BOOST', 0.82),
        'relation_hop_decay' => (float) env('AI_ENGINE_GRAPH_RELATION_HOP_DECAY', 0.9),
        'planner_enabled' => env('AI_ENGINE_GRAPH_PLANNER_ENABLED', true),
        'planner_candidate_multiplier' => (int) env('AI_ENGINE_GRAPH_PLANNER_CANDIDATE_MULTIPLIER', 2),
        'planner_seed_limit' => (int) env('AI_ENGINE_GRAPH_PLANNER_SEED_LIMIT', 6),
        'planner_seed_score_floor' => (float) env('AI_ENGINE_GRAPH_PLANNER_SEED_SCORE_FLOOR', 0.65),
        'planner_lexical_weight' => (float) env('AI_ENGINE_GRAPH_PLANNER_LEXICAL_WEIGHT', 0.4),
        'planner_relationship_lexical_weight' => (float) env('AI_ENGINE_GRAPH_PLANNER_RELATIONSHIP_LEXICAL_WEIGHT', 0.5),
        'planner_selected_seed_boost' => (float) env('AI_ENGINE_GRAPH_PLANNER_SELECTED_SEED_BOOST', 0.05),
        'planner_relationship_bonus' => (float) env('AI_ENGINE_GRAPH_PLANNER_RELATIONSHIP_BONUS', 0.05),
        'planner_score_breakdown' => env('AI_ENGINE_GRAPH_PLANNER_SCORE_BREAKDOWN', true),
        'natural_language_planning' => [
            'enabled' => env('AI_ENGINE_GRAPH_NL_PLANNING_ENABLED', true),
        ],
        'ranking_feedback' => [
            'enabled' => env('AI_ENGINE_GRAPH_RANKING_FEEDBACK_ENABLED', true),
            'cache_key' => env('AI_ENGINE_GRAPH_RANKING_FEEDBACK_CACHE_KEY', 'ai_engine:graph_ranking_feedback'),
            'ttl' => (int) env('AI_ENGINE_GRAPH_RANKING_FEEDBACK_TTL', 604800),
            'min_samples' => (int) env('AI_ENGINE_GRAPH_RANKING_FEEDBACK_MIN_SAMPLES', 5),
        ],
        'extract_relations_from_vector_relationships' => env('AI_ENGINE_GRAPH_EXTRACT_RELATIONS', true),
        'max_related_entities_per_relation' => (int) env('AI_ENGINE_GRAPH_MAX_RELATED_PER_RELATION', 25),
        'timeout' => (int) env('AI_ENGINE_GRAPH_TIMEOUT', 10),
        'ontology' => [
            'enabled_packs' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('AI_ENGINE_GRAPH_ONTOLOGY_PACKS', ''))
            ))),
            'relation_aliases' => [
                'OWNED_BY' => ['owner', 'owned_by', 'ownedby'],
                'CREATED_BY' => ['creator', 'created_by', 'createdby', 'author'],
                'ASSIGNED_TO' => ['assignee', 'assigned_to', 'assignedto'],
                'MANAGED_BY' => ['manager', 'managed_by', 'managedby', 'lead', 'supervisor'],
                'REPORTED_BY' => ['reporter', 'reported_by', 'reportedby'],
                'SENT_BY' => ['sender', 'sent_by', 'sentby'],
                'SENT_TO' => ['recipient', 'recipients', 'receiver', 'to'],
                'FOR_CUSTOMER' => ['customer', 'client'],
                'FOR_VENDOR' => ['vendor', 'supplier'],
                'IN_WORKSPACE' => ['workspace'],
                'IN_PROJECT' => ['project'],
                'IN_ORGANIZATION' => ['organization', 'organisation'],
                'IN_TEAM' => ['team'],
                'IN_ACCOUNT' => ['account'],
                'IN_FOLDER' => ['folder'],
                'IN_CHANNEL' => ['channel'],
                'IN_THREAD' => ['thread', 'conversation'],
                'IN_MILESTONE' => ['milestone'],
                'IN_SPRINT' => ['sprint'],
                'DEPENDS_ON' => ['dependency', 'dependencies'],
                'BLOCKED_BY' => ['blocker', 'blockers'],
                'REPLIED_TO' => ['reply', 'replies', 'reply_to', 'replyto'],
                'MENTIONS' => ['mention', 'mentions'],
                'HAS_MEMBER' => ['member', 'members'],
                'HAS_PARTICIPANT' => ['participant', 'participants', 'collaborator', 'collaborators'],
                'WATCHED_BY' => ['watcher', 'watchers', 'subscriber', 'subscribers'],
                'HAS_ATTACHMENT' => ['attachment', 'attachments', 'file', 'files'],
                'HAS_INVOICE' => ['invoice', 'invoices'],
                'HAS_ORDER' => ['order', 'orders'],
                'HAS_TICKET' => ['ticket', 'tickets'],
                'HAS_ISSUE' => ['issue', 'issues', 'bug', 'bugs'],
                'HAS_NOTE' => ['note', 'notes'],
                'HAS_DOCUMENT' => ['document', 'documents', 'doc', 'docs'],
                'HAS_CONTACT' => ['contact', 'contacts'],
                'HAS_COMPANY' => ['company', 'companies'],
            ],
            'model_aliases' => [
                'user' => ['owner', 'assignee', 'manager', 'reporter'],
                'mail' => ['email', 'message', 'thread'],
                'task' => ['todo', 'checklist'],
                'project' => ['initiative'],
                'workspace' => ['space'],
                'ticket' => ['case'],
                'issue' => ['bug'],
                'document' => ['doc'],
                'contact' => ['person'],
                'company' => ['organization', 'organisation'],
            ],
            'model_relation_types' => [
                'user' => ['OWNED_BY', 'CREATED_BY', 'ASSIGNED_TO', 'MANAGED_BY', 'REPORTED_BY', 'HAS_USER'],
                'mail' => ['HAS_MAIL', 'SENT_BY', 'SENT_TO', 'REPLIED_TO', 'MENTIONS', 'HAS_ATTACHMENT', 'IN_THREAD', 'IN_CHANNEL'],
                'task' => ['HAS_TASK', 'DEPENDS_ON', 'BLOCKED_BY', 'ASSIGNED_TO', 'OWNED_BY', 'IN_SPRINT', 'IN_MILESTONE'],
                'project' => ['HAS_PROJECT', 'IN_PROJECT', 'BELONGS_TO', 'DEPENDS_ON', 'HAS_MEMBER', 'HAS_PARTICIPANT'],
                'workspace' => ['HAS_WORKSPACE', 'IN_WORKSPACE', 'BELONGS_TO'],
                'ticket' => ['HAS_TICKET', 'ASSIGNED_TO', 'REPORTED_BY'],
                'issue' => ['HAS_ISSUE', 'BLOCKED_BY', 'DEPENDS_ON'],
                'document' => ['HAS_DOCUMENT', 'HAS_ATTACHMENT'],
                'contact' => ['HAS_CONTACT', 'FOR_CUSTOMER', 'FOR_VENDOR'],
                'company' => ['HAS_COMPANY', 'FOR_CUSTOMER', 'FOR_VENDOR'],
            ],
            'packs' => [
                'project_management' => [
                    'model_aliases' => [
                        'epic' => ['initiative', 'roadmap_item'],
                        'milestone' => ['phase'],
                        'sprint' => ['iteration'],
                    ],
                    'model_relation_types' => [
                        'epic' => ['HAS_PROJECT', 'HAS_TASK', 'IN_MILESTONE'],
                        'milestone' => ['IN_PROJECT', 'HAS_TASK', 'HAS_ISSUE'],
                        'sprint' => ['HAS_TASK', 'IN_PROJECT'],
                    ],
                    'relation_aliases' => [
                        'IN_EPIC' => ['epic'],
                        'HAS_EPIC' => ['epics'],
                        'HAS_MILESTONE' => ['milestones'],
                        'HAS_SPRINT' => ['sprints'],
                    ],
                ],
                'messaging' => [
                    'model_aliases' => [
                        'message' => ['post', 'chat_message'],
                        'thread' => ['conversation'],
                        'channel' => ['stream', 'room'],
                    ],
                    'model_relation_types' => [
                        'message' => ['SENT_BY', 'SENT_TO', 'IN_THREAD', 'IN_CHANNEL', 'MENTIONS', 'HAS_ATTACHMENT'],
                        'thread' => ['HAS_MESSAGE', 'HAS_PARTICIPANT'],
                        'channel' => ['HAS_MESSAGE', 'HAS_PARTICIPANT'],
                    ],
                    'relation_aliases' => [
                        'HAS_MESSAGE' => ['messages', 'posts'],
                        'HAS_COMMENT' => ['comments', 'replies'],
                    ],
                ],
                'support' => [
                    'model_aliases' => [
                        'ticket' => ['case', 'support_case'],
                        'issue' => ['incident', 'problem'],
                    ],
                    'model_relation_types' => [
                        'ticket' => ['HAS_TICKET', 'ASSIGNED_TO', 'REPORTED_BY', 'FOR_CUSTOMER', 'HAS_NOTE'],
                        'issue' => ['HAS_ISSUE', 'BLOCKED_BY', 'DEPENDS_ON', 'HAS_NOTE'],
                    ],
                    'relation_aliases' => [
                        'HAS_ESCALATION' => ['escalation', 'escalations'],
                    ],
                ],
                'crm' => [
                    'model_aliases' => [
                        'lead' => ['prospect'],
                        'contact' => ['person', 'customer_contact'],
                        'company' => ['account_company'],
                    ],
                    'model_relation_types' => [
                        'lead' => ['FOR_CUSTOMER', 'HAS_CONTACT', 'HAS_COMPANY', 'OWNED_BY'],
                        'contact' => ['HAS_CONTACT', 'HAS_COMPANY', 'FOR_CUSTOMER'],
                        'company' => ['HAS_COMPANY', 'HAS_CONTACT'],
                    ],
                    'relation_aliases' => [
                        'HAS_LEAD' => ['lead', 'leads'],
                        'FOR_PROSPECT' => ['prospect'],
                    ],
                ],
                'commerce' => [
                    'model_aliases' => [
                        'order' => ['purchase'],
                        'invoice' => ['bill'],
                    ],
                    'model_relation_types' => [
                        'order' => ['HAS_ORDER', 'FOR_CUSTOMER', 'FOR_VENDOR', 'HAS_COMPANY'],
                        'invoice' => ['HAS_INVOICE', 'FOR_CUSTOMER', 'FOR_VENDOR', 'HAS_ORDER'],
                    ],
                    'relation_aliases' => [
                        'HAS_PAYMENT' => ['payment', 'payments'],
                        'HAS_SHIPMENT' => ['shipment', 'shipments'],
                    ],
                ],
            ],
        ],
        'benchmark' => [
            'default_iterations' => (int) env('AI_ENGINE_GRAPH_BENCHMARK_ITERATIONS', 5),
            'default_max_results' => (int) env('AI_ENGINE_GRAPH_BENCHMARK_MAX_RESULTS', 5),
            'default_index_limit' => (int) env('AI_ENGINE_GRAPH_BENCHMARK_INDEX_LIMIT', 10),
            'default_load_concurrency' => (int) env('AI_ENGINE_GRAPH_BENCHMARK_LOAD_CONCURRENCY', 4),
            'history_limit' => (int) env('AI_ENGINE_GRAPH_BENCHMARK_HISTORY_LIMIT', 100),
            'history_ttl' => (int) env('AI_ENGINE_GRAPH_BENCHMARK_HISTORY_TTL', 604800),
        ],
        'knowledge_base' => [
            'enabled' => env('AI_ENGINE_GRAPH_KB_ENABLED', true),
            'cache_results' => env('AI_ENGINE_GRAPH_KB_CACHE_RESULTS', true),
            'planner_signature' => env('AI_ENGINE_GRAPH_KB_PLANNER_SIGNATURE', 'v3'),
            'plan_cache_ttl' => (int) env('AI_ENGINE_GRAPH_KB_PLAN_CACHE_TTL', 1800),
            'result_cache_ttl' => (int) env('AI_ENGINE_GRAPH_KB_RESULT_CACHE_TTL', 900),
            'profile_ttl' => (int) env('AI_ENGINE_GRAPH_KB_PROFILE_TTL', 86400),
            'profile_index_limit' => (int) env('AI_ENGINE_GRAPH_KB_PROFILE_INDEX_LIMIT', 200),
            'warm_default_limit' => (int) env('AI_ENGINE_GRAPH_KB_WARM_DEFAULT_LIMIT', 25),
        ],
        'neo4j' => [
            'url' => env('AI_ENGINE_NEO4J_URL', 'http://localhost:7474'),
            'database' => env('AI_ENGINE_NEO4J_DATABASE', 'neo4j'),
            'username' => env('AI_ENGINE_NEO4J_USERNAME', 'neo4j'),
            'password' => env('AI_ENGINE_NEO4J_PASSWORD'),
            'chunk_vector_index' => env('AI_ENGINE_NEO4J_CHUNK_VECTOR_INDEX', 'chunk_embedding_index'),
            'chunk_vector_property' => env('AI_ENGINE_NEO4J_CHUNK_VECTOR_PROPERTY', 'embedding'),
            'shared_deployment' => env('AI_ENGINE_NEO4J_SHARED_DEPLOYMENT', false),
            'vector_naming' => [
                'strategy' => env('AI_ENGINE_NEO4J_VECTOR_NAMING_STRATEGY', ''),
                'node_slug' => env('AI_ENGINE_NEO4J_VECTOR_NODE_SLUG'),
                'tenant_key' => env('AI_ENGINE_NEO4J_VECTOR_TENANT_KEY'),
            ],
            'vector_similarity' => env('AI_ENGINE_NEO4J_VECTOR_SIMILARITY', 'cosine'),
            'ensure_schema_on_sync' => env('AI_ENGINE_NEO4J_ENSURE_SCHEMA_ON_SYNC', true),
            'vector_candidate_multiplier' => (int) env('AI_ENGINE_NEO4J_VECTOR_CANDIDATE_MULTIPLIER', 3),
            'use_query_api' => env('AI_ENGINE_NEO4J_USE_QUERY_API', true),
        ],
    ],

    'nodes' => [
        // Enable node management
        'enabled' => env('AI_ENGINE_NODES_ENABLED', true),

        // Is this the master node?
        'is_master' => env('AI_ENGINE_IS_MASTER', true),

        // Master node URL (for child nodes)
        'master_url' => env('AI_ENGINE_MASTER_URL'),

        // Local node identity used in health/manifest payloads and admin UI.
        // Use env() in config defaults; runtime code should read these values
        // through config('ai-engine.nodes.local.*'). If node-specific env vars
        // are omitted, the name falls back to APP_NAME, the URL falls back to
        // APP_URL where it is consumed, the slug is derived later, and the label
        // defaults from AI_ENGINE_IS_MASTER. AI_ENGINE_NODE_ROLE is kept as a
        // backward-compatible alias for older app configs.
        'local' => [
            'name' => env('AI_ENGINE_NODE_NAME', env('APP_NAME', 'Laravel')),
            'slug' => env('AI_ENGINE_NODE_SLUG'),
            'label' => env('AI_ENGINE_NODE_LABEL', env(
                'AI_ENGINE_NODE_ROLE',
                env('AI_ENGINE_IS_MASTER', true) ? 'master' : 'client'
            )),
            'aliases' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('AI_ENGINE_NODE_ALIASES', ''))
            ))),
        ],

        // JWT Configuration
        'jwt' => [
            // JWT secret for node authentication
            'secret' => env('AI_ENGINE_JWT_SECRET', env('APP_KEY')),

            // JWT library to use: 'firebase' or 'tymon' (auto-detect if null)
            'library' => env('AI_ENGINE_JWT_LIBRARY', null),

            // JWT algorithm (HS256, HS384, HS512, RS256, etc.)
            'algorithm' => env('AI_ENGINE_JWT_ALGORITHM', 'HS256'),

            // Token TTL in seconds
            'ttl' => env('AI_ENGINE_JWT_TTL', 3600), // 1 hour

            // Refresh token TTL in seconds
            'refresh_ttl' => env('AI_ENGINE_JWT_REFRESH_TTL', 86400), // 24 hours

            // Issuer (iss claim)
            'issuer' => env('AI_ENGINE_JWT_ISSUER', env('APP_URL')),

            // Audience (aud claim) - optional
            'audience' => env('AI_ENGINE_JWT_AUDIENCE', null),
        ],

        // Shared secret for inter-node communication (use same value on all nodes)
        // Defaults to JWT secret if not specified
        'shared_secret' => env('AI_ENGINE_SHARED_SECRET', env('AI_ENGINE_JWT_SECRET', env('APP_KEY'))),

        // Node capabilities
        'capabilities' => ['search', 'actions', 'rag'],

        // Bulk sync controls
        'bulk_sync' => [
            // Default auto-fix mode for admin/CLI when no explicit mode is provided.
            // Allowed values: smart, strict
            'autofix_mode' => env('AI_ENGINE_NODE_BULK_SYNC_AUTOFIX_MODE', 'smart'),
        ],

        // Auto-register with master on boot
        'auto_register' => env('AI_ENGINE_AUTO_REGISTER', false),

        // Health check interval (seconds)
        'health_check_interval' => env('AI_ENGINE_HEALTH_CHECK_INTERVAL', 300),

        // Request timeout (seconds)
        'request_timeout' => env('AI_ENGINE_REQUEST_TIMEOUT', 30),

        // SSL certificate verification (disable for self-signed certs in development)
        'verify_ssl' => env('AI_ENGINE_VERIFY_SSL', true),

        // Cache TTL (seconds)
        'cache_ttl' => env('AI_ENGINE_CACHE_TTL', 900),

        // Cache configuration
        'cache' => [
            // Enable caching for federated search
            'enabled' => env('AI_ENGINE_CACHE_ENABLED', true),

            // Cache driver: null (use default), 'file', 'redis', 'memcached', 'database', 'array'
            'driver' => env('AI_ENGINE_CACHE_DRIVER'),

            // Cache store name (if using a specific store from config/cache.php)
            'store' => env('AI_ENGINE_CACHE_STORE'),

            // Cache prefix for all keys
            'prefix' => env('AI_ENGINE_CACHE_PREFIX', 'ai_engine'),

            // Use database as fallback/persistent cache (requires ai_node_search_cache table)
            'use_database' => env('AI_ENGINE_CACHE_USE_DATABASE', false),

            // Enable cache tags (only works with Redis/Memcached)
            'use_tags' => env('AI_ENGINE_CACHE_USE_TAGS', false),
        ],

        // Max parallel requests
        'max_parallel_requests' => env('AI_ENGINE_MAX_PARALLEL_REQUESTS', 10),

        // Node search mode: 'routing' (simple, route to single node) or 'federated' (search all nodes)
        // Routing is simpler and faster, federated is more comprehensive but complex
        'search_mode' => env('AI_ENGINE_NODE_SEARCH_MODE', 'routing'),

        // Routing failover behavior
        'routing' => [
            // When true, if remote node chat fails, run a local-only RAG fallback.
            // This keeps the assistant responsive during node outages, but response
            // is limited to local data and may not contain remote-domain records.
            'local_fallback_on_failure' => env('AI_ENGINE_ROUTE_LOCAL_FALLBACK_ON_FAILURE', false),

            // User-facing prefix for degraded fallback responses.
            'local_fallback_notice' => env(
                'AI_ENGINE_ROUTE_LOCAL_FALLBACK_NOTICE',
                'Remote node is unavailable. Showing local results only (degraded mode).'
            ),
        ],

        // Search result merging (only used in federated mode)
        'merge' => [
            'strategy' => env('AI_ENGINE_MERGE_STRATEGY', 'score'), // score, round_robin, node_priority, diversity, hybrid
            'deduplication' => env('AI_ENGINE_MERGE_DEDUPLICATION', true),
        ],

        // Circuit breaker settings
        'circuit_breaker' => [
            'failure_threshold' => env('AI_ENGINE_CB_FAILURE_THRESHOLD', 5),
            'success_threshold' => env('AI_ENGINE_CB_SUCCESS_THRESHOLD', 2),
            'timeout' => env('AI_ENGINE_CB_TIMEOUT', 60),
            'retry_timeout' => env('AI_ENGINE_CB_RETRY_TIMEOUT', 30),
        ],

        // Connection pooling
        'connection_pool' => [
            'enabled' => env('AI_ENGINE_CONNECTION_POOL_ENABLED', true),
            'max_per_node' => env('AI_ENGINE_CONNECTION_POOL_MAX_PER_NODE', 5),
            'ttl' => env('AI_ENGINE_CONNECTION_POOL_TTL', 300), // 5 minutes
        ],

        // Rate limiting
        'rate_limit' => [
            'enabled' => env('AI_ENGINE_RATE_LIMIT_ENABLED', true),
            'max_attempts' => env('AI_ENGINE_RATE_LIMIT_MAX', 60),
            'decay_minutes' => env('AI_ENGINE_RATE_LIMIT_DECAY', 1),
            'per_node' => env('AI_ENGINE_RATE_LIMIT_PER_NODE', true), // Per-node rate limiting
        ],

        // Logging & Debugging
        'logging' => [
            'enabled' => env('AI_ENGINE_NODE_LOGGING', true),
            'channel' => env('AI_ENGINE_NODE_LOG_CHANNEL', 'ai-engine'),
            'log_requests' => env('AI_ENGINE_LOG_REQUESTS', true),
            'log_responses' => env('AI_ENGINE_LOG_RESPONSES', true),
            'log_errors' => env('AI_ENGINE_LOG_ERRORS', true),
            'log_circuit_breaker' => env('AI_ENGINE_LOG_CIRCUIT_BREAKER', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation History Optimization
    |--------------------------------------------------------------------------
    |
    | Simple sliding window to reduce prompt size.
    | When conversation exceeds threshold, only recent messages are sent.
    |
    */
    'conversation_history' => [
        // Enable history optimization (sliding window)
        'enabled' => env('AI_CONVERSATION_HISTORY_OPTIMIZATION', true),

        // Number of recent messages to keep when history is long
        'recent_messages' => env('AI_CONVERSATION_RECENT_MESSAGES', 10),

        // Maximum message length to store (truncate longer messages)
        'max_message_length' => env('AI_CONVERSATION_MAX_MESSAGE_LENGTH', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Model Selection
    |--------------------------------------------------------------------------
    |
    | Enable automatic model selection based on task requirements.
    | When enabled, the system will automatically choose the best model
    | for the task with offline fallback to Ollama.
    |
    */
    'auto_select_model' => env('AI_AUTO_SELECT_MODEL', false),

    /*
    |--------------------------------------------------------------------------
    | Chat Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | Configure authentication middleware for chat endpoints.
    | Allows custom guards and middleware configuration.
    |
    */
    'chat' => [
        // Enable authentication for chat endpoints
        'auth_enabled' => env('AI_CHAT_AUTH_ENABLED', true),

        // Custom authentication guard (e.g., 'sanctum', 'jwt', 'manager', 'api')
        // Leave null to auto-detect from available guards
        // Can be a single guard or comma-separated list for multiple guards
        // Example: 'manager,user' will try manager first, then user
        'auth_guard' => env('AI_CHAT_AUTH_GUARD', null),

        // Custom middleware to apply (e.g., 'auth:manager', 'auth:sanctum')
        // Leave null to auto-detect based on auth_guard
        // Can be a single middleware or comma-separated list
        // Example: 'auth:manager,auth:user' will try both guards
        'auth_middleware' => env('AI_CHAT_AUTH_MIDDLEWARE', null),

        // Routes to exclude from authentication
        'auth_except' => ['index', 'rag', 'getEngines'],

        // Authorization settings
        'authorization' => [
            'enabled' => env('AI_CHAT_AUTHORIZATION_ENABLED', false),
        ],
    ],
];
    }

    /**
     * Parse middleware list env values.
     *
     * Supports:
     * - JSON arrays: ["auth:sanctum","throttle:30,1"]
     * - Semicolon lists: auth:sanctum;throttle:30,1
     * - Single values (commas are preserved for middleware parameters)
     *
     * @return array<int, string>
     */
    private static function csvEnv(string $key, string $default = ''): array
    {
        $raw = trim((string) env($key, $default));
        if ($raw === '') {
            return [];
        }

        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map(
                    static fn ($item): string => trim((string) $item),
                    $decoded
                ), static fn (string $item): bool => $item !== ''));
            }
        }

        $parts = str_contains($raw, ';')
            ? explode(';', $raw)
            : [$raw];

        return array_values(array_filter(array_map(
            static fn ($item): string => trim((string) $item),
            $parts
        ), static fn (string $item): bool => $item !== ''));
    }
}
