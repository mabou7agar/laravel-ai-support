# Unified AI Agent Implementation Plan

## Executive Summary

This plan outlines the integration of ChatService's dynamic action system with DataCollector's guided data collection to create a **Unified AI Agent** that intelligently chooses between quick actions and guided flows based on user intent and data complexity.

**Timeline:** 6-8 weeks  
**Complexity:** Medium-High  
**Impact:** High - Transforms the AI Engine into a true conversational agent

---

## Current State Analysis

### What We Have

#### ChatService Action System ✅
- **ActionRegistry**: Auto-discovers actions from models with `HasAIActions` trait
- **ActionManager**: Coordinates discovery, parameter extraction, and execution
- **ActionParameterExtractor**: AI-powered parameter extraction from messages
- **ActionExecutionPipeline**: Executes actions with middleware support
- **Intent Analysis**: AI determines user intent and suggests appropriate actions
- **Pending Actions**: Manages incomplete actions across multiple messages
- **Remote Actions**: Supports distributed action execution across nodes

#### DataCollector System ✅
- **Guided Data Collection**: Step-by-step field collection with validation
- **Intent Analysis**: AI-powered field extraction with anti-hallucination guards
- **State Management**: Tracks collection progress and field status
- **Field Filtering**: Ensures only current field is extracted
- **Validation**: Comprehensive field-level validation
- **Completion Actions**: Executes callbacks or action classes on completion

### What's Missing

1. **Unified Decision Layer**: No single point that decides "quick action vs guided collection"
2. **Seamless Transitions**: Can't switch from quick action to guided collection mid-conversation
3. **Shared Context**: ChatService and DataCollector maintain separate contexts
4. **Action Type Awareness**: ActionRegistry doesn't know about DataCollector actions
5. **Complexity Detection**: No AI-based complexity analysis to choose approach

---

## Architecture Design

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                      Unified AI Agent                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  User Message                                                    │
│       │                                                          │
│       ▼                                                          │
│  ┌─────────────────────────────────────────────────────┐       │
│  │         Intent & Complexity Analysis (AI)            │       │
│  │  - What does user want?                              │       │
│  │  - How complex is the request?                       │       │
│  │  - What data is provided?                            │       │
│  │  - What's missing?                                   │       │
│  └─────────────────────────────────────────────────────┘       │
│       │                                                          │
│       ├──────────────┬──────────────┬──────────────────┐       │
│       ▼              ▼              ▼                  ▼        │
│  ┌─────────┐   ┌──────────┐   ┌──────────┐   ┌──────────┐    │
│  │ Quick   │   │  Guided  │   │ Multi-   │   │  Custom  │    │
│  │ Action  │   │  Flow    │   │  Step    │   │  Tool    │    │
│  │         │   │(DataColl)│   │ (Agent)  │   │ Executor │    │
│  └─────────┘   └──────────┘   └──────────┘   └──────────┘    │
│       │              │              │              │            │
│       └──────────────┴──────────────┴──────────────┘           │
│                      │                                          │
│              ┌───────▼────────┐                                │
│              │ Action Context │                                │
│              │   (Unified)    │                                │
│              └───────┬────────┘                                │
│                      │                                          │
│              ┌───────▼────────┐                                │
│              │   Execution    │                                │
│              │   Pipeline     │                                │
│              └───────┬────────┘                                │
│                      │                                          │
│                  Response                                       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Core Components

#### 1. AgentOrchestrator (NEW)
**Purpose:** Central decision maker that routes requests to appropriate handlers

```php
class AgentOrchestrator
{
    public function process(string $message, string $sessionId, $userId): AgentResponse
    {
        // 1. Analyze intent and complexity
        $analysis = $this->analyzeRequest($message, $sessionId);
        
        // 2. Choose execution strategy
        $strategy = $this->selectStrategy($analysis);
        
        // 3. Execute via appropriate handler
        return $this->executeStrategy($strategy, $message, $sessionId, $userId);
    }
    
    protected function selectStrategy(array $analysis): string
    {
        // AI-powered decision making
        return match(true) {
            $analysis['complexity'] === 'simple' && $analysis['complete'] => 'quick_action',
            $analysis['complexity'] === 'medium' && $analysis['needs_guidance'] => 'guided_flow',
            $analysis['complexity'] === 'high' && $analysis['multi_step'] => 'agent_mode',
            default => 'conversational'
        };
    }
}
```

