# Tool-Based CRUD Architecture

## Overview

The `AutonomousModelConfig` defines **all CRUD operations as tools** that the AI can discover and use dynamically. This replaces the old workflow-based approach for CRUD operations.

**⚠️ Important:** The `StartWorkflowHandler` has been removed for CRUD operations. All data operations (create, read, update, delete) now go through `AutonomousRAGAgent` which uses the tool-based architecture.

## Architecture

### 1. Base Class: `AutonomousModelConfig`

Located: `/packages/laravel-ai-engine/src/Contracts/AutonomousModelConfig.php`

**Key Methods:**
- `getTools()` - Returns array of CRUD tools (create, update, delete, custom actions)
- `getFilterConfig()` - Returns filter configuration for queries
- `getAllowedOperations()` - Returns permissions for user
- `validate()` - Validates data before operations
- `transformData()` - Transforms data before saving

### 2. Model-Specific Config

Example: `InvoiceModelConfig` at `/app/AI/Configs/InvoiceModelConfig.php`

**Implements:**
```php
class InvoiceModelConfig extends AutonomousModelConfig
{
    public static function getTools(): array
    {
        return [
            'create_invoice' => [
                'description' => 'Create a new invoice',
                'parameters' => [...],
                'requires_confirmation' => true,
                'handler' => function($data) { ... }
            ],
            'update_invoice' => [...],
            'delete_invoice' => [...],
            'mark_invoice_paid' => [...],
            'send_invoice' => [...],
        ];
    }
}
```

### 3. AI Integration

The `AutonomousRAGAgent` automatically:
1. **Discovers** tools from model configs
2. **Exposes** them to AI in the prompt
3. **Executes** tools when AI chooses them

## Benefits

✅ **Single Source of Truth** - All operations in one config  
✅ **AI-Driven** - AI decides when to create/update/delete  
✅ **Permission-Based** - Built-in authorization checks  
✅ **Flexible** - Easy to add custom operations  
✅ **Consistent** - Same pattern for all models  
✅ **Type-Safe** - Tools define their parameters  

## Usage Example

### User Request
```
"Update invoice 217 to mark it as paid"
```

### AI Decision
```json
{
  "tool": "model_tool",
  "parameters": {
    "model": "invoice",
    "tool_name": "mark_invoice_paid",
    "tool_params": {
      "id": 217
    }
  }
}
```

### Execution Flow
1. AI analyzes request → chooses `model_tool`
2. `AutonomousRAGAgent` finds `InvoiceModelConfig`
3. Checks user permissions via `getAllowedOperations()`
4. Executes `mark_invoice_paid` handler
5. Returns formatted response

## Available Tools for Invoice

| Tool | Description | Confirmation Required |
|------|-------------|----------------------|
| `create_invoice` | Create new invoice | Yes |
| `update_invoice` | Update existing invoice | Yes |
| `delete_invoice` | Delete invoice | Yes |
| `mark_invoice_paid` | Mark as paid | No |
| `send_invoice` | Send to customer | Yes |

## Adding New Tools

```php
public static function getTools(): array
{
    return [
        'duplicate_invoice' => [
            'description' => 'Duplicate an existing invoice',
            'parameters' => [
                'id' => 'required|integer - Invoice ID to duplicate',
            ],
            'requires_confirmation' => true,
            'handler' => function($data) {
                $original = Invoice::find($data['id']);
                $duplicate = $original->replicate();
                $duplicate->save();
                return [
                    'success' => true,
                    'message' => "Invoice duplicated as #{$duplicate->invoice_id}",
                ];
            },
        ],
    ];
}
```

## Permission System

```php
public static function getAllowedOperations(?int $userId): array
{
    if (!$userId) return ['list'];
    
    $user = User::find($userId);
    $operations = ['list'];
    
    if ($user->can('invoice create')) $operations[] = 'create';
    if ($user->can('invoice edit')) $operations[] = 'update';
    if ($user->can('invoice delete')) $operations[] = 'delete';
    
    return $operations;
}
```

## Migration Path

### Old Approach (Workflow Handler)
```php
// MessageAnalyzer routes CRUD to StartWorkflowHandler
'create' → StartWorkflowHandler → WorkflowDiscovery → "I couldn't understand"
```

### New Approach (Tool-Based)
```php
// MessageAnalyzer routes ALL data operations to AutonomousRAGAgent
'create'/'update'/'delete'/'query' → KnowledgeSearchHandler → AutonomousRAGAgent → model_tool
```

**Key Changes:**
1. ✅ Removed `StartWorkflowHandler` for CRUD operations
2. ✅ Updated `MessageAnalyzer` to route all data operations to `knowledge_search`
3. ✅ `AutonomousRAGAgent` uses `model_tool` for CRUD operations
4. ✅ Single source of truth: `InvoiceModelConfig::getTools()`

### Code Structure

**Old (Separate Configs):**
```php
// InvoiceAutonomousConfig.php - for CREATE only
// InvoiceUpdateConfig.php - for UPDATE only  
// InvoiceDeleteConfig.php - for DELETE only
```

**New (Unified):**
```php
// InvoiceModelConfig.php - ALL operations
public static function getTools(): array {
    return [
        'create_invoice' => [...],
        'update_invoice' => [...],
        'delete_invoice' => [...],
    ];
}
```

## Files Created/Modified

**Created:**
- `/packages/laravel-ai-engine/src/Contracts/AutonomousModelConfig.php`
- `/app/AI/Configs/InvoiceModelConfig.php`

**Modified:**
- `/packages/laravel-ai-engine/src/Services/RAG/AutonomousRAGAgent.php`
  - Added `getToolsForModel()` method
  - Added `findModelConfigClass()` method
  - Added `executeModelTool()` method
  - Updated AI prompt to include `model_tool`
  - Updated `executeTool()` switch statement

## Testing

```bash
# Test invoice operations via AI
curl 'https://middleware.test/ai-demo/chat/send' \
  -H 'authorization: Bearer TOKEN' \
  -H 'content-type: application/json' \
  --data-raw '{
    "message": "mark invoice 217 as paid",
    "session_id": "test-crud",
    "memory": true,
    "actions": true,
    "intelligent_rag": true
  }'
```

## Next Steps

1. Create similar configs for other models (Customer, Product, etc.)
2. Add validation and transformation logic
3. Implement confirmation flow for destructive operations
4. Add audit logging for all CRUD operations
