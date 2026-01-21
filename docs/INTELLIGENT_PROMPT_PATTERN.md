# Intelligent Prompt Generation Pattern

## Overview

The Intelligent Prompt Generation pattern uses intent analysis to generate contextual, AI-driven prompts that make the system smarter and more accurate. Instead of static rules, the AI understands context and generates appropriate instructions.

## Core Service: `IntelligentPromptGenerator`

Located: `/packages/laravel-ai-engine/src/Services/IntelligentPromptGenerator.php`

### Key Methods:

1. **`generatePrompt()`** - Main method that analyzes intent and generates contextual prompts
2. **`generateContextualInstructions()`** - Creates instructions based on intent type
3. **`generateExtractionGuidance()`** - Provides field-specific extraction rules
4. **`generateValidationRules()`** - Generates validation logic based on context
5. **`generateNextQuestion()`** - AI-generated natural questions

## Where to Apply This Pattern

### ✅ 1. WorkflowDataCollector (COMPLETED)

**File:** `/packages/laravel-ai-engine/src/Services/Agent/WorkflowDataCollector.php`

**Applied to:**
- Data extraction from user messages
- Next question generation

**Benefits:**
- Understands "Macbook Pro M99" as product name, not quantity
- Generates natural, contextual questions
- Preserves complete values with specifications

**Example:**
```php
// Generate intelligent extraction prompt
$intelligentPrompt = $this->intelligentPrompt->generatePrompt(
    $message,
    $context,
    ['field_definitions' => $fieldDefinitions]
);

// Generate next question
$prompt = $this->intelligentPrompt->generateNextQuestion(
    $context,
    $missing,
    $fieldDefinitions
);
```

---

### ✅ 2. IntelligentCRUDHandler (COMPLETED)

**File:** `/packages/laravel-ai-engine/src/Services/IntelligentCRUDHandler.php`

**Applied to:**
- CRUD operation detection (create/read/update/delete)
- Entity identification with context awareness

**Benefits:**
- Better understanding of "delete the simple product" vs "delete product 123"
- Context-aware operation detection
- Smarter entity resolution

**Example:**
```php
// Generate intelligent prompt for CRUD detection
$intelligentPrompt = $this->intelligentPrompt->generatePrompt(
    $message,
    $context,
    ['operation' => 'crud_detection']
);
```

---

### 🔧 3. GenericEntityResolver (TODO)

**File:** `/packages/laravel-ai-engine/src/Services/GenericEntityResolver.php`

**Should apply to:**
- Entity search and matching
- Duplicate detection and resolution
- Ambiguity handling ("Which customer: John Smith or John Doe?")

**Benefits:**
- Smarter entity matching (fuzzy search with context)
- Better duplicate handling
- Natural language entity resolution

**Recommended implementation:**
```php
protected function searchEntity(
    string $identifier,
    string $modelClass,
    array $searchFields,
    UnifiedActionContext $context
): ?Model {
    // Generate intelligent search prompt
    $intelligentPrompt = $this->intelligentPrompt->generatePrompt(
        $identifier,
        $context,
        [
            'operation' => 'entity_search',
            'model' => $modelClass,
            'search_fields' => $searchFields
        ]
    );
    
    // Use prompt to guide search logic
    // ...
}
```

---

### 🔧 4. AIEnhancedWorkflowService (TODO)

**File:** `/packages/laravel-ai-engine/src/Services/AIEnhancedWorkflowService.php`

**Should apply to:**
- Validation message generation
- Entity matching prompts
- Workflow guidance generation

**Benefits:**
- Context-aware validation messages
- Better entity matching accuracy
- Natural workflow guidance

**Recommended implementation:**
```php
public function validateData(
    array $data,
    array $rules,
    UnifiedActionContext $context
): array {
    // Generate intelligent validation prompt
    $intelligentPrompt = $this->intelligentPrompt->generatePrompt(
        json_encode($data),
        $context,
        [
            'operation' => 'validation',
            'rules' => $rules
        ]
    );
    
    // Use AI to validate with context
    // ...
}
```