#### 2. ComplexityAnalyzer (NEW)
**Purpose:** AI-powered analysis to determine request complexity

```php
class ComplexityAnalyzer
{
    public function analyze(string $message, array $context): array
    {
        // AI prompt to analyze complexity
        $prompt = $this->buildComplexityPrompt($message, $context);
        
        $response = $this->ai->generate($prompt);
        
        return [
            'complexity' => 'simple|medium|high',
            'data_completeness' => 0.0-1.0,
            'missing_fields' => [...],
            'needs_guidance' => true|false,
            'suggested_strategy' => 'quick_action|guided_flow|agent_mode',
            'confidence' => 0.0-1.0,
        ];
    }
}
```

#### 3. UnifiedActionContext (NEW)
**Purpose:** Shared context between all execution strategies

```php
class UnifiedActionContext
{
    public string $sessionId;
    public string $userId;
    public array $conversationHistory;
    public ?array $pendingAction;
    public ?array $dataCollectorState;
    public array $extractedData;
    public array $validationErrors;
    public string $currentStrategy;
    
    public function switchStrategy(string $newStrategy): void
    {
        // Seamlessly transition between strategies
        $this->currentStrategy = $newStrategy;
        $this->migrateContext($newStrategy);
    }
}
```

#### 4. DataCollectorActionType (NEW)
**Purpose:** Register DataCollector as an action type in ActionRegistry

```php
class DataCollectorActionType implements ActionTypeInterface
{
    public function register(ActionRegistry $registry): void
    {
        // Auto-discover models that need guided collection
        $models = $this->discoverComplexModels();
        
        foreach ($models as $modelClass) {
            $config = $this->generateDataCollectorConfig($modelClass);
            
            $registry->register("collect_{$config->name}", [
                'label' => "📝 {$config->title}",
                'description' => "Guided data collection for {$config->title}",
                'executor' => DataCollectorActionExecutor::class,
                'type' => 'data_collector',
                'complexity' => 'medium',
                'config' => $config,
                'model_class' => $modelClass,
            ]);
        }
    }
}
```

#### 5. AgentMode (NEW)
**Purpose:** Advanced multi-step reasoning for complex scenarios

```php
class AgentMode
{
    public function execute(string $message, UnifiedActionContext $context): AgentResponse
    {
        // 1. Plan actions needed
        $plan = $this->planActions($message, $context);
        
        // 2. Execute plan steps
        foreach ($plan->steps as $step) {
            $result = $this->executeStep($step, $context);
            
            if ($result->needsUserInput) {
                return $result; // Pause and ask user
            }
            
            $context->addResult($step, $result);
        }
        
        // 3. Complete and respond
        return $this->completeExecution($context);
    }
}
```

---

## Implementation Phases

### Phase 1: Foundation (Week 1-2)

#### Goals
- Create core agent infrastructure
- Establish unified context system
- Implement complexity analyzer

#### Tasks

**Week 1: Core Infrastructure**
- [ ] Create `AgentOrchestrator` service
- [ ] Create `UnifiedActionContext` DTO
- [ ] Create `ComplexityAnalyzer` service
- [ ] Create `AgentResponse` DTO
- [ ] Update service provider bindings
- [ ] Add configuration options (`config/ai-agent.php`)

**Week 2: Integration Layer**
- [ ] Create `ActionTypeInterface`
- [ ] Implement `DataCollectorActionType`
- [ ] Update `ActionRegistry` to support action types
- [ ] Create strategy selector logic
- [ ] Add context migration utilities

#### Deliverables
- ✅ `AgentOrchestrator` service
- ✅ Complexity analysis working
- ✅ Unified context system
- ✅ Basic strategy selection

