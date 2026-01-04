# Duplicate Detection System

## Overview

The Duplicate Detection System prevents duplicate record creation by searching for existing records in both vector database and regular database, then asking the user to choose whether to use existing records or create new ones.

## Architecture

### Two Complementary Systems

#### 1. **DuplicateDetectionService** (User-Interactive)
- **When**: During data extraction in `SmartActionService`
- **Purpose**: Search for existing records and ask user before creating
- **User Control**: Full - user chooses existing or creates new
- **Location**: `src/Services/DuplicateDetectionService.php`

#### 2. **AutoResolvesRelationships Trait** (Automatic)
- **When**: During model creation in `executeAI()`
- **Purpose**: Automatically resolve relationships after user confirms
- **User Control**: None - fully automatic
- **Location**: `src/Traits/AutoResolvesRelationships.php`

## How It Works

### Flow Diagram

```
User: "Create invoice for Mohamed - m.abou7agar@gmail.com"
    ↓
SmartActionService extracts data
    ↓
resolveRelationships() called for customer_id
    ↓
DuplicateDetectionService searches:
  - Vector Search (semantic similarity)
  - Database Search (LIKE queries)
    ↓
Found 2 customers:
  1. Mohamed Abou Hagar (95% match) ← Auto-use
  2. Mohamed Ali (72% match)
    ↓
Similarity Check:
  - >90%: Auto-use (no user prompt)
  - 70-90%: Ask user
  - <70%: Create new
    ↓
If 70-90%: Present options to user
"🔍 Found Existing Customer(s):
1. Mohamed Abou Hagar - m.abou7agar@gmail.com (95% match)
2. Mohamed Ali - mohamed.ali@example.com (72% match)

Would you like to:
• Use one of these (reply with number)
• Create new anyway (reply 'new')"
    ↓
User chooses option
    ↓
Data prepared with resolved customer_id
    ↓
Invoice::executeAI() creates invoice
```

## Key Features

### 1. **Dual Search Strategy**

**Vector Search** (Semantic):
- Uses OpenAI embeddings + Qdrant
- Finds semantically similar records
- Example: "John Doe" matches "J. Doe"
- Requires model to use `HasVectorSearch` trait

**Database Search** (Exact/Fuzzy):
- Uses SQL LIKE queries
- Finds exact and partial matches
- Works with any model
- Fallback when vector search unavailable

### 2. **Smart Similarity Scoring**

```php
Similarity >= 90%: Auto-use (high confidence)
Similarity 70-89%: Ask user (medium confidence)
Similarity < 70%:  Create new (low confidence)
```

### 3. **Intelligent Field Detection**

Automatically detects searchable fields:
- Priority: `name`, `email`, `title`, `sku`, `code`, `phone`, `username`
- Falls back to first fillable field
- Email detection: auto-adds email field if value is email

### 4. **User-Friendly Presentation**

```
🔍 Found Existing Customer(s):

1. Mohamed Abou Hagar - m.abou7agar@gmail.com (95% match)
   • Phone: +20 123 456 7890
   • Created: Dec 15, 2024

2. Mohamed Ali - mohamed.ali@example.com (72% match)
   • Phone: +20 987 654 3210
   • Created: Nov 20, 2024

Would you like to:
• Use one of these (reply with number)
• Create new anyway (reply 'new')
```

## Usage

### For Model Developers

#### Enable Vector Search (Recommended)

```php
use LaravelAIEngine\Traits\HasVectorSearch;
use LaravelAIEngine\Traits\Vectorizable;

class Customer extends Model
{
    use HasVectorSearch, Vectorizable;
    
    protected $fillable = ['name', 'email', 'phone', 'address'];
}
```

#### Configure Searchable Fields

```php
class Product extends Model
{
    protected $fillable = ['name', 'sku', 'price', 'description'];
    
    // DuplicateDetectionService will prioritize: name, sku
}
```

#### Customize Search Behavior

```php
class Customer extends Model
{
    // Override searchable fields priority
    public function getSearchableFields(): array
    {
        return ['email', 'phone', 'name']; // Email first
    }
}
```

### For End Users

#### Scenario 1: High Similarity (Auto-Use)

```
User: "Create invoice for John Doe"
AI: [Searches and finds "John Doe" with 95% match]
AI: "Creating invoice for John Doe (john@example.com)..."
[Auto-uses existing customer]
```

