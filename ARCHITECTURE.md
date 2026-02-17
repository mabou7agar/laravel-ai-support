# Laravel AI Engine — Architecture Guide

## Directory Structure

```
src/
├── AIEngineServiceProvider.php    # Registers all services, configs, routes, commands
├── helpers.php                    # Global helper functions
│
├── Contracts/                     # Interfaces & abstract classes
│   ├── AutonomousModelConfig      # Define CRUD tools for a model
│   ├── DiscoverableAutonomousCollector  # Auto-discovered data collectors
│   ├── DiscoverableWorkflow       # Auto-discovered workflows
│   ├── EngineDriverInterface      # AI engine driver contract
│   ├── VectorizableInterface      # Models that can be vectorized
│   └── RAGgable                   # Models searchable via RAG
│
├── DTOs/                          # Data Transfer Objects
│   ├── AIRequest / AIResponse     # Engine-level request/response
│   ├── AgentResponse              # Internal orchestrator response
│   ├── UnifiedActionContext       # Session state (history, metadata, workflows)
│   ├── AutonomousCollectorConfig  # Collector field definitions
│   └── InteractiveAction          # Action buttons/quick replies
│
├── Drivers/                       # AI Engine Drivers
│   ├── OpenAI/                    # GPT-4o, GPT-4o-mini, etc.
│   ├── Anthropic/                 # Claude models
│   ├── Gemini/                    # Google Gemini
│   ├── DeepSeek/                  # DeepSeek models
│   └── ...                        # Stable Diffusion, Azure, etc.
│
├── Enums/
│   ├── EngineEnum                 # openai, anthropic, gemini, deepseek
│   └── EntityEnum                 # Model identifiers (gpt-4o-mini, etc.)
│
├── Services/
│   ├── AIEngineManager.php        # Multi-engine orchestration & facade root
│   ├── AIEngineService.php        # Core AI generation (prompt → response)
│   ├── ChatService.php            # Main entry point for chat messages
│   ├── ConversationService.php    # Conversation CRUD & history
│   │
│   ├── Agent/                     # ── AI Agent System ──
│   │   ├── MinimalAIOrchestrator  # Central router: AI decides every action
│   │   ├── ContextManager         # Session state (cache-backed)
│   │   ├── OrchestratorPromptBuilder    # Builds AI routing prompt
│   │   ├── OrchestratorDecisionParser   # Parses AI JSON → action
│   │   ├── OrchestratorResourceDiscovery # Discovers tools/collectors/collections
│   │   ├── OrchestratorResponseFormatter # Formats prompt sections
│   │   ├── AgentResponseConverter       # AgentResponse → AIResponse
│   │   ├── AgentPolicyService           # Configurable policy messages
│   │   ├── AgentToolExecutor            # Local + remote tool execution
│   │   ├── IntentClassifierService      # Classifies follow-ups, lists, lookups
│   │   ├── FollowUpStateService         # Entity list context for follow-ups
│   │   ├── FollowUpDecisionAIService    # AI-powered follow-up guard
│   │   ├── PositionalReferenceAIService # "show me the 3rd one" resolver
│   │   ├── PositionalReferenceCoordinator # Positional reference handler
│   │   ├── DecisionPolicyService        # Policy overrides for AI decisions
│   │   ├── NextStepSuggestionService    # Contextual next-step suggestions
│   │   ├── NodeRoutingCoordinator       # route_to_node handler
│   │   ├── RoutedSessionPolicyService   # Routed session lifecycle
│   │   ├── CollectorExecutionCoordinator # Collector session lifecycle
│   │   ├── ToolExecutionCoordinator     # Single-shot tool execution
│   │   ├── UserProfileResolver          # User context for prompts
│   │   ├── AgentCollectionAdapter       # Agent ↔ collection format adapter
│   │   ├── WorkflowDiscoveryService     # Discovers DiscoverableWorkflow impls
│   │   ├── AgentMode.php                # Legacy workflow engine
│   │   ├── AgentWorkflow.php            # Base workflow class
│   │   ├── WorkflowDataCollector.php    # Workflow data collection
│   │   │
│   │   ├── Handlers/
│   │   │   ├── AgentReasoningLoop       # THOUGHT/ACTION/OBSERVATION loop
│   │   │   ├── AgentToolHandler         # Local tool registry
│   │   │   ├── CrossNodeToolResolver    # Remote tool discovery
│   │   │   ├── AutonomousCollectorHandler # Collector step handler
│   │   │   └── ToolParameterExtractor   # Extract params from AI output
│   │   │
│   │   └── Traits/
│   │       ├── AutomatesSteps           # Workflow step automation
│   │       └── HasWorkflowConfig        # Workflow configuration
│   │
│   ├── RAG/                       # ── RAG Pipeline ──
│   │   ├── AutonomousRAGAgent     # Full RAG flow orchestrator
│   │   ├── AutonomousRAGDecisionService # AI decides: db_query vs vector_search
│   │   ├── RAGToolDispatcher      # Routes tool → executor
│   │   ├── RAGQueryExecutor       # Structured DB queries (list/count/aggregate)
│   │   ├── RAGFilterService       # AI-generated filters → Eloquent
│   │   ├── RAGCollectionDiscovery # Canonical collection source of truth
│   │   ├── RAGModelDiscovery      # Model metadata discovery
│   │   ├── IntelligentRAGService  # Vector search backend (Qdrant)
│   │   └── ContextLimitationService # Context window management
│   │
│   ├── Node/                      # ── Multi-Node System ──
│   │   ├── NodeRegistryService    # Manages registered nodes (DB-backed)
│   │   ├── NodeMetadataDiscovery  # Discovers local capabilities
│   │   ├── NodeForwarder          # HTTP forwarding to remote nodes
│   │   ├── NodeNameMatcher        # Fuzzy matches user intent → node
│   │   ├── NodeRoutingDigestService # Routing digest for AI prompt
│   │   ├── NodeAuthService        # Node-to-node authentication
│   │   ├── CircuitBreakerService  # Prevents cascading failures
│   │   └── NodeRouterService      # Route resolution
│   │
│   ├── DataCollector/             # ── Guided Multi-Step Forms ──
│   │   ├── AutonomousCollectorRegistry       # Discovers & registers collectors
│   │   ├── AutonomousCollectorDiscoveryService # Filesystem scanning
│   │   └── CollectorExecutionCoordinator     # (alias in Agent/)
│   │
│   ├── Vector/                    # ── Vector Search ──
│   │   ├── VectorSearchService    # Qdrant search operations
│   │   ├── EmbeddingService       # Text → embeddings
│   │   └── VectorAccessControl    # Multi-tenant vector filtering
│   │
│   ├── Media/                     # ── Media Processing ──
│   │   ├── AudioService           # Speech-to-text, TTS
│   │   ├── VisionService          # Image analysis
│   │   ├── VideoService           # Video processing
│   │   ├── DocumentService        # Document parsing
│   │   └── MediaEmbeddingService  # Media → embeddings
│   │
│   └── (other)
│       ├── CreditManager          # Usage credits & billing
│       ├── CacheManager           # Response caching
│       ├── RateLimitManager       # Rate limiting
│       ├── AnalyticsManager       # Usage analytics
│       └── ...
│
├── Models/                        # Eloquent models
│   ├── Conversation / Message     # Chat persistence
│   ├── AINode                     # Registered remote nodes
│   ├── PendingAction              # Queued user actions
│   └── AIModel                    # AI model registry
│
├── Traits/                        # Model traits
│   ├── Vectorizable               # Makes model searchable via RAG
│   ├── HasMediaEmbeddings         # Media embedding support
│   ├── HasAIActions               # AI-powered actions on models
│   └── HasAIConfigBuilder         # Fluent AI config builder
│
├── Console/Commands/              # Artisan commands (38 commands)
├── Http/Controllers/              # API controllers
├── Events/ & Listeners/           # Event system
├── Jobs/                          # Queue jobs
└── Facades/                       # Laravel facades
```

