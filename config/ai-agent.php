<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Agent Mode
    |--------------------------------------------------------------------------
    |
    | Enable unified AI agent that intelligently chooses between quick actions
    | and guided data collection based on request complexity.
    |
    */
    'enabled' => env('AI_AGENT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Strategy
    |--------------------------------------------------------------------------
    |
    | Default execution strategy when complexity analysis is uncertain.
    | Options: quick_action, guided_flow, agent_mode, conversational
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
    | Workflows
    |--------------------------------------------------------------------------
    |
    | Register agent workflows with their trigger keywords.
    | When a message contains a trigger, the workflow will be activated.
    |
    */
    'workflows' => [
        // Example:
        // \App\AI\Workflows\CreateInvoiceWorkflow::class => [
        //     'create invoice',
        //     'new invoice',
        //     'invoice for',
        // ],
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
    | Agent Mode Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for advanced agent mode with multi-step reasoning.
    |
    */
    'agent_mode' => [
        'enabled' => env('AI_AGENT_MODE_ENABLED', true),
        'max_steps' => env('AI_AGENT_MAX_STEPS', 10),
        'max_retries' => env('AI_AGENT_MAX_RETRIES', 3),
        'tools_enabled' => env('AI_AGENT_TOOLS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tools
    |--------------------------------------------------------------------------
    |
     | Available tools for agent mode.
    | Host applications can enable the built-in tools below or provide their
    | own AgentTool implementations through the same registry.
     |
     */
    'tools' => [
        // 'validate_field' => \LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool::class,
        // 'search_options' => \LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool::class,
        // 'suggest_value' => \LaravelAIEngine\Services\Agent\Tools\SuggestValueTool::class,
        // 'explain_field' => \LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool::class,
        // 'business_action_catalog' => \LaravelAIEngine\Services\Agent\Tools\BusinessActionCatalogTool::class,
        // 'prepare_business_action' => \LaravelAIEngine\Services\Agent\Tools\PrepareBusinessActionTool::class,
        // 'execute_business_action' => \LaravelAIEngine\Services\Agent\Tools\ExecuteBusinessActionTool::class,
        // 'suggest_business_action' => \LaravelAIEngine\Services\Agent\Tools\SuggestBusinessActionTool::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deterministic Agent Handlers
    |--------------------------------------------------------------------------
    |
    | Host applications can register small, high-confidence handlers that run
    | before model routing. Use these for deterministic business commands,
    | multilingual aliases, or compliance-critical flows. Handlers must
    | implement LaravelAIEngine\Contracts\DeterministicAgentHandler.
    |
    */
    'deterministic_handlers' => [
        // \App\AI\Handlers\CreateInvoiceIntentHandler::class,
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
        // \App\AI\Capabilities\BusinessCapabilityProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Business Actions
    |--------------------------------------------------------------------------
    |
    | Deterministic write actions the agent may prepare, suggest, and execute.
    | Each action should define id, module, operation, required fields,
    | confirmation behavior, and prepare/handler/suggest callables as needed.
    |
    */
    'business_actions' => [
        //
    ],

    'business_action_providers' => [
        \LaravelAIEngine\Services\BusinessActions\GenericModuleActionDefinitionProvider::class,
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
        'owner_fields' => ['created_by', 'creator_id', 'owner_id'],
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
