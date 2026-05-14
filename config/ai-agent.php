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
        'persisted_events_limit' => env('AI_AGENT_EVENT_STREAM_PERSISTED_LIMIT', 200),
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

    'skill_tool_planner' => [
        'enabled' => env('AI_AGENT_SKILL_TOOL_PLANNER_ENABLED', true),
        'engine' => env('AI_AGENT_SKILL_TOOL_PLANNER_ENGINE', env('AI_ENGINE_DEFAULT')),
        'model' => env('AI_AGENT_SKILL_TOOL_PLANNER_MODEL', env('AI_ENGINE_ORCHESTRATION_MODEL', env('AI_ENGINE_DEFAULT_MODEL', 'gpt-4o-mini'))),
        'max_tokens' => env('AI_AGENT_SKILL_TOOL_PLANNER_MAX_TOKENS', 1200),
        'temperature' => env('AI_AGENT_SKILL_TOOL_PLANNER_TEMPERATURE', 0.1),
        'max_steps' => env('AI_AGENT_SKILL_TOOL_PLANNER_MAX_STEPS', 4),
        'extract_before_plan' => env('AI_AGENT_SKILL_TOOL_EXTRACT_BEFORE_PLAN', true),
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
            // 'tools' => ['search_options', 'generate_action_reply'],
            // 'sub_agents' => ['writer'],
        ],
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
        // 'action_catalog' => \LaravelAIEngine\Services\Agent\Tools\ActionCatalogTool::class,
        // 'action_flow_guide' => \LaravelAIEngine\Services\Agent\Tools\ActionFlowGuideTool::class,
        // 'update_action_draft' => \LaravelAIEngine\Services\Agent\Tools\UpdateActionDraftTool::class,
        // 'get_action_draft' => \LaravelAIEngine\Services\Agent\Tools\GetActionDraftTool::class,
        // 'clear_action_draft' => \LaravelAIEngine\Services\Agent\Tools\ClearActionDraftTool::class,
        // 'prepare_action' => \LaravelAIEngine\Services\Agent\Tools\PrepareActionTool::class,
        // 'execute_action' => \LaravelAIEngine\Services\Agent\Tools\ExecuteActionTool::class,
        // 'generate_action_reply' => \LaravelAIEngine\Services\Agent\Tools\GenerateActionReplyTool::class,
        // 'suggest_action' => \LaravelAIEngine\Services\Agent\Tools\SuggestActionTool::class,
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
        'intent_aliases' => [
            'invoice' => ['invoice', 'bill', 'billing', 'charge', 'فاتورة', 'فوتر', 'facture', 'factura', 'fatura'],
        ],
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
    | Deterministic registration for model configs, tools, collectors, and
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
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable detailed logging for agent operations.
    |
    */
    'debug' => env('AI_AGENT_DEBUG', false),
];