---

### 🔧 5. DataCollectorService (TODO)

**File:** `/packages/laravel-ai-engine/src/Services/DataCollector/DataCollectorService.php`

**Should apply to:**
- Field collection prompts
- Data validation
- Enhancement suggestions

**Benefits:**
- Better data collection flow
- Context-aware field prompts
- Intelligent data enhancement

**Recommended implementation:**
```php
protected function askForField(
    string $fieldName,
    array $fieldDef,
    DataCollectorState $state
): string {
    $context = new UnifiedActionContext(
        sessionId: $state->sessionId,
        userId: null
    );
    $context->set('collected_data', $state->collectedData);
    
    // Generate intelligent question
    return $this->intelligentPrompt->generateNextQuestion(
        $context,
        [$fieldName],
        [$fieldName => $fieldDef]
    );
}
```

---

### 🔧 6. ChatService (TODO - Minimal)

**File:** `/packages/laravel-ai-engine/src/Services/ChatService.php`

**Should apply to:**
- System prompt enhancement
- Context-aware RAG queries

**Benefits:**
- Better RAG retrieval
- Context-aware responses

**Recommended implementation:**
```php
protected function enhanceSystemPrompt(
    string $basePrompt,
    string $userMessage,
    array $conversationHistory
): string {
    $context = new UnifiedActionContext('temp', null);
    $context->conversationHistory = $conversationHistory;
    
    $intelligentPrompt = $this->intelligentPrompt->generatePrompt(
        $userMessage,
        $context,
        ['operation' => 'system_prompt_enhancement']
    );
    
    return $basePrompt . "\n\n" . $intelligentPrompt['enhanced_prompt'];
}
```

---

## Implementation Pattern

### Standard Implementation Steps:

1. **Inject `IntelligentPromptGenerator`:**
```php
public function __construct(
    protected IntelligentPromptGenerator $intelligentPrompt
) {}
```

2. **Create Context:**
```php
$context = new UnifiedActionContext($sessionId, $userId);
$context->set('relevant_data', $data);
$context->conversationHistory = $history;
```

3. **Generate Intelligent Prompt:**
```php
$intelligentPrompt = $this->intelligentPrompt->generatePrompt(
    $userMessage,
    $context,
    ['operation' => 'your_operation_type']
);
```

4. **Use Enhanced Prompt:**
```php
$prompt = $intelligentPrompt['enhanced_prompt'] . "\n\n";
$prompt .= "YOUR SPECIFIC INSTRUCTIONS\n";
// ... rest of your prompt
```

---

## Benefits Summary

| Aspect | Before | After |
|--------|--------|-------|
| **Extraction** | Static rules | Intent-driven, context-aware |
| **Questions** | Generic templates | AI-generated, natural |
| **Understanding** | Literal parsing | Contextual comprehension |
| **Errors** | Common mistakes (M99 → 99) | Self-correcting guidance |
| **Maintenance** | Update rules everywhere | Update once in generator |

---

## Key Principles

1. **Intent-Driven**: Different logic for create vs update vs provide_data
2. **Context-Aware**: Knows what was asked, collected, and missing
3. **Self-Correcting**: Generates guidance to prevent common mistakes
4. **Natural Language**: AI generates friendly prompts, not robotic forms
5. **Centralized**: One service manages all intelligent prompt generation

---

## Testing

When implementing, test these scenarios:

1. **Model Numbers**: "Macbook Pro M99" should be product name, not quantity 99
2. **Context Switching**: System remembers what it asked for
3. **Corrections**: "No, I meant John Smith not John Doe"
4. **Compound Responses**: "Customer is John, email john@example.com"
5. **Ambiguity**: System asks clarifying questions naturally

---

## Future Enhancements

1. **Learning from Mistakes**: Track extraction errors and improve prompts
2. **User Preferences**: Adapt prompt style to user communication patterns
3. **Multi-language**: Generate prompts in user's language
4. **Domain-Specific**: Industry-specific prompt templates
5. **A/B Testing**: Test different prompt strategies for optimization
