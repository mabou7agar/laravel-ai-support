# Subworkflow Isolation Architecture

## Overview

Subworkflows provide a way to delegate entity creation to specialized workflows while maintaining clean separation of concerns. This document explains the improved isolation architecture that prevents context pollution between parent and child workflows.

## The Problem

Before isolation improvements, subworkflows shared context with their parent workflows, leading to:

1. **Data Pollution**: Price "500" being added to parent's items array
2. **Field Duplication**: Email "user@example.com" duplicated in both `name` and `email` fields
3. **Context Leakage**: Intermediate subworkflow data bleeding into parent workflow state

## The Solution: Isolated Subcontexts

### Architecture

```
Parent Workflow (Invoice)
    ↓ Creates isolated subcontext
Subworkflow (Customer Creation)
    ↓ Collects data in isolated context
    ↓ Returns only final result
Parent Workflow
    ↓ Merges only the result
```

### Key Components

#### 1. `UnifiedActionContext::createSubContext()`

Creates a clean, isolated context for subworkflow execution:

```php
$subContext = $context->createSubContext([
    'customer_identifier' => 'user@example.com',
]);

// Subcontext has:
// ✅ Shared conversation history
// ✅ Initial data needed for subworkflow
// ❌ NO parent's collected_data
// ❌ NO parent's workflow_state
```

#### 2. `UnifiedActionContext::mergeSubworkflowResult()`

Merges only the final result back to parent:

```php
$context->mergeSubworkflowResult([
    'entity_id' => $customer->id,
    'entity' => $customer,
]);

// Parent receives:
// ✅ Created entity ID
// ✅ Created entity object
// ❌ NOT intermediate collected_data
// ❌ NOT subworkflow's temporary state
```

## Usage Example

### Before (Context Pollution)

```php
// Parent context:
{
    "items": [{"product": "Laptop", "quantity": 10}]
}

// User says "500" (price in product subworkflow)
// AI extracts to parent's items:
{
    "items": [
        {"product": "Laptop", "quantity": 10},
        {"product": "500", "quantity": 1}  // ❌ WRONG!
    ]
}
```

### After (Isolated Subcontext)

```php
// Parent context:
{
    "items": [{"product": "Laptop", "quantity": 10}]
}

// Create isolated subcontext for product creation
$subContext = $context->createSubContext([
    'product_name' => 'Laptop',
]);

// User says "500" (price in product subworkflow)
// AI extracts to SUBCONTEXT only:
{
    "sale_price": 500  // ✅ Correct field in isolated context
}

// Parent receives only the result:
{
    "items": [
        {"product": "Laptop", "quantity": 10}
    ],
    "created_entity": Product{id: 15, name: "Laptop", price: 500}
}
```

## Implementation Guide

### Step 1: Mark Subworkflow Context

When creating a subworkflow, use `createSubContext()`:

```php
// In GenericEntityResolver or custom resolver
public function executeSubworkflow($subflowClass, $data, $context) {
    // Create isolated context
    $subContext = $context->createSubContext([
        'entity_identifier' => $data['identifier'],
        'parent_field' => $data['field_name'],
    ]);
    
    // Execute subworkflow with isolated context
    $workflow = app($subflowClass);
    $result = $workflow->execute($subContext);
    
    // Merge only the result
    $context->mergeSubworkflowResult($result->data);
    
    return $result;
}
```

### Step 2: Check Isolation in Data Collection

The `WorkflowDataCollector` automatically detects isolated subcontexts:

```php
$isSubworkflow = $context->metadata['is_subworkflow'] ?? false;

if ($isSubworkflow) {
    // This context is isolated - no filtering needed
    // Data collected here won't pollute parent
}
```

### Step 3: Return Clean Results

Subworkflows should return only what the parent needs:

```php
// In subworkflow's final action
return ActionResult::success(
    message: "✅ Customer Created Successfully!",
    data: [
        'entity_id' => $customer->id,
        'entity' => $customer,
        // Don't include intermediate state like collected_data
    ]
);
```

## Benefits

### 1. Clean Separation
- Parent and child workflows have separate data spaces
- No accidental data sharing or pollution

### 2. Predictable Behavior
- Subworkflow can't accidentally modify parent's state
- Parent only receives explicit results

### 3. Easier Debugging
- Each workflow's data is isolated
- Logs show clear boundaries between workflows

### 4. Better UX
- User doesn't notice workflow transitions
- Conversation flows naturally
- No confusion from mixed contexts

## Migration Guide

### Old Approach (Filtering)

```php
// ❌ Complex filtering to prevent pollution
if ($inSubworkflow) {
    $filteredFields = array_filter($fields, function($field) {
        return !isParentEntityField($field);
    });
}
```

### New Approach (Isolation)

```php
// ✅ Clean architectural isolation
$subContext = $context->createSubContext($initialData);
// Subcontext is naturally isolated - no filtering needed
```

## Best Practices

1. **Always use `createSubContext()` for subworkflows**
   - Don't share the parent context directly
   - Initialize with only needed data

2. **Return minimal results**
   - Only return entity ID and entity object
   - Don't return intermediate collected_data

3. **Trust the isolation**
   - No need for complex filtering logic
   - Architecture handles separation

4. **Log context boundaries**
   - Mark when entering/exiting subworkflows
   - Helps with debugging

## Troubleshooting

### Issue: Data still leaking between workflows

**Check:**
- Are you using `createSubContext()` or sharing parent context?
- Is the subworkflow properly marked with `is_subworkflow` metadata?
- Are you merging entire state instead of just results?

### Issue: Subworkflow can't access needed data

**Solution:**
- Pass required data in `createSubContext()` initial data
- Don't expect subworkflow to access parent's collected_data

### Issue: Parent not receiving subworkflow results

**Check:**
- Is subworkflow returning data in correct format?
- Is `mergeSubworkflowResult()` being called?
- Are you returning `entity_id` and `entity` keys?

## Related Documentation

- [Workflow Architecture](./WORKFLOW_ARCHITECTURE.md)
- [Data Collection Guide](./DATA_COLLECTION.md)
- [Entity Resolution](./ENTITY_RESOLUTION.md)