#### Testing
```bash
php artisan ai:test-agent --message="Create a course" --debug
# Should analyze complexity and choose guided flow

php artisan ai:test-agent --message="Create post 'Hello'" --debug
# Should analyze complexity and choose quick action
```

---

### Phase 2: DataCollector Integration (Week 3-4)

#### Goals
- Register DataCollector as action type
- Enable seamless transitions
- Maintain anti-hallucination guards

#### Tasks

**Week 3: Action Type Registration**
- [ ] Implement `DataCollectorActionExecutor`
- [ ] Auto-discover models needing guided collection
- [ ] Generate `DataCollectorConfig` from model metadata
- [ ] Register DataCollector actions in `ActionRegistry`
- [ ] Add complexity hints to model configurations

**Week 4: Transition Logic**
- [ ] Implement strategy switching in `UnifiedActionContext`
- [ ] Add context migration between quick action ↔ guided flow
- [ ] Preserve anti-hallucination guards during transitions
- [ ] Handle incomplete quick actions → guided flow fallback
- [ ] Add user confirmation for strategy switches

#### Deliverables
- ✅ DataCollector actions discoverable
- ✅ Seamless transitions working
- ✅ Anti-hallucination guards preserved
- ✅ Fallback mechanisms in place

#### Testing
```bash
# Test auto-discovery
php artisan ai:show-actions --type=data_collector

# Test transition
php artisan ai:test-agent --message="Create a course called Laravel" --debug
# Should start with quick action, detect missing data, switch to guided flow
```

---

### Phase 3: Agent Mode (Week 5-6)

#### Goals
- Implement multi-step reasoning
- Add planning capabilities
- Enable tool usage

#### Tasks

**Week 5: Planning System**
- [ ] Create `AgentPlanner` service
- [ ] Implement action planning prompts
- [ ] Add step execution logic
- [ ] Create `AgentStep` DTO
- [ ] Add plan validation

**Week 6: Tool System**
- [ ] Create `AgentTool` interface
- [ ] Implement built-in tools:
  - [ ] `ValidateFieldTool`
  - [ ] `SearchOptionsTool`
  - [ ] `SuggestValueTool`
  - [ ] `ExplainFieldTool`
- [ ] Add tool discovery
- [ ] Implement tool execution pipeline

#### Deliverables
- ✅ Multi-step planning working
- ✅ Tool system functional
- ✅ Complex scenarios handled

#### Testing
```bash
# Test multi-step
php artisan ai:test-agent --message="Create a course and then change the name" --debug

# Test tool usage
php artisan ai:test-agent --message="What should I put for course level?" --debug
# Should use ExplainFieldTool
```

---

### Phase 4: Polish & Optimization (Week 7-8)

#### Goals
- Optimize performance
- Add comprehensive logging
- Create documentation
- Production hardening

#### Tasks

**Week 7: Optimization**
- [ ] Add caching for complexity analysis
- [ ] Optimize AI prompt sizes
- [ ] Implement request batching
- [ ] Add performance metrics
- [ ] Create debug mode for agent

**Week 8: Documentation & Testing**
- [ ] Write comprehensive documentation
- [ ] Create usage examples
- [ ] Add integration tests
- [ ] Create migration guide
- [ ] Performance benchmarking

#### Deliverables
- ✅ Production-ready system
- ✅ Complete documentation
- ✅ Test coverage >80%
- ✅ Migration guide

#### Testing
```bash
# Performance test
php artisan ai:benchmark-agent --iterations=100

# Integration test
php artisan test --filter=AgentTest
```

---

## Technical Specifications

### 1. AgentOrchestrator

**File:** `src/Services/Agent/AgentOrchestrator.php`

