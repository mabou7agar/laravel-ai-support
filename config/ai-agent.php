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
        // Owner-scopes the agent-run REST endpoints (show, trace, list, resume, cancel)
        // exactly like the SSE stream endpoint, closing IDOR on those routes. Default ON
        // (fail-closed): a run that has a user_id is only readable/controllable by that
        // user. Set authorize_owned_runs=false to disable (e.g. when the host app enforces
        // its own authorization), allow_anonymous_runs=true to expose runs with no user_id,
        // or provide a custom 'authorizer' (callable|class with authorize($run, $authUserId)).
        'access' => [
            'authorize_owned_runs' => env('AI_AGENT_AGENT_RUN_AUTHORIZE_OWNED_RUNS', true),
            'allow_anonymous_runs' => env('AI_AGENT_AGENT_RUN_ALLOW_ANONYMOUS_RUNS', false),
            'authorizer' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Final Response Streaming
    |--------------------------------------------------------------------------
    |
    | When enabled, an agent run that produces a CONVERSATIONAL final reply
    | streams that reply token-by-token into the run's event stream
    | (final_response.token_streamed ... final_response.stream_completed) so an
    | SSE/broadcast consumer can "type it out" live. Default OFF: the reply is
    | generated synchronously and emitted as a single completed message. Can be
    | overridden per-run via options['streaming'].
    |
    */
    'final_response_streaming' => [
        'enabled' => env('AI_AGENT_FINAL_RESPONSE_STREAMING_ENABLED', false),
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
        // DEPRECATED / no-op: AiNative is now the sole runtime (the classic routing
        // pipeline was removed — see docs/decisions/0001-classic-routing-path.md).
        // Setting this to false no longer switches to a classic path; it is kept only
        // for config back-compat and will be removed in a future major version.
        'enabled' => env('AI_AGENT_AI_NATIVE_ENABLED', true),
        'skills' => env('AI_AGENT_AI_NATIVE_SKILLS', true),
        'max_steps' => (int) env('AI_AGENT_AI_NATIVE_MAX_STEPS', 8),
        // Registers the search_knowledge tool so the runtime can reach the vector /
        // document RAG store. Also the tool a force_rag turn is told to call.
        'knowledge_tool_enabled' => env('AI_AGENT_AI_NATIVE_KNOWLEDGE_TOOL', true),
        'budget' => [
            'enabled' => env('AI_AGENT_AI_NATIVE_BUDGET_ENABLED', false),
            'max_steps' => (int) env('AI_AGENT_AI_NATIVE_BUDGET_MAX_STEPS', 16),
        ],
        'compaction' => [
            // In-loop context compaction: before each planner call, trim the
            // oldest recorded tool results when the accumulated history grows
            // past the threshold so a smaller state is sent to the planner.
            // Default OFF preserves today's behavior byte-for-byte.
            'enabled' => env('AI_AGENT_AI_NATIVE_COMPACTION_ENABLED', false),
            'threshold' => (int) env('AI_AGENT_AI_NATIVE_COMPACTION_THRESHOLD', 12),
            'keep_recent_results' => (int) env('AI_AGENT_AI_NATIVE_COMPACTION_KEEP_RECENT_RESULTS', 6),
            // When enabled, also compact $context->conversationHistory via the
            // ConversationContextCompactor (its own config tree still applies).
            'compact_conversation' => env('AI_AGENT_AI_NATIVE_COMPACTION_COMPACT_CONVERSATION', false),
        ],
        'max_tokens' => (int) env('AI_AGENT_AI_NATIVE_MAX_TOKENS', 1200),
        'temperature' => (float) env('AI_AGENT_AI_NATIVE_TEMPERATURE', 0.1),
        // Surface planner reasoning: when ON, the planner prompt asks the model to
        // include an optional "reasoning":"<one short sentence>" field in its JSON
        // plan, which is accumulated per turn into AgentResponse
        // metadata['reasoning_trace'][] and can be streamed as an agent.reasoning
        // event. Default OFF preserves current behavior (no reasoning field
        // requested, metadata unchanged).
        'expose_reasoning' => (bool) env('AI_AGENT_AI_NATIVE_EXPOSE_REASONING', false),
        // Reply in the user's language. When ON (default), the planner prompt instructs the
        // model to write all user-facing text (message/reasoning/questions/summaries) in the
        // same language as the latest user message — covering languages the package ships no
        // static translations for — falling back to the resolved app/request locale when the
        // message language is ambiguous. Set false to leave reply language to the model.
        'respond_in_user_language' => (bool) env('AI_AGENT_AI_NATIVE_RESPOND_IN_USER_LANGUAGE', true),
        // Live plan timeline (TodoWrite analog): when ON, the planner prompt asks
        // the model to optionally include a "steps":["..."] array (the intended
        // remaining steps) in its JSON plan. The latest steps + a current index are
        // accumulated per turn into AgentResponse metadata['plan'] = {steps, current}
        // and can be streamed as a plan.updated event. Default OFF preserves current
        // behavior (no steps requested, metadata unchanged).
        'plan_timeline' => (bool) env('AI_AGENT_AI_NATIVE_PLAN_TIMELINE', false),
        // Parallel tool calls in one planning step: when enabled and the planner
        // returns a non-empty tool_calls[] array, every independent lookup is
        // executed (sequentially under the hood) and recorded into state before
        // the next planning round-trip — one round-trip for N lookups. Default
        // OFF preserves today's single-call-per-step behavior byte-for-byte.
        'parallel_tools' => env('AI_AGENT_AI_NATIVE_PARALLEL_TOOLS', false),
        'parallel_tools_max' => (int) env('AI_AGENT_AI_NATIVE_PARALLEL_TOOLS_MAX', 8),
        'auto_retry' => [
            // Max automated re-plan attempts per user turn after a recoverable tool failure.
            // 0 (default) keeps the current behavior: escalate to the user immediately.
            'max' => (int) env('AI_AGENT_AI_NATIVE_AUTO_RETRY_MAX', 0),
        ],
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
        // Opt-in: when true, a turn whose message is a READ request (search/show/list/count)
        // cannot license a WRITE tool unless a write is already in progress. Prevents a
        // read follow-up ("search for the invoice for Mohamed") from re-triggering a
        // recently-completed create flow. Read/write verb lists default in ActionIntentGuard;
        // override with ai_native.read_intent_terms / write_intent_terms.
        'guard_read_intent_writes' => env('AI_AGENT_GUARD_READ_INTENT_WRITES', false),
        // Which registered tools are exposed to the planner each turn. Every tool's full
        // schema goes into the prompt, so a large registry inflates context.
        //   'all'          — expose every tool (default; no behaviour change)
        //   'skill_scoped' — when a skill is active, expose only its tools + the 'always' core
        //   'keyword'      — top-K tools by lexical overlap with the turn + the 'always' core
        //   'semantic'     — top-K tools by embedding similarity + the 'always' core (one
        //                    embedding call/turn; tool embeddings are cached). Fails open.
        'tool_selection' => [
            'strategy' => env('AI_AGENT_TOOL_SELECTION', 'all'),
            'always' => ['search_knowledge', 'data_query'],
            'limit' => (int) env('AI_AGENT_TOOL_SELECTION_LIMIT', 12),
            // Bounded fail-open. The keyword/semantic selectors fall open (expose the full set)
            // on a no-signal turn — empty/greeting message, no match, or an embedding error — so
            // the planner is never starved. With a large registry that would dump every schema
            // into the prompt, so the fallback is capped: the full set when the registry is
            // <= fallback_limit (unchanged behavior), otherwise core + the first fallback_limit
            // tools. Set to null to restore the legacy unbounded fail-open.
            'fallback_limit' => env('AI_AGENT_TOOL_SELECTION_FALLBACK_LIMIT', 50),
            // 'full' (default) injects each selected tool's full schema; 'progressive'
            // lists tools by name + summary only and registers a find_tools meta-tool the
            // planner calls to load a tool's full parameters on demand.
            'disclosure' => env('AI_AGENT_TOOL_DISCLOSURE', 'full'),
            // Hybrid disclosure: under 'progressive', these tool names keep their FULL
            // parameter schema (callable directly, no find_tools round-trip) — the
            // "hot core" — while every other tool stays name + summary. null falls back
            // to `always` (so the common core is never gated); [] defers everything.
            // A per-request options.tool_selection.disclosure_full_tools override wins,
            // so one agent can tune its own hot core without changing the global default.
            'disclosure_full_tools' => null,
        ],
        'confirmation_summary' => [
            'enabled' => env('AI_AGENT_AI_NATIVE_CONFIRMATION_SUMMARY', true),
            'prompt' => 'Please review before I run {tool}.',
            'heading' => 'Summary:',
            'instruction' => 'Choose Confirm to continue, or Change to edit before execution.',
            'changed_draft_notice' => '',
            'hide_empty_values' => true,
            'max_depth' => (int) env('AI_AGENT_AI_NATIVE_CONFIRMATION_SUMMARY_MAX_DEPTH', 3),
            'max_items' => (int) env('AI_AGENT_AI_NATIVE_CONFIRMATION_SUMMARY_MAX_ITEMS', 20),
            'max_value_length' => (int) env('AI_AGENT_AI_NATIVE_CONFIRMATION_SUMMARY_MAX_VALUE_LENGTH', 160),
            'computed_totals' => [
                'enabled' => env('AI_AGENT_AI_NATIVE_CONFIRMATION_SUMMARY_COMPUTED_TOTALS', true),
                'quantity_fields' => ['quantity', 'qty'],
                'unit_amount_fields' => ['unit_price', 'price', 'rate', 'amount'],
                'line_total_field' => 'line_total',
                'subtotal_field' => 'subtotal',
                'total_field' => 'total',
            ],
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
        'write_confirmation_question_terms' => [
            'approval' => [
                'would you like',
                'would you like to',
                'should i',
                'do you want',
                'shall i',
                'can i',
                'may i',
                'confirm',
                'please confirm',
            ],
            'actions' => [
                'create',
                'creating',
                'update',
                'updating',
                'delete',
                'deleting',
                'send',
                'sending',
                'save',
                'saving',
                'generate',
                'generating',
                'run',
                'execute',
                'submit',
                'approve',
            ],
            'missing_input' => [
                'what',
                'which',
                'please provide',
                'enter',
                'instead',
                ' or ',
            ],
        ],
        'payload_aliases' => [
            'label_fields' => ['name', 'title', 'label'],
            'email_fields' => ['email'],
        ],
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
    /*
    |--------------------------------------------------------------------------
    | Agent Resources (zero-code model tools)
    |--------------------------------------------------------------------------
    |
    | Declare Eloquent models here to expose them to the agent as find_<name> and
    | create_<name> tools — no tool subclasses required (see AiResource). Each entry:
    |
    |   'customer' => [
    |       'model'    => \App\Models\Customer::class,
    |       'search'   => ['name', 'email'],          // lookup columns
    |       'writable' => ['name', 'email', 'company'],// create/update fields (omit for read-only)
    |       'identity' => ['email'],                   // find-or-create key
    |       'required' => ['name', 'email'],
    |       // 'returns' => ['id', 'name', 'email'],
    |       // 'lookup_only' => true,                  // register only find_customer
    |       // 'defaults' => ['source' => 'ai'],       // server-set columns
    |   ],
    |
    | For tenant scoping or dynamic defaults (closures), register via AiResource in code.
    |
    */
    'resources' => [],

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
        'lock_ttl_seconds' => (int) env('AI_AGENT_RUN_LOCK_TTL', 360),
        'lock_ttl_buffer_seconds' => (int) env('AI_AGENT_RUN_LOCK_TTL_BUFFER', 60),
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
    'tools' => array_filter([
        // 'validate_field' => \LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool::class,
        // 'search_options' => \LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool::class,
        // 'suggest_value' => \LaravelAIEngine\Services\Agent\Tools\SuggestValueTool::class,
        // 'explain_field' => \LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool::class,

        // Design-intelligence website builder. Opt-in: enable both the design
        // builder and the agent tool flag to expose it to the AI runtime.
        'generate_website' => (
            filter_var(env('AI_ENGINE_DESIGN_ENABLED', true), FILTER_VALIDATE_BOOL)
            && filter_var(env('AI_ENGINE_DESIGN_AGENT_TOOL_ENABLED', false), FILTER_VALIDATE_BOOL)
        ) ? \LaravelAIEngine\Services\Agent\Tools\GenerateWebsiteTool::class : null,
    ]),

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
        'rejected_terms' => [
            'api key',
            'access token',
            'secret',
            'password',
            'payment',
            'credit card',
            'line item',
            'product catalog',
            'record id',
            'database record',
        ],
        'rejected_key_suffixes' => [
            '_id',
            '_uuid',
            '_name',
            '_email',
            '_phone',
            '_address',
            '_price',
            '_amount',
            '_total',
            '_number',
            '_code',
        ],
        'scope' => [
            'fallback_fields' => [
                'workspace' => env('AI_AGENT_MEMORY_WORKSPACE_KEY', 'workspace_id'),
                'tenant' => env('AI_AGENT_MEMORY_TENANT_KEY', 'tenant_id'),
                'user' => 'user_id',
            ],
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
                'scope_type,scope_id,session_id,namespace'
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

    'skill_providers' => array_values(array_filter([
        // \App\AI\Skills\AppSkillProvider::class,

        // Design-intelligence website builder skill (opt-in, same flags as the tool).
        (
            filter_var(env('AI_ENGINE_DESIGN_ENABLED', true), FILTER_VALIDATE_BOOL)
            && filter_var(env('AI_ENGINE_DESIGN_AGENT_TOOL_ENABLED', false), FILTER_VALIDATE_BOOL)
        ) ? \LaravelAIEngine\Services\Agent\Skills\GenerateWebsiteSkill::class : null,
    ])),

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