## Core Chat Flow

```
Controller → ChatService::processMessage()
  → resolveConversation() (load history)
  → MinimalAIOrchestrator::process()
    → RULE 1: Active collector? → continueCollector()
    → RULE 2: Ask AI for routing decision
      → OrchestratorPromptBuilder (tools, collectors, collections, nodes, history)
      → AI returns JSON: { action, parameters, message }
      → OrchestratorDecisionParser
      → Execute action:
          search_rag    → AutonomousRAGAgent → summarizeRAGResponse()
          conversational → direct AI response with context
          route_to_node → NodeRoutingCoordinator → remote node
          execute_tool  → AgentToolExecutor
          start_collector → CollectorExecutionCoordinator
  → AgentResponseConverter::convert() → AIResponse
```

## RAG Pipeline

```
MinimalAIOrchestrator::executeSearchRAG()
  → AutonomousRAGAgent::process()
    → AutonomousRAGDecisionService (AI picks tool + params)
    → RAGToolDispatcher::dispatch()
        db_query      → RAGQueryExecutor (SQL)
        db_count      → RAGQueryExecutor (COUNT)
        db_aggregate  → RAGQueryExecutor (SUM/AVG)
        vector_search → IntelligentRAGService (Qdrant)
          └─ fallback → db_query (if vector returns empty)
    → Raw result
  → summarizeRAGResponse() (AI formats raw data)
  → AgentResponse
```

## Config Files

- **`config/ai-engine.php`** — Engine infrastructure: API keys, models, vectorization, nodes, project context
- **`config/ai-agent.php`** — Agent behavior: orchestrator settings, intent classification, RAG summarization, entity maps