```php
<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Services\Actions\ActionManager;
use LaravelAIEngine\Services\DataCollector\DataCollectorService;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    public function __construct(
        protected ComplexityAnalyzer $complexityAnalyzer,
        protected ActionManager $actionManager,
        protected DataCollectorService $dataCollector,
        protected AgentMode $agentMode,
        protected ContextManager $contextManager
    ) {}

    /**
     * Process user message and route to appropriate handler
     */
    public function process(
        string $message,
        string $sessionId,
        $userId,
        array $options = []
    ): AgentResponse {
        // 1. Get or create unified context
        $context = $this->contextManager->getOrCreate($sessionId, $userId);
        
        // 2. Add message to context
        $context->addUserMessage($message);
        
        // 3. Analyze complexity and intent
        $analysis = $this->complexityAnalyzer->analyze($message, $context);
        
        Log::channel('ai-engine')->info('Agent analysis completed', [
            'session_id' => $sessionId,
            'complexity' => $analysis['complexity'],
            'strategy' => $analysis['suggested_strategy'],
            'confidence' => $analysis['confidence'],
        ]);
        
        // 4. Select execution strategy
        $strategy = $this->selectStrategy($analysis, $context);
        
        // 5. Execute via appropriate handler
        return $this->executeStrategy($strategy, $message, $context, $options);
    }

    /**
     * Select execution strategy based on analysis
     */
    protected function selectStrategy(array $analysis, UnifiedActionContext $context): string
    {
        // If already in a flow, continue unless user explicitly changes
        if ($context->currentStrategy && !$this->shouldSwitchStrategy($analysis, $context)) {
            return $context->currentStrategy;
        }
        
        // AI-suggested strategy
        $suggested = $analysis['suggested_strategy'];
        
        // Override based on configuration or user preferences
        if ($this->shouldOverrideStrategy($suggested, $context)) {
            return $this->getOverrideStrategy($context);
        }
        
        return $suggested;
    }

    /**
     * Execute strategy
     */
    protected function executeStrategy(
        string $strategy,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return match($strategy) {
            'quick_action' => $this->executeQuickAction($message, $context, $options),
            'guided_flow' => $this->executeGuidedFlow($message, $context, $options),
            'agent_mode' => $this->executeAgentMode($message, $context, $options),
            default => $this->executeConversational($message, $context, $options),
        };
    }

    /**
     * Execute quick action (existing ChatService flow)
     */
    protected function executeQuickAction(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        // Use existing ActionManager
        $actions = $this->actionManager->generateActionsForContext(
            $message,
            $context->toArray(),
            $context->intentAnalysis
        );
        
        if (empty($actions)) {
            // No actions found, fallback to conversational
            return $this->executeConversational($message, $context, $options);
        }
        
        // If action is incomplete, switch to guided flow
        $action = $actions[0];
        if (!empty($action->data['missing_fields'] ?? [])) {
            Log::channel('ai-engine')->info('Quick action incomplete, switching to guided flow');
            $context->switchStrategy('guided_flow');
            return $this->executeGuidedFlow($message, $context, $options);
        }
        
        // Execute complete action
        $result = $this->actionManager->executeAction($action, $context->userId);
        
        return AgentResponse::fromActionResult($result, $context);
    }

    /**
     * Execute guided flow (DataCollector)
     */
    protected function executeGuidedFlow(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        // Check if DataCollector session exists
        if (!$context->dataCollectorState) {
            // Start new DataCollector session
            $config = $this->createDataCollectorConfig($context);
            $state = $this->dataCollector->startSession($context->sessionId, $config);
            $context->dataCollectorState = $state;
            
            return AgentResponse::fromDataCollectorState($state, $context);
        }
        
        // Continue existing DataCollector session
        $response = $this->dataCollector->processMessage(
            $context->sessionId,
            $message,
            $options['engine'] ?? 'openai',
            $options['model'] ?? 'gpt-4o'
        );
        
        // Update context
        $context->dataCollectorState = $response->state;
        
        // Check if completed
        if ($response->state->status === 'completed') {
            $context->clearDataCollectorState();
        }
        
        return AgentResponse::fromDataCollectorResponse($response, $context);
    }

    /**
     * Execute agent mode (multi-step reasoning)
     */
    protected function executeAgentMode(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->agentMode->execute($message, $context, $options);
    }

    /**
     * Execute conversational (no action)
     */
    protected function executeConversational(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        // Use existing ChatService for pure conversation
        // This is the fallback when no actions are detected
        
        return AgentResponse::conversational(
            message: "I understand you said: {$message}. How can I help you?",
            context: $context
        );
    }
}
```