#### Scenario 2: Medium Similarity (User Choice)

```
User: "Create invoice for Mohamed"
AI: 🔍 Found Existing Customer(s):
    1. Mohamed Abou Hagar (85% match)
    2. Mohamed Ali (75% match)
    
    Would you like to use one of these or create new?

User: "1"
AI: "Using Mohamed Abou Hagar. Creating invoice..."
```

#### Scenario 3: Low Similarity (Create New)

```
User: "Create invoice for Jane Smith"
AI: [Searches, finds no good matches]
AI: "Creating new customer Jane Smith..."
[Creates new customer automatically]
```

## Configuration

### Similarity Thresholds

```php
// config/ai-engine.php
'duplicate_detection' => [
    'auto_use_threshold' => 0.9,      // 90%+ auto-use
    'ask_user_threshold' => 0.7,      // 70-89% ask user
    'max_results' => 5,                // Show top 5 matches
    'enable_vector_search' => true,    // Use vector search
    'enable_db_search' => true,        // Use database search
],
```

### Per-Model Configuration

```php
class Customer extends Model
{
    public function getDuplicateDetectionConfig(): array
    {
        return [
            'searchable_fields' => ['email', 'phone', 'name'],
            'auto_use_threshold' => 0.95, // Higher threshold for customers
            'create_if_missing' => false,  // Never auto-create customers
        ];
    }
}
```

## API Reference

### DuplicateDetectionService

#### searchExistingRecords()

```php
/**
 * Search for existing records
 * 
 * @param string $modelClass Model to search
 * @param array $extractedData Data extracted from user message
 * @param array $searchableFields Fields to search (optional)
 * @return array Matching records with similarity scores
 */
public function searchExistingRecords(
    string $modelClass, 
    array $extractedData, 
    array $searchableFields = []
): array
```

**Returns:**
```php
[
    [
        'id' => 123,
        'data' => ['name' => 'John Doe', 'email' => 'john@example.com'],
        'similarity' => 0.95,
        'source' => 'vector', // or 'database'
        'model' => Customer {#123}
    ],
    // ...
]
```

#### formatExistingRecordsForUser()

```php
/**
 * Format existing records for user presentation
 * 
 * @param array $existingRecords Records from searchExistingRecords()
 * @param string $modelName Human-readable model name
 * @return string Formatted message for user
 */
public function formatExistingRecordsForUser(
    array $existingRecords, 
    string $modelName
): string
```

## Integration Points

### SmartActionService

```php
protected function findOrCreateRelated(
    string $relatedClass, 
    string $searchValue, 
    $userId = null
): ?int|array
{
    // Uses DuplicateDetectionService
    $existingRecords = $this->duplicateDetectionService->searchExistingRecords(
        $relatedClass,
        $extractedData,
        $searchableFields
    );
    
    // Returns:
    // - int: Direct ID (high similarity or created)
    // - array: Existing records for user choice
    // - null: Failed
}
```

### ChatService

When `_pending_duplicate_choices` is present in action data, ChatService should:
1. Format the duplicate options for user
2. Present them with the confirmation summary
3. Handle user selection (number or "new")
4. Update action data with selected ID

## Performance

### Benchmarks

| Operation | Time | Notes |
|-----------|------|-------|
| Vector Search | 50-100ms | With Qdrant |
| Database Search | 10-30ms | With indexes |
| Combined Search | 60-130ms | Parallel execution |
| Similarity Calculation | <1ms | Per record |

### Optimization Tips

1. **Index searchable fields**:
```sql
CREATE INDEX idx_customers_name ON customers(name);
CREATE INDEX idx_customers_email ON customers(email);
```

2. **Enable vector indexing**:
```bash
php artisan vector:index "App\Models\Customer"
```

3. **Limit search results**:
```php
'max_results' => 5, // Don't overwhelm users
```

## Troubleshooting

### No Duplicates Found (But They Exist)

**Cause**: Model not vectorized or searchable fields not configured

**Solution**:
```php
// 1. Add traits
use HasVectorSearch, Vectorizable;

// 2. Index the model
php artisan vector:index "App\Models\Customer"

// 3. Verify fillable fields
protected $fillable = ['name', 'email', 'phone'];
```

### Too Many False Positives

**Cause**: Similarity threshold too low

