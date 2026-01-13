# Entity State Management with Enums

Using the `EntityState` enum for better context organization and type safety.

---

## Basic Usage

### Old Way (String-based keys)

```php
// Confusing - what does this mean?
$context->set('missing_products', $missingProducts);
$context->set('customer_id', $customerId);
$context->set('failed_customer', $error);

// Hard to track state
if ($context->has('missing_products')) {
    // Handle missing products
}
```

### New Way (Enum-based)

```php
use LaravelAIEngine\Enums\EntityState;

// Clear and explicit
$context->setEntityState('products', EntityState::MISSING, $missingProducts);
$context->setEntityState('customer', EntityState::RESOLVED, $customerId);
$context->setEntityState('customer', EntityState::FAILED, $error);

// Easy to check state
if ($context->hasEntityState('products', EntityState::MISSING)) {
    // Handle missing products
}

// Get current state
$state = $context->getCurrentEntityState('products');
// Returns: EntityState::MISSING
```

---

## Available States

```php
enum EntityState: string
{
    case RESOLVED = 'resolved';   // Entity found/created successfully
    case MISSING = 'missing';     // Entity not found, needs creation
    case PENDING = 'pending';     // Waiting for user input
    case CREATING = 'creating';   // Currently being created
    case FAILED = 'failed';       // Resolution/creation failed
    case PARTIAL = 'partial';     // Some found, some missing (for arrays)
}
```

---

## Real-World Example: Invoice Workflow

```php
protected function resolveEntities_products(UnifiedActionContext $context, array $identifiers, array $config): ActionResult
{
    $validProducts = [];
    $missingProducts = [];
    
    foreach ($identifiers as $productData) {
        $productName = is_array($productData) ? ($productData['name'] ?? '') : $productData;
        $quantity = is_array($productData) ? ($productData['quantity'] ?? 1) : 1;
        
        $product = ProductService::where('name', 'LIKE', "%{$productName}%")->first();
        
        if ($product) {
            $validProducts[] = [
                'id' => $product->id,
                'name' => $product->name,
                'quantity' => $quantity,
                'price' => $product->sale_price ?? 0,
            ];
        } else {
            $missingProducts[] = $productName;
        }
    }
    
    // Old way
    // $context->set('missing_products', $missingProducts);
    // $context->set('valid_products', $validProducts);
    
    // New way - Clear state management
    if (!empty($missingProducts)) {
        $context->setEntityState('products', EntityState::MISSING, $missingProducts);
        
        if (!empty($validProducts)) {
            // Some found, some missing
            $context->setEntityState('products', EntityState::PARTIAL, $validProducts);
        }
        
        return ActionResult::failure(
            error: 'Products not found: ' . implode(', ', $missingProducts)
        );
    }
    
    // All products found
    $context->setEntityState('products', EntityState::RESOLVED, $validProducts);
    
    return ActionResult::success(
        message: 'Products found: ' . count($validProducts),
        data: ['products' => $validProducts]
    );
}
```

---

## Customer Resolution Example

```php
protected function resolveEntity_customer(UnifiedActionContext $context, string $identifier, array $config): ActionResult
{
    // Mark as pending while searching
    $context->setEntityState('customer', EntityState::PENDING, $identifier);
    
    $customers = Customer::where('workspace', getActiveWorkSpace())
        ->where(function($query) use ($identifier) {
            $query->where('name', 'LIKE', "%{$identifier}%")
                  ->orWhere('email', 'LIKE', "%{$identifier}%")
                  ->orWhere('billing_phone', 'LIKE', "%{$identifier}%");
        })
        ->get();
    
    if ($customers->isEmpty()) {
        // Not found - needs creation
        $context->setEntityState('customer', EntityState::MISSING, $identifier);
        
        return ActionResult::failure(error: 'Customer not found');
    }
    
    $customer = $customers->first();
    
    // Successfully resolved
    $context->setEntityState('customer', EntityState::RESOLVED, $customer->customer_id);
    
    return ActionResult::success(
        message: "Customer found: {$customer->name}",
        data: ['customer' => $customer]
    );
}
```

---

## Checking Entity States

```php
// Check if entity is in a specific state
if ($context->hasEntityState('customer', EntityState::MISSING)) {
    // Start customer creation subworkflow
    return $this->startCustomerCreationSubflow($context);
}

if ($context->hasEntityState('products', EntityState::PARTIAL)) {
    // Some products found, some missing
    $validProducts = $context->getEntityState('products', EntityState::PARTIAL);
    $missingProducts = $context->getEntityState('products', EntityState::MISSING);
    
    return ActionResult::needsUserInput(
        message: "Found {count($validProducts)} products. Missing: " . implode(', ', $missingProducts)
    );
}

// Get current state
$customerState = $context->getCurrentEntityState('customer');

if ($customerState === EntityState::RESOLVED) {
    $customerId = $context->getEntityState('customer', EntityState::RESOLVED);
    // Proceed with invoice creation
}
```

---

## Benefits