### 2. ComplexityAnalyzer

**File:** `src/Services/Agent/ComplexityAnalyzer.php`

```php
<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Cache;

class ComplexityAnalyzer
{
    public function __construct(
        protected AIEngineService $ai
    ) {}

    /**
     * Analyze request complexity and suggest strategy
     */
    public function analyze(string $message, UnifiedActionContext $context): array
    {
        // Cache key based on message and context
        $cacheKey = $this->getCacheKey($message, $context);
        
        return Cache::remember($cacheKey, 300, function () use ($message, $context) {
            return $this->performAnalysis($message, $context);
        });
    }

    /**
     * Perform AI-powered complexity analysis
     */
    protected function performAnalysis(string $message, UnifiedActionContext $context): array
    {
        $prompt = $this->buildAnalysisPrompt($message, $context);
        
        $request = new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from('openai'),
            model: EntityEnum::from('gpt-4o-mini'),
            maxTokens: 300,
            temperature: 0,
            metadata: ['purpose' => 'complexity_analysis']
        );
        
        $response = $this->ai->generate($request);
        
        return $this->parseAnalysisResponse($response->content);
    }

    /**
     * Build analysis prompt
     */
    protected function buildAnalysisPrompt(string $message, UnifiedActionContext $context): string
    {
        $prompt = "Analyze this user request and determine the best execution strategy.\n\n";
        $prompt .= "User Message: \"{$message}\"\n\n";
        
        if ($context->conversationHistory) {
            $prompt .= "Recent Conversation:\n";
            foreach (array_slice($context->conversationHistory, -3) as $msg) {
                $prompt .= "- {$msg['role']}: {$msg['content']}\n";
            }
            $prompt .= "\n";
        }
        
        if ($context->pendingAction) {
            $prompt .= "Pending Action: {$context->pendingAction['label']}\n";
            $prompt .= "Missing Fields: " . implode(', ', $context->pendingAction['missing_fields'] ?? []) . "\n\n";
        }
        
        $prompt .= "Analyze:\n";
        $prompt .= "1. What is the user trying to do?\n";
        $prompt .= "2. How much data did they provide?\n";
        $prompt .= "3. How many fields are missing?\n";
        $prompt .= "4. How complex is this request?\n\n";
        
        $prompt .= "Complexity Levels:\n";
        $prompt .= "- SIMPLE: All data provided, can execute immediately (e.g., 'Create post Hello World')\n";
        $prompt .= "- MEDIUM: Some data provided, needs 2-5 more fields (e.g., 'Create a course')\n";
        $prompt .= "- HIGH: Complex multi-step request or modification (e.g., 'Create course then change name')\n\n";
        
        $prompt .= "Strategies:\n";
        $prompt .= "- quick_action: Execute immediately with provided data\n";
        $prompt .= "- guided_flow: Step-by-step data collection (DataCollector)\n";
        $prompt .= "- agent_mode: Multi-step reasoning and planning\n";
        $prompt .= "- conversational: Just chatting, no action needed\n\n";
        
        $prompt .= "Respond in JSON:\n";
        $prompt .= "{\n";
        $prompt .= "  \"complexity\": \"simple|medium|high\",\n";
        $prompt .= "  \"intent\": \"create|modify|delete|query|chat\",\n";
        $prompt .= "  \"data_completeness\": 0.0-1.0,\n";
        $prompt .= "  \"missing_fields_count\": 0-10,\n";
        $prompt .= "  \"needs_guidance\": true|false,\n";
        $prompt .= "  \"suggested_strategy\": \"quick_action|guided_flow|agent_mode|conversational\",\n";
        $prompt .= "  \"confidence\": 0.0-1.0,\n";
        $prompt .= "  \"reasoning\": \"Brief explanation\"\n";
        $prompt .= "}";
        
        return $prompt;
    }

    /**
     * Parse AI response
     */
    protected function parseAnalysisResponse(string $content): array
    {
        // Extract JSON from response
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $json = json_decode($matches[0], true);
            
            if ($json) {
                return array_merge([
                    'complexity' => 'medium',
                    'intent' => 'chat',
                    'data_completeness' => 0.5,
                    'missing_fields_count' => 0,
                    'needs_guidance' => false,
                    'suggested_strategy' => 'conversational',
                    'confidence' => 0.5,
                    'reasoning' => '',
                ], $json);
            }
        }
        
        // Fallback
        return [
            'complexity' => 'medium',
            'intent' => 'chat',
            'data_completeness' => 0.5,
            'missing_fields_count' => 0,
            'needs_guidance' => false,
            'suggested_strategy' => 'conversational',
            'confidence' => 0.3,
            'reasoning' => 'Failed to parse AI response',
        ];
    }

    /**
     * Get cache key
     */
    protected function getCacheKey(string $message, UnifiedActionContext $context): string
    {
        $contextHash = md5(json_encode([
            'pending_action' => $context->pendingAction,
            'current_strategy' => $context->currentStrategy,
            'history_count' => count($context->conversationHistory),
        ]));
        
        return "complexity_analysis:" . md5($message) . ":{$contextHash}";
    }
}
```

