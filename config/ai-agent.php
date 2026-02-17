<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Minimal Setup (Most apps only need this)
    |--------------------------------------------------------------------------
    |
    | 1) entity_model_map  - map domain entity names to model classes
    | 2) intent            - tune list/follow-up/lookup vocabulary
    | 3) orchestrator      - adjust action behavior/instructions
    |
    | All other sections are optional advanced/legacy controls.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | AI-First Global Mode
    |--------------------------------------------------------------------------
    |
    | profile:
    | - balanced         => keeps section-level fallback behavior
    | - strict_ai_first  => disables heuristic/rule fallbacks globally
    |
    | strict:
    | - optional explicit override for all profiles (higher priority than profile)
    | - set true/false only when you need to force behavior directly
    |
    */
    'ai_first' => [
        'profile' => env('AI_AGENT_MODE_PROFILE', 'balanced'),
        'strict' => env('AI_AGENT_AI_FIRST_STRICT', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Mode
    |--------------------------------------------------------------------------
    |
    | Enable unified AI agent that intelligently chooses between quick actions
    | and guided data collection based on request complexity.
    | Legacy/optional for older agent-mode flows.
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
    | Legacy/optional for older strategy-driven flows.
    |
    */
    'default_strategy' => env('AI_AGENT_DEFAULT_STRATEGY', 'conversational'),

    /*
    |--------------------------------------------------------------------------
    | Complexity Thresholds
    |--------------------------------------------------------------------------
    |
    | Thresholds for automatic strategy selection based on complexity analysis.
    | Legacy/optional unless you use complexity-based strategy routing.
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
    | Legacy/optional unless you use strategy-driven agent mode.
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
    | Workflow Directories (Optional)
    |--------------------------------------------------------------------------
    |
    | Additional directories scanned by WorkflowDiscoveryService.
    |
    */
    'workflow_directories' => [],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Cache complexity analysis results to improve performance.
    | Legacy/optional for complexity-based flows.
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
    | Optional unless you run AgentMode workflows directly.
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
    | Optional unless you rely on ToolRegistry discovery.
    |
    */
    'tools' => [
        // 'validate_field' => \LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool::class,
        // 'search_options' => \LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool::class,
        // 'suggest_value' => \LaravelAIEngine\Services\Agent\Tools\SuggestValueTool::class,
        // 'explain_field' => \LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent Classification
    |--------------------------------------------------------------------------
    |
    | Keywords and limits used by orchestrator intent classification.
    | Adjust these to reduce hardcoded behavior and adapt to your domain.
    |
    */
    'intent' => [
        'list_verbs' => ['list', 'show', 'display', 'search', 'find', 'fetch', 'retrieve', 'refresh', 'relist'],
        'refresh_words' => ['again', 'reload'],
        'record_terms' => ['invoices', 'emails', 'items', 'records'],
        'entity_terms' => ['invoice', 'email', 'item', 'record', 'entry', 'customer', 'product'],
        'followup_keywords' => [
            'what', 'which', 'who', 'when', 'where', 'why', 'how',
            'total', 'sum', 'count', 'average', 'status', 'due',
            'paid', 'unpaid', 'latest', 'earliest',
        ],
        'followup_pronouns' => ['it', 'its', 'them', 'those', 'these', 'that', 'this', 'ones'],
        'ordinal_words' => ['first', 'second', 'third', 'fourth', 'fifth', '1st', '2nd', '3rd', '4th', '5th'],
        'ordinal_map' => [
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
            '1st' => 1,
            '2nd' => 2,
            '3rd' => 3,
            '4th' => 4,
            '5th' => 5,
        ],
        'positional_entity_words' => ['item', 'email', 'invoice', 'entry', 'record'],
        'max_positional_index' => 100,
        'max_option_selection' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Model Map
    |--------------------------------------------------------------------------
    |
    | Map generic entity names to concrete model classes for detail lookup.
    | Keep this app-specific to avoid hardcoded package assumptions.
    |
    */
    'entity_model_map' => [
        // 'invoice' => \App\Models\Invoice::class,
        // 'customer' => \App\Models\Customer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Config Discovery
    |--------------------------------------------------------------------------
    |
    | Customize where model config classes are discovered from.
    |
    */
    'model_config_discovery' => [
        'paths' => [
            app_path('AI/Configs'),
        ],
        'namespaces' => [
            rtrim(env('APP_NAMESPACE', 'App\\'), '\\') . '\\AI\\Configs',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Orchestrator Prompt and Parsing
    |--------------------------------------------------------------------------
    |
    | Controls generated orchestration prompt instructions and action parsing.
    |
    */
    'orchestrator' => [
        'default_action' => env('AI_AGENT_ORCHESTRATOR_DEFAULT_ACTION', 'conversational'),
        'allowed_actions' => [
            'start_collector',
            'use_tool',
            'route_to_node',
            'resume_session',
            'pause_and_handle',
            'search_rag',
            'conversational',
        ],
        'action_descriptions' => [
            'start_collector' => 'When user wants to create, update, or delete data',
            'use_tool' => 'When a specific tool should be executed directly',
            'route_to_node' => 'When user wants to list/search/view data from a remote node domain',
            'resume_session' => 'When user asks to resume or go back to a paused workflow',
            'pause_and_handle' => 'When user interrupts an active workflow to handle a separate request',
            'search_rag' => 'When user wants to view/list/search local indexed data',
            'conversational' => 'For greetings, follow-up summaries, and general chat',
        ],
        'instructions' => [
            'Analyze conversation history and user message before choosing an action.',
            'Preserve selected entity context and avoid asking user to repeat IDs when context is sufficient.',
            'If user asks follow-up about already listed entities, avoid re-listing unless explicitly requested.',
            'For route_to_node, never route to local.',
            'Choose one action and keep reasoning concise.',
        ],
        'user_profile_fields' => [
            'name',
            'email',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routed Session Continuation
    |--------------------------------------------------------------------------
    |
    | Controls whether follow-up messages should stay on a previously routed
    | remote node or shift back to local orchestration.
    |
    */
    'routed_session' => [
        'history_window' => 3,
        'use_explicit_topic_checks' => false,
        'explicit_topic_checks' => [],
        'fallback_continue_on_ai_error' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Follow-Up Guard
    |--------------------------------------------------------------------------
    |
    | AI-first classifier that decides whether a post-list message is
    | follow-up context (answer directly) or a relist/new query.
    |
    */
    'followup_guard' => [
        'enabled' => env('AI_AGENT_FOLLOWUP_GUARD_ENABLED', true),
        'max_tokens' => 64,
        'temperature' => 0.0,
        'history_window' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Positional Reference Resolver
    |--------------------------------------------------------------------------
    |
    | AI-first extraction of "first/second/#3/item 4" references.
    |
    */
    'positional_reference' => [
        'enabled' => env('AI_AGENT_POSITIONAL_REFERENCE_ENABLED', true),
        'max_tokens' => 24,
        'temperature' => 0.0,
        'max_position' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Autonomous RAG Agent
    |--------------------------------------------------------------------------
    |
    | function_calling:
    | - off: always use prompt-based tool selection
    | - auto/on: enable function calling for supported models
    |
    | legacy_heuristic_fallback:
    | - false: AI-first fallback path (default)
    | - true: enables old rule-based inference on parse failure
    |
    | decision_prompt_template:
    | - null: use package default decision prompt
    | - string: override the prompt template used for tool routing
    |
    */
    'autonomous_rag' => [
        'function_calling' => env('AI_AGENT_AUTONOMOUS_RAG_FUNCTION_CALLING', 'off'),
        'legacy_heuristic_fallback' => env('AI_AGENT_AUTONOMOUS_RAG_LEGACY_HEURISTICS', false),
        'decision_fallback_tool' => env('AI_AGENT_AUTONOMOUS_RAG_FALLBACK_TOOL', 'vector_search'),
        'decision_fallback_limit' => env('AI_AGENT_AUTONOMOUS_RAG_FALLBACK_LIMIT', 10),
        'decision_prompt_template' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Policies
    |--------------------------------------------------------------------------
    |
    | Centralized policy rules and user-facing messages to avoid hardcoded
    | literals inside orchestrator/coordinator implementations.
    |
    */
    'policy' => [
        'intents' => [
            'destructive_verbs' => ['delete', 'remove', 'cancel'],
        ],
        'messages' => [
            'no_collector_destructive' => 'Delete operations are not currently available through the AI assistant. Please use the application interface to delete records.',
            'no_collector_generic' => "I couldn't find a way to handle that request. I can help you create, update, or search for records. What would you like to do?",
            'no_paused_session' => "There's no paused session to resume.",
            'resume_session' => "Welcome back! Let's continue with your :collector.",
            'rag_no_results' => 'No results found.',
            'rag_no_relevant_info' => "I couldn't find any relevant information. Could you please rephrase your question?",
            'node_not_found' => "I couldn't find a remote node matching ':resource'.",
            'node_unreachable' => "I couldn't reach remote node ':node':location (:summary). I did not run a local fallback query to avoid mixed-domain results. Please verify the node is running and try again.",
            'positional_unknown' => "I couldn't understand which item you're referring to. Could you be more specific?",
            'positional_not_found' => "I couldn't find item #:position in the previous list. Please check the number and try again.",
            'positional_details_unavailable' => "I found the :entity but couldn't retrieve its details. Please try again.",
            'selected_option' => "**Selected option :number**\n\n:detail",
            'option_remote_lookup' => 'show details for :entity id :id',
            'option_local_lookup' => 'show full details for :entity id :id',
            'tool_not_specified' => 'No tool specified.',
            'collector_not_specified' => 'No collector specified.',
            'collector_unavailable' => "Collector ':collector' not available.",
        ],
        'limits' => [
            'node_error_summary_max' => 220,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversational Mode
    |--------------------------------------------------------------------------
    |
    | Template and generation settings for conversational fallback responses.
    |
    */
    'conversational' => [
        'max_tokens' => 600,
        'temperature' => 0.7,
        'instructions' => [
            'If the user asks a follow-up question about previously listed items, answer directly from context.',
            'Do not repeat the full list unless the user explicitly asks to list/search again.',
            'Respond in a friendly, helpful manner.',
        ],
        'prompt_template' => <<<PROMPT
You are a helpful AI assistant. Respond naturally to the user's message.

Recent conversation:
:history

Recent entity list context:
:entity_context

User: :message

Behavior rules:
:instructions
PROMPT,
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Summarization
    |--------------------------------------------------------------------------
    |
    | When RAG queries return raw data, the AI summarizer reformats it into
    | a natural-language response. These settings control that step.
    |
    */
    'rag_summarization' => [
        'max_tokens' => 1500,
        'temperature' => 0.4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Protocol Labels (Optional)
    |--------------------------------------------------------------------------
    |
    | Wire-level labels expected in AI classification/extraction responses.
    | Keep defaults unless you intentionally change prompt protocols.
    |
    */
    'protocol' => [
        'followup_guard' => [
            'response_key' => 'CLASSIFICATION',
            'classes' => [
                'follow_up_answer' => 'FOLLOW_UP_ANSWER',
                'refresh_list' => 'REFRESH_LIST',
                'entity_lookup' => 'ENTITY_LOOKUP',
                'new_query' => 'NEW_QUERY',
                'unknown' => 'UNKNOWN',
            ],
        ],
        'positional_reference' => [
            'response_key' => 'POSITION',
            'none_value' => 'NONE',
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