### 1. **Type Safety**
```php
// IDE autocomplete and type checking
$context->setEntityState('customer', EntityState::MISSING, $data); // ✅
$context->setEntityState('customer', 'missing', $data); // ❌ Won't work
```

### 2. **Clear Intent**
```php
// Old - What does this mean?
$context->set('missing_products', $products);

// New - Crystal clear
$context->setEntityState('products', EntityState::MISSING, $products);
```

### 3. **State Tracking**
```php
// Automatically tracks state metadata
$state = $context->getCurrentEntityState('customer');
// Returns: EntityState::RESOLVED

// Access metadata
$metadata = $context->get('_entity_states')['customer'];
// Returns: ['state' => 'resolved', 'updated_at' => '2026-01-12T02:10:00Z']
```

### 4. **Consistent Keys**
```php
// Enum generates consistent keys
EntityState::MISSING->getKey('products');  // "missing_products"
EntityState::RESOLVED->getKey('customer'); // "customer_id"
EntityState::FAILED->getKey('order');      // "failed_order"
```

---

## Migration Guide

### Step 1: Update Workflow Methods

**Before:**
```php
$context->set('missing_products', $missingProducts);
$context->set('customer_id', $customerId);
```

**After:**
```php
use LaravelAIEngine\Enums\EntityState;

$context->setEntityState('products', EntityState::MISSING, $missingProducts);
$context->setEntityState('customer', EntityState::RESOLVED, $customerId);
```

### Step 2: Update State Checks

**Before:**
```php
if ($context->has('missing_products')) {
    $missing = $context->get('missing_products');
}
```

**After:**
```php
if ($context->hasEntityState('products', EntityState::MISSING)) {
    $missing = $context->getEntityState('products', EntityState::MISSING);
}
```

### Step 3: Update Final Actions

**Before:**
```php
protected function createInvoice(UnifiedActionContext $context): ActionResult
{
    $customerId = $context->get('customer_id');
    $products = $context->get('valid_products');
    
    // ...
}
```

**After:**
```php
protected function createInvoice(UnifiedActionContext $context): ActionResult
{
    $customerId = $context->getEntityState('customer', EntityState::RESOLVED);
    $products = $context->getEntityState('products', EntityState::RESOLVED);
    
    // Or check state first
    if (!$context->hasEntityState('customer', EntityState::RESOLVED)) {
        return ActionResult::failure(error: 'Customer not resolved');
    }
    
    // ...
}
```

---

## Advanced: Custom State Logic

```php
protected function handleEntityState(UnifiedActionContext $context, string $entity): ActionResult
{
    $state = $context->getCurrentEntityState($entity);
    
    return match($state) {
        EntityState::RESOLVED => ActionResult::success(
            message: "{$entity} is ready"
        ),
        
        EntityState::MISSING => $this->startCreationSubflow($context, $entity),
        
        EntityState::PENDING => ActionResult::needsUserInput(
            message: "Waiting for {$entity} information"
        ),
        
        EntityState::CREATING => ActionResult::needsUserInput(
            message: "Creating {$entity}..."
        ),
        
        EntityState::FAILED => ActionResult::failure(
            error: "{$entity} resolution failed"
        ),
        
        EntityState::PARTIAL => $this->handlePartialResolution($context, $entity),
        
        default => ActionResult::failure(
            error: "Unknown state for {$entity}"
        ),
    };
}
```

---

## Debugging

```php
// Get all entity states
$allStates = $context->get('_entity_states');

foreach ($allStates as $entity => $metadata) {
    echo "{$entity}: {$metadata['state']} (updated: {$metadata['updated_at']})\n";
}

// Output:
// customer: resolved (updated: 2026-01-12T02:10:00Z)
// products: partial (updated: 2026-01-12T02:10:05Z)
```

---

## Best Practices

1. **Always use enum for entity states**
   ```php
   // ✅ Good
   $context->setEntityState('customer', EntityState::RESOLVED, $id);
   
   // ❌ Bad
   $context->set('customer_id', $id);
   ```

2. **Check state before accessing data**
   ```php
   // ✅ Good
   if ($context->hasEntityState('customer', EntityState::RESOLVED)) {
       $id = $context->getEntityState('customer', EntityState::RESOLVED);
   }
   
   // ❌ Bad
   $id = $context->get('customer_id'); // Might not exist
   ```

3. **Use match expressions for state handling**
   ```php
   // ✅ Good - Exhaustive and type-safe
   return match($context->getCurrentEntityState('customer')) {
       EntityState::RESOLVED => $this->proceed(),
       EntityState::MISSING => $this->createCustomer(),
       // ...
   };
   ```

4. **Track state transitions**
   ```php
   // Mark as creating before starting subflow
   $context->setEntityState('customer', EntityState::CREATING, $data);
   
   // Start subflow
   $result = $this->startSubflow(...);
   
   // Update state based on result
   if ($result->success) {
       $context->setEntityState('customer', EntityState::RESOLVED, $result->data['customer_id']);
   } else {
       $context->setEntityState('customer', EntityState::FAILED, $result->error);
   }
   ```

---

**This pattern makes your workflow code more maintainable, type-safe, and easier to debug!**