### 3. UnifiedActionContext

**File:** `src/DTOs/UnifiedActionContext.php`

```php
<?php

namespace LaravelAIEngine\DTOs;

class UnifiedActionContext
{
    public function __construct(
        public string $sessionId,
        public $userId,
        public array $conversationHistory = [],
        public ?array $pendingAction = null,
        public ?array $dataCollectorState = null,
        public array $extractedData = [],
        public array $validationErrors = [],
        public string $currentStrategy = 'conversational',
        public ?array $intentAnalysis = null,
        public array $metadata = []
    ) {}

    /**
     * Add user message to history
     */
    public function addUserMessage(string $message): void
    {
        $this->conversationHistory[] = [
            'role' => 'user',
            'content' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
        
        // Keep only last 10 messages
        if (count($this->conversationHistory) > 10) {
            $this->conversationHistory = array_slice($this->conversationHistory, -10);
        }
    }

    /**
     * Add assistant message to history
     */
    public function addAssistantMessage(string $message): void
    {
        $this->conversationHistory[] = [
            'role' => 'assistant',
            'content' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
        
        if (count($this->conversationHistory) > 10) {
            $this->conversationHistory = array_slice($this->conversationHistory, -10);
        }
    }

    /**
     * Switch execution strategy
     */
    public function switchStrategy(string $newStrategy): void
    {
        $oldStrategy = $this->currentStrategy;
        $this->currentStrategy = $newStrategy;
        
        // Migrate context data
        $this->migrateContext($oldStrategy, $newStrategy);
    }

    /**
     * Migrate context between strategies
     */
    protected function migrateContext(string $from, string $to): void
    {
        // Quick action → Guided flow
        if ($from === 'quick_action' && $to === 'guided_flow') {
            // Transfer extracted data to DataCollector initial data
            if ($this->pendingAction && !empty($this->pendingAction['params'])) {
                $this->metadata['initial_data'] = $this->pendingAction['params'];
            }
        }
        
        // Guided flow → Quick action
        if ($from === 'guided_flow' && $to === 'quick_action') {
            // Transfer collected data to extracted data
            if ($this->dataCollectorState) {
                $this->extractedData = array_merge(
                    $this->extractedData,
                    $this->dataCollectorState['data'] ?? []
                );
            }
        }
    }

    /**
     * Clear DataCollector state
     */
    public function clearDataCollectorState(): void
    {
        $this->dataCollectorState = null;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'user_id' => $this->userId,
            'conversation_history' => $this->conversationHistory,
            'pending_action' => $this->pendingAction,
            'data_collector_state' => $this->dataCollectorState,
            'extracted_data' => $this->extractedData,
            'validation_errors' => $this->validationErrors,
            'current_strategy' => $this->currentStrategy,
            'intent_analysis' => $this->intentAnalysis,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: $data['session_id'],
            userId: $data['user_id'],
            conversationHistory: $data['conversation_history'] ?? [],
            pendingAction: $data['pending_action'] ?? null,
            dataCollectorState: $data['data_collector_state'] ?? null,
            extractedData: $data['extracted_data'] ?? [],
            validationErrors: $data['validation_errors'] ?? [],
            currentStrategy: $data['current_strategy'] ?? 'conversational',
            intentAnalysis: $data['intent_analysis'] ?? null,
            metadata: $data['metadata'] ?? []
        );
    }
}
```

