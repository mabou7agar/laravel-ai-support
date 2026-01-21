# Intelligent Routing Architecture

## Your Vision vs Current Implementation

### What You Want:

```
User Message
    ↓
CENTRAL INTELLIGENCE ANALYZER
    ↓
Determines:
  1. Is this related to active workflow? → Continue workflow
  2. Is this a normal question I can answer? → Direct answer
  3. Is this RAG query? → Search knowledge base
  4. Is this an action/workflow? → Start workflow
  5. Should I join a sub-workflow? → Intelligent routing
    ↓
Allow seamless switching:
  - User can ask questions mid-workflow
  - User can continue workflow after question
  - System remembers context
    ↓
Natural AI communication (like ChatGPT)
```

### What We Currently Have:

```
AgentOrchestrator::process()
    ↓
1. Check active workflow → continueWorkflow() ✅
2. Check DataCollector → continue ✅
3. ComplexityAnalyzer::analyze() → AI analyzes ✅
4. selectStrategy() → choose approach ✅
5. executeStrategy() → route to handler ✅
```

**Strategies:**
- `quick_action` - Simple actions
- `guided_flow` - DataCollector
- `agent_mode` - Complex workflows
- `conversational` - RAG/simple questions

---

## What's Missing (The Gap):

### 1. ❌ No Seamless Conversation Switching

**Current Problem:**
```php
// AgentOrchestrator.php line 38
if ($context->currentWorkflow && !empty($context->workflowState)) {
    // ALWAYS continues workflow - no escape!
    return $this->agentMode->continueWorkflow($message, $context);
}
```

**Issue:** User can't ask "what's the weather?" mid-workflow without breaking it.

**What You Want:**
```php
if ($context->currentWorkflow) {
    // First, analyze if message is:
    // A) Workflow continuation ("John Smith")
    // B) Normal question ("what's the weather?")
    // C) Workflow cancellation ("cancel")
    
    $messageIntent = $this->analyzeMessageIntent($message, $context);
    
    if ($messageIntent === 'normal_question') {
        // Answer question WITHOUT breaking workflow
        $answer = $this->answerQuestion($message, $context);
        // Keep workflow active for next message
        return $answer;
    }
    
    if ($messageIntent === 'workflow_continuation') {
        return $this->agentMode->continueWorkflow($message, $context);
    }
}
```

---

### 2. ❌ No Intelligent Sub-Workflow Detection

**Current:** Sub-workflows are hardcoded in workflow config.

**What You Want:**
```php
// During invoice creation, user says: "create a new product first"
// System should:
1. Detect this is a sub-workflow request
2. Pause current workflow (invoice)
3. Start sub-workflow (product creation)
4. After completion, resume invoice workflow
5. Use created product in invoice
```

---

### 3. ❌ ComplexityAnalyzer is Too Limited

**Current:** Only determines complexity level (simple/medium/high).

**What You Want:**
```php
MessageAnalyzer::analyze($message, $context) returns:
{
    "type": "workflow_continuation|normal_question|new_workflow|rag_query|simple_answer",
    "confidence": 0.95,
    "related_to_active_workflow": true,
    "requires_knowledge_base": false,
    "can_answer_directly": false,
    "suggested_action": "continue_workflow",
    "reasoning": "User is providing customer name as requested"
}
```

---

## Proposed Architecture

### New Component: `MessageAnalyzer`

```php
class MessageAnalyzer
{
    /**
     * Analyze message to determine routing
     */
    public function analyze(string $message, UnifiedActionContext $context): array
    {
        // PRIORITY 1: Check active workflow
        if ($context->currentWorkflow) {
            return $this->analyzeInWorkflowContext($message, $context);
        }
        
        // PRIORITY 2: Check if simple question
        if ($this->isSimpleQuestion($message)) {
            return [
                'type' => 'simple_answer',
                'action' => 'answer_directly',
                'confidence' => 0.9
            ];
        }
        
        // PRIORITY 3: Check if RAG query
        if ($this->requiresKnowledgeBase($message)) {
            return [
                'type' => 'rag_query',
                'action' => 'search_knowledge',
                'confidence' => 0.85
            ];
        }
        
        // PRIORITY 4: Check if workflow/action
        return $this->analyzeForWorkflow($message, $context);
    }
    
    /**
     * Analyze message in workflow context
     */
    protected function analyzeInWorkflowContext(string $message, UnifiedActionContext $context): array
    {
        // Is user answering the workflow question?
        if ($context->get('asking_for')) {
            // Check if message is relevant to what we asked
            $isRelevant = $this->isRelevantToQuestion($message, $context->get('asking_for'));
            
            if ($isRelevant) {
                return [
                    'type' => 'workflow_continuation',
                    'action' => 'continue_workflow',
                    'confidence' => 0.95
                ];
            }
        }
        
        // Is user asking a question?
        if ($this->looksLikeQuestion($message)) {
            return [
                'type' => 'normal_question',
                'action' => 'answer_and_resume_workflow',
                'confidence' => 0.85,
                'workflow_to_resume' => $context->currentWorkflow
            ];
        }
        
        // Is user requesting sub-workflow?
        if ($this->isSubWorkflowRequest($message)) {
            return [
                'type' => 'sub_workflow',
                'action' => 'start_sub_workflow',
                'parent_workflow' => $context->currentWorkflow,
                'confidence' => 0.9
            ];
        }
        
        // Is user canceling?
        if ($this->isCancellation($message)) {
            return [
                'type' => 'cancel',
                'action' => 'cancel_workflow',
                'confidence' => 0.95
            ];
        }
        
        // Default: assume workflow continuation
        return [
            'type' => 'workflow_continuation',
            'action' => 'continue_workflow',
            'confidence' => 0.7
        ];
    }
}
```

