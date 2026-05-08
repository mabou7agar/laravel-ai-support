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