---

## Configuration

**File:** `config/ai-agent.php`

```php
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
            'App\\Models\\Course',
            'App\\Models\\ComplexForm',
        ],
        
        // Always use quick action for these models
        'quick_action' => [
            'App\\Models\\Post',
            'App\\Models\\Comment',
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
    |
    */
    'tools' => [
        'validate_field' => \LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool::class,
        'search_options' => \LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool::class,
        'suggest_value' => \LaravelAIEngine\Services\Agent\Tools\SuggestValueTool::class,
        'explain_field' => \LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool::class,
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
```

---

## Migration Strategy

### For Existing ChatService Users

#### Option 1: Gradual Migration (Recommended)

```php
// Enable agent mode but keep existing behavior as default
'ai-agent.enabled' => true,
'ai-agent.default_strategy' => 'quick_action', // Keep current behavior

// Gradually add models to guided flow
'ai-agent.strategy_overrides.guided_flow' => [
    'App\\Models\\Course', // Start with one model
],
```

#### Option 2: Full Migration

```php
// Enable full agent mode
'ai-agent.enabled' => true,
'ai-agent.default_strategy' => 'conversational',

// Let AI decide strategy for all models
'ai-agent.strategy_overrides' => [],
```

### For Existing DataCollector Users

```php
// DataCollector automatically becomes available as action type
// No code changes needed - just enable agent mode

'ai-agent.enabled' => true,

// DataCollector sessions continue working
// New sessions can be started via agent orchestrator
```

---

## Testing Strategy

### Unit Tests

```php
// tests/Unit/Agent/ComplexityAnalyzerTest.php
class ComplexityAnalyzerTest extends TestCase
{
    public function test_simple_request_detection()
    {
        $analyzer = app(ComplexityAnalyzer::class);
        $context = new UnifiedActionContext('test-session', 1);
        
        $analysis = $analyzer->analyze('Create post "Hello World"', $context);
        
        $this->assertEquals('simple', $analysis['complexity']);
        $this->assertEquals('quick_action', $analysis['suggested_strategy']);
    }
    
    public function test_complex_request_detection()
    {
        $analyzer = app(ComplexityAnalyzer::class);
        $context = new UnifiedActionContext('test-session', 1);
        
        $analysis = $analyzer->analyze('Create a course', $context);
        
        $this->assertEquals('medium', $analysis['complexity']);
        $this->assertEquals('guided_flow', $analysis['suggested_strategy']);
    }
}
```

### Integration Tests

```php
// tests/Feature/Agent/AgentOrchestratorTest.php
class AgentOrchestratorTest extends TestCase
{
    public function test_quick_action_execution()
    {
        $orchestrator = app(AgentOrchestrator::class);
        
        $response = $orchestrator->process(
            'Create post "Test Post"',
            'test-session',
            1
        );
        
        $this->assertTrue($response->success);
        $this->assertEquals('quick_action', $response->strategy);
    }
    
    public function test_guided_flow_execution()
    {
        $orchestrator = app(AgentOrchestrator::class);
        
        $response = $orchestrator->process(
            'Create a course',
            'test-session',
            1
        );
        
        $this->assertEquals('guided_flow', $response->strategy);
        $this->assertNotNull($response->dataCollectorState);
    }
    
    public function test_strategy_switching()
    {
        $orchestrator = app(AgentOrchestrator::class);
        
        // Start with incomplete quick action
        $response1 = $orchestrator->process(
            'Create a course called Laravel',
            'test-session',
            1
        );
        
        // Should switch to guided flow
        $this->assertEquals('guided_flow', $response1->strategy);
        
        // Continue with guided flow
        $response2 = $orchestrator->process(
            'A comprehensive Laravel course',
            'test-session',
            1
        );
        
        $this->assertEquals('guided_flow', $response2->strategy);
    }
}
```