**Solution**:
```php
// Increase threshold
'auto_use_threshold' => 0.95, // Instead of 0.9
'ask_user_threshold' => 0.8,  // Instead of 0.7
```

### Vector Search Not Working

**Cause**: Model not indexed or Qdrant not configured

**Solution**:
```bash
# Check vector configuration
php artisan vector:status

# Index the model
php artisan vector:index "App\Models\Customer"

# Verify Qdrant connection
php artisan vector:test-connection
```

## Best Practices

### 1. Always Use Vector Search for Text-Heavy Models

```php
// Good: Customer, Product, Article
use HasVectorSearch, Vectorizable;

// Not needed: Settings, Logs, Metrics
```

### 2. Configure Searchable Fields Explicitly

```php
// Better control over matching
protected $searchableFields = ['email', 'phone', 'name'];
```

### 3. Set Appropriate Thresholds Per Model

```php
// High-value models (customers, orders)
'auto_use_threshold' => 0.95,

// Low-value models (tags, categories)
'auto_use_threshold' => 0.85,
```

### 4. Index Searchable Fields

```sql
-- Always index fields used for searching
CREATE INDEX idx_customers_name ON customers(name);
CREATE INDEX idx_customers_email ON customers(email);
```

### 5. Handle User Selection Gracefully

```php
// Always provide clear options
"Would you like to:
• Use one of these (reply with number)
• Create new anyway (reply 'new')"
```

## Examples

### Example 1: Customer Duplicate Detection

```php
// User input
"Create invoice for Mohamed - m.abou7agar@gmail.com"

// System searches
$results = $duplicateDetectionService->searchExistingRecords(
    Customer::class,
    ['name' => 'Mohamed', 'email' => 'm.abou7agar@gmail.com'],
    ['name', 'email']
);

// Found: Mohamed Abou Hagar (95% match)
// Action: Auto-use (>90%)
// Result: Uses existing customer_id: 123
```

### Example 2: Product Duplicate Detection

```php
// User input
"Add Macbook Pro to invoice"

// System searches
$results = $duplicateDetectionService->searchExistingRecords(
    Product::class,
    ['name' => 'Macbook Pro'],
    ['name', 'sku']
);

// Found:
// 1. MacBook Pro 16" (85% match)
// 2. MacBook Pro 14" (82% match)

// Action: Ask user
// Presents: "Which MacBook Pro? Reply 1 or 2"
```

### Example 3: No Duplicates Found

```php
// User input
"Create invoice for New Customer XYZ"

// System searches
$results = $duplicateDetectionService->searchExistingRecords(
    Customer::class,
    ['name' => 'New Customer XYZ'],
    ['name', 'email']
);

// Found: Nothing (0 results)
// Action: Create new customer
// Result: Creates customer_id: 456
```

## Comparison with AutoResolvesRelationships

| Feature | DuplicateDetectionService | AutoResolvesRelationships |
|---------|---------------------------|---------------------------|
| **When** | During extraction | During model creation |
| **User Control** | Full (asks user) | None (automatic) |
| **Creates Records** | No (only searches) | Yes (if configured) |
| **Prevents Duplicates** | ✅ Yes | ❌ No |
| **Use Case** | User-facing decisions | Background automation |
| **Timing** | Before confirmation | After confirmation |
| **Search Method** | Vector + DB | DB only |
| **Similarity Scoring** | ✅ Yes | ❌ No |

## Future Enhancements

### Planned Features

1. **Machine Learning Similarity**
   - Train custom similarity models per model type
   - Learn from user selections

2. **Batch Duplicate Detection**
   - Detect duplicates across multiple fields at once
   - "Found 3 potential duplicates in your data"

3. **Duplicate Merging**
   - Suggest merging duplicate records
   - "Would you like to merge these 2 customers?"

4. **Smart Defaults**
   - Learn user preferences over time
   - "You usually choose the most recent record"

5. **Cross-Model Duplicate Detection**
   - Detect duplicates across related models
   - "This customer already has an order with this product"

## Conclusion

The Duplicate Detection System provides intelligent, user-friendly duplicate prevention by:
- ✅ Searching both vector and database
- ✅ Calculating similarity scores
- ✅ Asking users when uncertain
- ✅ Auto-using high-confidence matches
- ✅ Creating new records when appropriate

This prevents duplicate records while maintaining user control and providing a smooth conversational experience.