---

### Enhanced `AgentOrchestrator`

```php
class AgentOrchestrator
{
    public function __construct(
        protected MessageAnalyzer $messageAnalyzer,  // NEW
        protected ComplexityAnalyzer $complexityAnalyzer,
        protected AgentMode $agentMode,
        protected ContextManager $contextManager,
        protected RAGService $ragService  // NEW
    ) {}
    
    public function process(string $message, string $sessionId, $userId, array $options = []): AgentResponse
    {
        $context = $this->contextManager->getOrCreate($sessionId, $userId);
        $context->addUserMessage($message);
        
        // STEP 1: Analyze message to determine routing
        $analysis = $this->messageAnalyzer->analyze($message, $context);
        
        Log::info('Message analyzed', [
            'type' => $analysis['type'],
            'action' => $analysis['action'],
            'confidence' => $analysis['confidence']
        ]);
        
        // STEP 2: Route based on analysis
        return match($analysis['action']) {
            'continue_workflow' => $this->continueWorkflow($message, $context),
            'answer_and_resume_workflow' => $this->answerQuestionInWorkflow($message, $context),
            'start_sub_workflow' => $this->startSubWorkflow($message, $context, $analysis),
            'cancel_workflow' => $this->cancelWorkflow($context),
            'answer_directly' => $this->answerDirectly($message, $context),
            'search_knowledge' => $this->searchKnowledge($message, $context),
            'start_workflow' => $this->startNewWorkflow($message, $context),
            default => $this->handleConversational($message, $context)
        };
    }
    
    /**
     * Answer question without breaking workflow
     */
    protected function answerQuestionInWorkflow(string $message, UnifiedActionContext $context): AgentResponse
    {
        // Answer the question
        $answer = $this->ragService->search($message);
        
        // Add to conversation history
        $context->addAssistantMessage($answer);
        
        // Keep workflow active - don't change state
        $this->contextManager->save($context);
        
        return AgentResponse::conversational(
            message: $answer . "\n\n" . $this->getWorkflowPrompt($context),
            context: $context,
            metadata: [
                'workflow_active' => true,
                'workflow_paused_for_question' => true
            ]
        );
    }
    
    /**
     * Start sub-workflow and pause parent
     */
    protected function startSubWorkflow(string $message, UnifiedActionContext $context, array $analysis): AgentResponse
    {
        // Save parent workflow state
        $context->set('parent_workflow', [
            'class' => $context->currentWorkflow,
            'step' => $context->currentStep,
            'state' => $context->workflowState,
            'data' => $context->get('collected_data')
        ]);
        
        // Detect which sub-workflow to start
        $subWorkflowClass = $this->detectSubWorkflow($message, $context);
        
        // Start sub-workflow
        return $this->agentMode->startWorkflow($subWorkflowClass, $context, $message);
    }
}
```

---

## Implementation Plan

### Phase 1: Message Analyzer (Core Intelligence)
- [ ] Create `MessageAnalyzer` service
- [ ] Implement `analyzeInWorkflowContext()`
- [ ] Implement `isSimpleQuestion()`
- [ ] Implement `requiresKnowledgeBase()`
- [ ] Implement `isSubWorkflowRequest()`

### Phase 2: Enhanced Orchestrator
- [ ] Inject `MessageAnalyzer` into `AgentOrchestrator`
- [ ] Add `answerQuestionInWorkflow()` method
- [ ] Add `startSubWorkflow()` method
- [ ] Add `cancelWorkflow()` method
- [ ] Update `process()` to use message analysis

### Phase 3: Workflow State Management
- [ ] Add parent workflow tracking to context
- [ ] Implement workflow pause/resume
- [ ] Handle sub-workflow completion
- [ ] Merge sub-workflow results into parent

### Phase 4: Natural Conversation
- [ ] Allow questions mid-workflow
- [ ] Maintain workflow context after questions
- [ ] Smart prompt generation after interruptions
- [ ] Seamless workflow resumption

---

## Benefits

| Feature | Before | After |
|---------|--------|-------|
| **Mid-workflow questions** | ❌ Breaks workflow | ✅ Answers & resumes |
| **Sub-workflows** | ❌ Hardcoded only | ✅ Intelligent detection |
| **Context switching** | ❌ Loses state | ✅ Preserves everything |
| **Natural conversation** | ❌ Rigid | ✅ Like ChatGPT |
| **User experience** | Robotic | Human-like |

---

## Example Flow

```
User: "create invoice"
System: "Who is the customer?"

User: "wait, what's the weather today?"
System: [Analyzes: normal_question, not workflow_continuation]
System: "It's sunny, 72°F. Now, who is the customer for the invoice?"

User: "John Smith"
System: [Analyzes: workflow_continuation]
System: "Great! What products?"

User: "create a new product first - Laptop Pro"
System: [Analyzes: sub_workflow request]
System: [Pauses invoice, starts product creation]
System: "Product created! Resuming invoice... What products for John Smith?"

User: "the laptop we just created"
System: [Uses product from sub-workflow]
System: "Perfect! Quantity?"
```

This is natural AI communication! 🎯