---

## Performance Benchmarks

### Expected Performance

| Operation | Current | Target | Improvement |
|-----------|---------|--------|-------------|
| Simple request | 1.5s | 1.2s | 20% faster |
| Complex request | 3.0s | 2.5s | 17% faster |
| Strategy switch | N/A | 0.5s | New feature |
| Complexity analysis | N/A | 0.8s | Cached |

### Optimization Strategies

1. **Cache complexity analysis** (5 min TTL)
2. **Batch AI requests** when possible
3. **Lazy load action registry** only when needed
4. **Reuse context** across messages
5. **Optimize prompts** to reduce token usage

---

## Success Metrics

### Phase 1 Success Criteria
- ✅ Complexity analyzer accuracy >85%
- ✅ Strategy selection accuracy >90%
- ✅ Context migration works 100%
- ✅ No regression in existing features

### Phase 2 Success Criteria
- ✅ DataCollector actions discoverable
- ✅ Seamless transitions working
- ✅ Anti-hallucination guards preserved
- ✅ User satisfaction >90%

### Phase 3 Success Criteria
- ✅ Multi-step planning works
- ✅ Tool execution successful
- ✅ Complex scenarios handled
- ✅ Performance within targets

### Phase 4 Success Criteria
- ✅ Production deployment successful
- ✅ Documentation complete
- ✅ Test coverage >80%
- ✅ Zero critical bugs

---

## Risks & Mitigation

### Risk 1: AI Complexity Analysis Inaccuracy
**Impact:** High  
**Probability:** Medium  
**Mitigation:**
- Add confidence thresholds
- Allow user override
- Fallback to safe default (conversational)
- Continuous monitoring and tuning

### Risk 2: Performance Degradation
**Impact:** Medium  
**Probability:** Low  
**Mitigation:**
- Aggressive caching
- Optimize prompts
- Batch requests
- Performance monitoring

### Risk 3: Breaking Changes
**Impact:** High  
**Probability:** Low  
**Mitigation:**
- Gradual migration path
- Feature flags
- Backward compatibility
- Comprehensive testing

### Risk 4: User Confusion
**Impact:** Medium  
**Probability:** Medium  
**Mitigation:**
- Clear strategy indicators
- Explain transitions
- Allow manual override
- User education

---

## Next Steps

### Immediate Actions (This Week)
1. Review and approve this plan
2. Set up project structure
3. Create initial service stubs
4. Configure development environment

### Week 1 Kickoff
1. Create `AgentOrchestrator` service
2. Implement `ComplexityAnalyzer`
3. Set up testing framework
4. Begin documentation

### Questions to Resolve
1. Should we enable agent mode by default or opt-in?
2. What models should use guided flow vs quick action?
3. What's the acceptable performance overhead?
4. How should we handle strategy switching UI/UX?

---

## Conclusion

This Unified AI Agent plan transforms your AI Engine from a collection of separate systems into a cohesive, intelligent agent that:

✅ **Intelligently chooses** between quick actions and guided flows  
✅ **Seamlessly transitions** between strategies  
✅ **Preserves anti-hallucination guards** throughout  
✅ **Maintains backward compatibility** with existing code  
✅ **Enables advanced features** like multi-step reasoning  
✅ **Provides excellent UX** with clear feedback  

**Timeline:** 6-8 weeks  
**Effort:** ~200-250 hours  
**Impact:** Transforms AI Engine into true conversational agent  

Ready to start? 🚀
