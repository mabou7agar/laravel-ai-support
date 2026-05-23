<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Agent Runtime
    |--------------------------------------------------------------------------
    |
    | Enable the unified AI agent runtime.
    |
    */
    'enabled' => env('AI_AGENT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Strategy
    |--------------------------------------------------------------------------
    |
    | Default execution strategy when complexity analysis is uncertain.
    | Options: quick_action, guided_flow, conversational
    |
    */
    'default_strategy' => env('AI_AGENT_DEFAULT_STRATEGY', 'conversational'),

    /*
    |--------------------------------------------------------------------------
    | Complexity Thresholds
    |--------------------------------------------------------------------------
    |
    | Thresholds for automatic strategy selection based on complexity analysis.
    |
    */
    'complexity_thresholds' => [
        'simple' => [
            'data_completeness' => 0.8,  // 80% of data provided
            'max_missing_fields' => 1,
        ],
        'medium' => [
            'data_completeness' => 0.4,  // 40-80% of data provided
            'max_missing_fields' => 5,
        ],
        'high' => [
            'data_completeness' => 0.0,  // Any completeness
            'max_missing_fields' => 999,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategy Overrides
    |--------------------------------------------------------------------------
    |
    | Force specific strategies for certain models or actions.
    |
    */
    'strategy_overrides' => [
        // Always use guided flow for these models
        'guided_flow' => [
            // 'App\\Models\\Course',
            // 'App\\Models\\ComplexForm',
        ],
        
        // Always use quick action for these models
        'quick_action' => [
            // 'App\\Models\\Post',
            // 'App\\Models\\Comment',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Cache complexity analysis results to improve performance.
    |
    */
    'cache' => [
        'enabled' => env('AI_AGENT_CACHE_ENABLED', true),
        'ttl' => env('AI_AGENT_CACHE_TTL', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Chat API
    |--------------------------------------------------------------------------
    |
    | Async chat turns normal chat requests into durable agent runs backed by
    | Laravel queues. Keep async_default disabled unless the host app wants all
    | agent chat requests to run through the durable queue path by default.
    |
    */
    'chat' => [
        'async_enabled' => env('AI_AGENT_CHAT_ASYNC_ENABLED', true),
        'async_default' => env('AI_AGENT_CHAT_ASYNC_DEFAULT', false),
        'auto_async' => [
            'force_rag' => env('AI_AGENT_CHAT_AUTO_ASYNC_FORCE_RAG', false),
            'rag_collections' => env('AI_AGENT_CHAT_AUTO_ASYNC_RAG_COLLECTIONS', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Run Event Stream
    |--------------------------------------------------------------------------
    |
    | Native Laravel event stream for persisted agent-run lifecycle events.
    | These events are also kept in run/step metadata as a non-streaming
    | fallback for admin screens and APIs.
    |
    */
    'event_stream' => [
        'enabled' => env('AI_AGENT_EVENT_STREAM_ENABLED', true),
        'persisted_events_limit' => (int) env('AI_AGENT_EVENT_STREAM_PERSISTED_LIMIT', 200),
        'sse' => [
            'enabled' => env('AI_AGENT_EVENT_STREAM_SSE_ENABLED', true),
            'max_seconds' => (int) env('AI_AGENT_EVENT_STREAM_SSE_MAX_SECONDS', 30),
            'poll_milliseconds' => (int) env('AI_AGENT_EVENT_STREAM_SSE_POLL_MS', 500),
            'heartbeat_seconds' => (int) env('AI_AGENT_EVENT_STREAM_SSE_HEARTBEAT_SECONDS', 10),
            'authorize_owned_runs' => env('AI_AGENT_EVENT_STREAM_SSE_AUTHORIZE_OWNED_RUNS', true),
            'allow_anonymous_runs' => env('AI_AGENT_EVENT_STREAM_SSE_ALLOW_ANONYMOUS_RUNS', false),
            'authorizer' => null,
        ],
        'broadcast' => [
            'enabled' => env('AI_AGENT_EVENT_STREAM_BROADCAST_ENABLED', false),
            'connection' => env('AI_AGENT_EVENT_STREAM_BROADCAST_CONNECTION'),
            'queue' => env('AI_AGENT_EVENT_STREAM_BROADCAST_QUEUE', 'ai-agent-events'),
            'private' => env('AI_AGENT_EVENT_STREAM_BROADCAST_PRIVATE', true),
            'channel_prefix' => env('AI_AGENT_EVENT_STREAM_BROADCAST_CHANNEL_PREFIX', 'agent-run'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Runtime Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the agent runtime with multi-step tool reasoning.
    |
    */
    'runtime' => [
        'enabled' => env('AI_AGENT_RUNTIME_ENABLED', true),
        'max_steps' => env('AI_AGENT_MAX_STEPS', 10),
        'max_retries' => env('AI_AGENT_MAX_RETRIES', 3),
        'tools_enabled' => env('AI_AGENT_TOOLS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Native Runtime
    |--------------------------------------------------------------------------
    |
    | The AI-native runtime is the package-wide default direction: the model
    | decides intent, tool use, follow-up questions, RAG/tool continuation, and
    | final responses. Laravel keeps the safety kernel: validation, approvals,
    | scope, audit metadata, persistence, credits, and provider/tool limits.
    |
    */
    'ai_native' => [
        'enabled' => env('AI_AGENT_AI_NATIVE_ENABLED', true),
        'skills' => env('AI_AGENT_AI_NATIVE_SKILLS', true),
        'max_steps' => (int) env('AI_AGENT_AI_NATIVE_MAX_STEPS', 8),
        'max_tokens' => (int) env('AI_AGENT_AI_NATIVE_MAX_TOKENS', 1200),
        'temperature' => (float) env('AI_AGENT_AI_NATIVE_TEMPERATURE', 0.1),
        'action_intent_terms' => [
            'prepare',
            'draft',
            'create',
            'add',
            'new',
            'make',
            'generate',
            'update',
            'edit',
            'change',
            'modify',
            'delete',
            'remove',
            'cancel',
            'approve',
            'reject',
            'submit',
            'send',
            'run',
            'execute',
            'trigger',
            'search',
            'find',
            'lookup',
            'show',
            'list',
            'get',
            'inspect',
            'اعمل',
            'انشئ',
            'أنشئ',
            'اصنع',
            'عدل',
            'احذف',
            'ارسل',
            'ابحث',
            'اعرض',
        ],
        'excluded_tools' => [
            'run_skill',
        ],
        'confirmation_summary' => [
            'enabled' => env('AI_AGENT_AI_NATIVE_CONFIRMATION_SUMMARY', true),
            'prompt' => 'Please review before I run {tool}.',
            'heading' => 'Summary:',
            'instruction' => 'Choose Confirm to continue, or Change to edit before execution.',
            'hide_empty_values' => true,
            'max_depth' => (int) env('AI_AGENT_AI_NATIVE_CONFIRMATION_SUMMARY_MAX_DEPTH', 3),
            'max_items' => (int) env('AI_AGENT_AI_NATIVE_CONFIRMATION_SUMMARY_MAX_ITEMS', 20),
            'max_value_length' => (int) env('AI_AGENT_AI_NATIVE_CONFIRMATION_SUMMARY_MAX_VALUE_LENGTH', 160),
            'hidden_fields' => [
                'id',
                '*_id',
            ],
            'redacted_fields' => [
                'password',
                'token',
                'secret',
                'api_key',
                'authorization',
                'credential',
            ],
        ],
        'auto_confirm_suggested_writes_after_final_confirmation' => env(
            'AI_AGENT_AI_NATIVE_AUTO_CONFIRM_SUGGESTED_WRITES',
            true
        ),
        'lookup_before_ask_terms' => [
            'id',
            'uuid',
            'name',
            'title',
            'label',
            'email',
            'number',
            'code',
            'slug',
            'owner',
            'assignee',
            'user',
            'tenant',
            'workspace',
            'organization',
            'team',
            'department',
            'role',
            'location',
            'status',
            'amount',
            'cost',
            'price',
            'rate',
            'unit_price',
            'sale_price',
            'total',
        ],
        'trigger_stopwords' => ['a', 'an', 'the', 'to', 'for', 'with', 'me', 'please'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Goal Agent / Sub-Agents
    |--------------------------------------------------------------------------
    |
    | A reusable target-oriented runtime. Host applications can register
    | sub-agents here, then call chat with agent_goal=true and an optional
    | target/sub_agents plan. Each sub-agent delegates work to a handler class
    | or callable, keeping domain logic in the host app.
    |
    */
    'goal_agent' => [
        'enabled' => env('AI_AGENT_GOAL_ENABLED', true),
        'max_sub_agents' => env('AI_AGENT_GOAL_MAX_SUB_AGENTS', 5),
        'stop_on_failure' => env('AI_AGENT_GOAL_STOP_ON_FAILURE', true),
        'register_sub_agent_tool' => env('AI_AGENT_REGISTER_SUB_AGENT_TOOL', true),
    ],

    'orchestration' => [
        'max_complexity' => (int) env('AI_AGENT_ORCHESTRATION_MAX_COMPLEXITY', 80),
    ],

    'run_safety' => [
        'lock_ttl_seconds' => (int) env('AI_AGENT_RUN_LOCK_TTL', 60),
        'lock_wait_seconds' => (int) env('AI_AGENT_RUN_LOCK_WAIT', 5),
        'duplicate_message_ttl_seconds' => (int) env('AI_AGENT_DUPLICATE_MESSAGE_TTL', 120),
        'stuck_after_minutes' => (int) env('AI_AGENT_RUN_STUCK_AFTER_MINUTES', 30),
        'expired_cleanup_days' => (int) env('AI_AGENT_RUN_EXPIRED_CLEANUP_DAYS', 30),
        'queue' => [
            'connection' => env('AI_AGENT_RUN_QUEUE_CONNECTION'),
            'name' => env('AI_AGENT_RUN_QUEUE', 'ai-agent'),
            'tries' => (int) env('AI_AGENT_RUN_QUEUE_TRIES', 3),
            'timeout' => (int) env('AI_AGENT_RUN_QUEUE_TIMEOUT', 300),
            'backoff' => [30, 60, 120],
            'max_steps' => (int) env('AI_AGENT_RUN_MAX_STEPS', 50),
            'max_tokens' => env('AI_AGENT_RUN_MAX_TOKENS') !== null ? (int) env('AI_AGENT_RUN_MAX_TOKENS') : null,
            'max_cost' => env('AI_AGENT_RUN_MAX_COST') !== null ? (float) env('AI_AGENT_RUN_MAX_COST') : null,
        ],
    ],

    'run_retention' => [
        'run_days' => (int) env('AI_AGENT_RUN_RETENTION_DAYS', 90),
        'step_days' => (int) env('AI_AGENT_RUN_STEP_RETENTION_DAYS', 90),
        'trace_days' => (int) env('AI_AGENT_RUN_TRACE_RETENTION_DAYS', 30),
        'artifact_days' => (int) env('AI_AGENT_RUN_ARTIFACT_RETENTION_DAYS', 90),
        'redact_prompts' => env('AI_AGENT_RUN_REDACT_PROMPTS', false),
        'redact_responses' => env('AI_AGENT_RUN_REDACT_RESPONSES', false),
        'store_raw_provider_payloads' => env('AI_AGENT_STORE_RAW_PROVIDER_PAYLOADS', env('AI_ENGINE_PROVIDER_TOOL_STORE_PAYLOADS', true)),
    ],

    'execution_policy' => [
        'runtime_allow' => [],
        'runtime_deny' => [],
        'tool_allow' => [],
        'tool_deny' => [],
        'sub_agent_allow' => [],
        'sub_agent_deny' => [],
        'rag_collection_allow' => [],
        'rag_collection_deny' => [],
        'node_allow' => [],
        'node_deny' => [],
        'sensitive_keys' => [
            'password',
            'token',
            'secret',
            'api_key',
            'authorization',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Runtime
    |--------------------------------------------------------------------------
    |
    | Runtime selection is explicit in v2. Laravel stays the default runtime.
    | LangGraph is optional and must be enabled/configured by the host app.
    |
    */
    'runtime' => [
        'default' => env('AI_AGENT_RUNTIME', 'laravel'),
        'langgraph' => [
            'enabled' => env('AI_AGENT_LANGGRAPH_ENABLED', false),
            'base_url' => env('AI_AGENT_LANGGRAPH_URL'),
            'api_token' => env('AI_AGENT_LANGGRAPH_API_TOKEN'),
            'signature_secret' => env('AI_AGENT_LANGGRAPH_SIGNATURE_SECRET'),
            'timeout' => (int) env('AI_AGENT_LANGGRAPH_TIMEOUT', 120),
            'retry_times' => (int) env('AI_AGENT_LANGGRAPH_RETRY_TIMES', 1),
            'retry_sleep_ms' => (int) env('AI_AGENT_LANGGRAPH_RETRY_SLEEP_MS', 100),
            'fallback_to_laravel' => env('AI_AGENT_LANGGRAPH_FALLBACK_TO_LARAVEL', true),
        ],
    ],

    'routing_pipeline' => [
        'stages' => [
            \LaravelAIEngine\Services\Agent\Routing\Stages\ActiveRunContinuationStage::class,
            \LaravelAIEngine\Services\Agent\Routing\Stages\ExplicitModeStage::class,
            \LaravelAIEngine\Services\Agent\Routing\Stages\SelectionReferenceStage::class,
            \LaravelAIEngine\Services\Agent\Routing\Stages\AgentSkillMatchStage::class,
            \LaravelAIEngine\Services\Agent\Routing\Stages\MessageClassificationStage::class,
            \LaravelAIEngine\Services\Agent\Routing\Stages\AIRouterStage::class,
            \LaravelAIEngine\Services\Agent\Routing\Stages\FallbackConversationalStage::class,
        ],
    ],

    'sub_agents' => [
        'general' => [
            'enabled' => env('AI_AGENT_GENERAL_SUB_AGENT_ENABLED', true),
            'name' => 'General sub-agent',
            'description' => 'Handles general reasoning, synthesis, and follow-up work for a target.',
            'capabilities' => ['general', 'summarize', 'synthesize', 'plan'],
            'handler' => \LaravelAIEngine\Services\Agent\SubAgents\ConversationalSubAgentHandler::class,
            // 'tools' => ['search_options'],
            // 'sub_agents' => ['writer'],
        ],
    ],

    'sub_agent_conversations' => [
        'default_rounds' => (int) env('AI_AGENT_SUB_AGENT_CONVERSATION_ROUNDS', 3),
        'max_rounds' => (int) env('AI_AGENT_SUB_AGENT_CONVERSATION_MAX_ROUNDS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tools
    |--------------------------------------------------------------------------
    |
     | Available tools for the agent runtime.
    | Host applications can enable the built-in tools below or provide their
    | own AgentTool implementations through the same registry.
     |
     */
    'tools' => [
        // 'validate_field' => \LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool::class,
        // 'search_options' => \LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool::class,
        // 'suggest_value' => \LaravelAIEngine\Services\Agent\Tools\SuggestValueTool::class,
        // 'explain_field' => \LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool::class,
    ],

    'action_reply' => [
        'ai_enabled' => env('AI_AGENT_ACTION_REPLY_AI_ENABLED', true),
        'enhancer' => null,
        'mcp' => [
            'enabled' => env('AI_AGENT_ACTION_REPLY_MCP_ENABLED', false),
            'url' => env('AI_AGENT_ACTION_REPLY_MCP_URL'),
            'tool_name' => env('AI_AGENT_ACTION_REPLY_MCP_TOOL_NAME', 'humanize_text'),
            'timeout' => (int) env('AI_AGENT_ACTION_REPLY_MCP_TIMEOUT', 8),
            'max_chars' => (int) env('AI_AGENT_ACTION_REPLY_MCP_MAX_CHARS', 4000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent Alias Cache
    |--------------------------------------------------------------------------
    |
    | High-confidence tool decisions may store short, safe user phrases as
    | routing hints. The cache does not store final answers or bypass domain
    | validation; it only speeds up future routing before RAG/AI is needed.
    |
    */
    'intent_alias_cache' => [
        'ttl_days' => env('AI_AGENT_INTENT_ALIAS_TTL_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Compaction
    |--------------------------------------------------------------------------
    |
    | Agent memory keeps recent turns verbatim and compacts older turns into a
    | bounded summary. This prevents prompt/context growth while preserving
    | long-running conversation intent.
    |
    */
    'context_compaction' => [
        'enabled' => env('AI_AGENT_CONTEXT_COMPACTION_ENABLED', true),
        'max_messages' => env('AI_AGENT_CONTEXT_MAX_MESSAGES', 12),
        'keep_recent_messages' => env('AI_AGENT_CONTEXT_KEEP_RECENT_MESSAGES', 6),
        'max_message_chars' => env('AI_AGENT_CONTEXT_MAX_MESSAGE_CHARS', 2000),
        'max_total_chars' => env('AI_AGENT_CONTEXT_MAX_TOTAL_CHARS', 12000),
        'max_summary_chars' => env('AI_AGENT_CONTEXT_MAX_SUMMARY_CHARS', 4000),
        'summary_message_chars' => env('AI_AGENT_CONTEXT_SUMMARY_MESSAGE_CHARS', 240),
    ],

    'conversation_memory' => [
        'enabled' => env('AI_AGENT_CONVERSATION_MEMORY_ENABLED', true),
        'driver' => env('AI_AGENT_CONVERSATION_MEMORY_DRIVER', 'database'),
        'extract_on_compaction' => env('AI_AGENT_MEMORY_EXTRACT_ON_COMPACTION', true),
        'extractor' => env('AI_AGENT_MEMORY_EXTRACTOR', 'ai'),
        'extractor_class' => env('AI_AGENT_MEMORY_EXTRACTOR_CLASS'),
        'engine' => env('AI_AGENT_MEMORY_ENGINE', env('AI_ENGINE_DEFAULT')),
        'model' => env('AI_AGENT_MEMORY_MODEL', env('AI_ENGINE_ORCHESTRATION_MODEL', env('AI_ENGINE_DEFAULT_MODEL'))),
        'max_extraction_input_chars' => (int) env('AI_AGENT_MEMORY_MAX_EXTRACTION_INPUT_CHARS', 6000),
        'max_memories_per_turn' => (int) env('AI_AGENT_MEMORY_MAX_PER_TURN', 6),
        'max_prompt_chars' => (int) env('AI_AGENT_MEMORY_MAX_PROMPT_CHARS', 1200),
        'min_score' => (float) env('AI_AGENT_MEMORY_MIN_SCORE', 0.45),
        'ttl_days' => (int) env('AI_AGENT_MEMORY_TTL_DAYS', 180),
        'scopes' => [
            'tenant_key' => env('AI_AGENT_MEMORY_TENANT_KEY', 'tenant_id'),
            'workspace_key' => env('AI_AGENT_MEMORY_WORKSPACE_KEY', 'workspace_id'),
        ],
        'semantic' => [
            'enabled' => env('AI_AGENT_MEMORY_SEMANTIC_ENABLED', false),
            'driver' => env('AI_AGENT_MEMORY_SEMANTIC_DRIVER', env('AI_ENGINE_VECTOR_DRIVER')),
            'collection' => env('AI_AGENT_MEMORY_SEMANTIC_COLLECTION', env('AI_ENGINE_MEMORY_COLLECTION')),
            'embedding_engine' => env('AI_AGENT_MEMORY_EMBEDDING_ENGINE', env('AI_ENGINE_DEFAULT')),
            'embedding_model' => env('AI_AGENT_MEMORY_EMBEDDING_MODEL', env('AI_ENGINE_VECTOR_EMBEDDING_MODEL')),
            'index_on_write' => env('AI_AGENT_MEMORY_SEMANTIC_INDEX_ON_WRITE', false),
            'payload_scope_fields' => array_values(array_filter(array_map('trim', explode(',', (string) env(
                'AI_AGENT_MEMORY_VECTOR_SCOPE_FIELDS',
                'user_id,tenant_id,workspace_id,session_id,namespace'
            ))))),
        ],
    ],

    'response_presentation' => [
        'points_format' => env('AI_AGENT_RESPONSE_POINTS_FORMAT', 'text'), // text, array, both
        'suggestions' => [
            'enabled' => env('AI_AGENT_RESPONSE_SUGGESTIONS_ENABLED', true),
            'limit' => (int) env('AI_AGENT_RESPONSE_SUGGESTIONS_LIMIT', 5),
            'excluded_tools' => [
                'run_skill',
                'run_sub_agent',
            ],
            'stop_words' => [
                'the',
                'a',
                'an',
                'and',
                'or',
                'to',
                'for',
                'from',
                'with',
                'this',
                'that',
                'current',
                'available',
                'deterministic',
                'data',
                'user',
                'message',
                'response',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Structured Collection Sessions
    |--------------------------------------------------------------------------
    |
    | Generic chat-driven data collection. Host apps provide a JSON schema
    | per request; the agent extracts fields, asks for missing values in the
    | user's language, confirms, closes the session, and emits a callback.
    |
    */
    'structured_collection' => [
        'ttl_seconds' => (int) env('AI_AGENT_STRUCTURED_COLLECTION_TTL', 3600),
        'max_tokens' => (int) env('AI_AGENT_STRUCTURED_COLLECTION_MAX_TOKENS', 900),
        'temperature' => (float) env('AI_AGENT_STRUCTURED_COLLECTION_TEMPERATURE', 0.1),
        'callback_timeout' => (int) env('AI_AGENT_STRUCTURED_COLLECTION_CALLBACK_TIMEOUT', 10),
        'preview' => [
            'enabled' => (bool) env('AI_AGENT_STRUCTURED_COLLECTION_PREVIEW_ENABLED', false),
            'mode' => env('AI_AGENT_STRUCTURED_COLLECTION_PREVIEW_MODE', 'component'),
            'assets' => [
                'css' => ['/vendor/ai-engine/structured-collection.css'],
                'js' => ['/vendor/ai-engine/structured-collection.js'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Capability Providers
    |--------------------------------------------------------------------------
    |
    | Host applications can expose agent capabilities as compact documents for
    | vector sync, persistent memory, and semantic tool/module routing.
    |
    */
    'capability_providers' => [
        // \App\AI\Capabilities\AppCapabilityProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Skills
    |--------------------------------------------------------------------------
    |
    | Skills are user-facing abilities that group triggers, required data,
    | tools, actions, and confirmation policy. They do not replace tools.
    | They describe and route complete business capabilities while the actual
    | work remains in services, repositories, actions, and tool handlers.
    |
    */
    'skills' => [
        'enabled' => env('AI_AGENT_SKILLS_ENABLED', true),
        'expose_as_capabilities' => env('AI_AGENT_SKILLS_EXPOSE_AS_CAPABILITIES', true),
        'prefer_deterministic_matches' => env('AI_AGENT_SKILLS_PREFER_DETERMINISTIC_MATCHES', true),
        'intent_matching' => [
            'enabled' => env('AI_AGENT_SKILL_INTENT_MATCHING_ENABLED', true),
            'engine' => env('AI_AGENT_SKILL_INTENT_MATCHING_ENGINE', env('AI_ENGINE_DEFAULT')),
            'model' => env('AI_AGENT_SKILL_INTENT_MATCHING_MODEL', env('AI_ENGINE_ORCHESTRATION_MODEL', env('AI_ENGINE_DEFAULT_MODEL', 'gpt-4o-mini'))),
            'max_tokens' => env('AI_AGENT_SKILL_INTENT_MATCHING_MAX_TOKENS', 450),
            'temperature' => env('AI_AGENT_SKILL_INTENT_MATCHING_TEMPERATURE', 0.05),
            'min_confidence' => env('AI_AGENT_SKILL_INTENT_MATCHING_MIN_CONFIDENCE', 72),
        ],
        'intent_aliases' => [],
        'continuation_terms' => [
            'do it',
            'create it',
            'make it',
            'submit it',
            'finalize',
            'proceed',
            'go ahead',
            'turn .* into',
            'convert .* into',
            'use .* conversation',
            'from .* conversation',
            'حول',
            'اعمل',
            'انشئ',
            'أنشئ',
        ],
    ],

    'routing_classifier' => [
        'action_entities' => env('AI_AGENT_ROUTING_ACTION_ENTITIES')
            ? array_values(array_filter(array_map('trim', explode(',', env('AI_AGENT_ROUTING_ACTION_ENTITIES')))))
            : [],
        'structured_status_terms' => ['open', 'closed', 'active', 'inactive', 'pending', 'completed'],
        'structured_collection_terms' => ['items', 'records', 'tasks', 'projects', 'mails', 'emails'],
        'structured_field_terms' => ['status', 'created', 'updated', 'assigned', 'owner', 'workspace', 'project', 'user', 'type'],
        'selection_reference_terms' => ['one', 'record', 'item', 'entry', 'message', 'result'],
        'pending_entity_terms' => ['record', 'item', 'entry'],
    ],

    'intent_understanding' => [
        'mode' => env('AI_AGENT_INTENT_UNDERSTANDING_MODE', 'heuristic'),
        'engine' => env('AI_AGENT_INTENT_ENGINE', env('AI_ENGINE_DEFAULT')),
        'model' => env('AI_AGENT_INTENT_MODEL', env('AI_ENGINE_ORCHESTRATION_MODEL', env('AI_ENGINE_DEFAULT_MODEL', 'gpt-4o-mini'))),
        'max_tokens' => (int) env('AI_AGENT_INTENT_MAX_TOKENS', 500),
        'temperature' => (float) env('AI_AGENT_INTENT_TEMPERATURE', 0.0),
        'cache_ttl_seconds' => (int) env('AI_AGENT_INTENT_CACHE_TTL', 120),
        'fallback_to_heuristics' => env('AI_AGENT_INTENT_FALLBACK_TO_HEURISTICS', true),
        'min_confidence' => (float) env('AI_AGENT_INTENT_MIN_CONFIDENCE', 0.6),
    ],

    'skill_providers' => [
        // \App\AI\Skills\AppSkillProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    |
    | Deterministic write actions the agent may prepare, suggest, and execute.
    | Each action should define id, module, operation, required fields,
    | confirmation behavior, and prepare/handler/suggest callables as needed.
    |
    */
    'actions' => [
        //
    ],

    'action_providers' => [
        \LaravelAIEngine\Services\Actions\GenericModuleActionDefinitionProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generic Module Actions
    |--------------------------------------------------------------------------
    |
    | Reusable metadata-driven CRUD action definitions. Host applications add
    | module resources here; the package generates create/update action
    | definitions and executes them through a confirmed, validated service.
    | Keep app-specific model classes, permissions, and relation mappings in
    | the host app config.
    |
    */
    'generic_module_actions' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Generic Module Action Ownership
    |--------------------------------------------------------------------------
    |
    | The generic writer can populate common ownership columns such as
    | creator_id, created_by, and workspace_id. Host apps can override owner
    | resolution with a callable if their tenant/organization/user model differs.
    |
    */
    'generic_module_actions_ownership' => [
        'owner_fields' => ['created_by', 'creator_id', 'owner_id', 'user_id'],
        'owner_id_resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Action Payload Extraction
    |--------------------------------------------------------------------------
    |
    | Lets host apps ask the package to turn natural conversation turns into
    | structured payload patches for registered actions. The host app
    | still owns validation, relation resolution, authorization, and writes.
    |
    */
    'action_payload_extraction' => [
        'enabled' => env('AI_AGENT_ACTION_PAYLOAD_EXTRACTION_ENABLED', true),
        'engine' => env('AI_AGENT_ACTION_PAYLOAD_EXTRACTION_ENGINE', env('AI_ENGINE_DEFAULT')),
        'model' => env('AI_AGENT_ACTION_PAYLOAD_EXTRACTION_MODEL', env('AI_ENGINE_ORCHESTRATION_MODEL', env('AI_ENGINE_DEFAULT_MODEL', 'gpt-4o'))),
        'max_tokens' => env('AI_AGENT_ACTION_PAYLOAD_EXTRACTION_MAX_TOKENS', 1400),
        'temperature' => env('AI_AGENT_ACTION_PAYLOAD_EXTRACTION_TEMPERATURE', 0.1),
        'numeric_date_order' => env('AI_AGENT_ACTION_PAYLOAD_EXTRACTION_DATE_ORDER', 'dmy'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Explicit Agent Manifest
    |--------------------------------------------------------------------------
    |
    | Deterministic registration for model configs, skills, tools, and
    | filters. This avoids runtime directory scanning in production and gives
    | one source of truth.
    |
    | Default file: app/AI/agent-manifest.php
    |
    */
    'manifest' => [
        'path' => env('AI_AGENT_MANIFEST_PATH', app_path('AI/agent-manifest.php')),
        'fallback_discovery' => env('AI_AGENT_MANIFEST_FALLBACK_DISCOVERY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Convention-Based Agent Discovery
    |--------------------------------------------------------------------------
    |
    | These paths are scanned when manifest fallback discovery is enabled.
    | This lets applications create app/AI/Skills/*Skill.php and
    | app/AI/Tools/*Tool.php classes without long config setup.
    |
    */
    'discovery' => [
        'skills' => [
            'paths' => [
                app_path('AI/Skills'),
            ],
        ],
        'tools' => [
            'paths' => [
                app_path('AI/Tools'),
            ],
        ],
        'action_providers' => [
            'paths' => [
                app_path('AI/Actions'),
                app_path('AI/Skills'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable detailed logging for agent operations.
    |
    */
    'debug' => env('AI_AGENT_DEBUG', false),
];
