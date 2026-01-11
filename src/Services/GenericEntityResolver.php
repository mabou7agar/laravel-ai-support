<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use Illuminate\Support\Facades\Log;

/**
 * Generic Entity Resolver - Driven by aiConfig definitions
 * 
 * Resolves entities (find or create) based on model's aiConfig,
 * eliminating the need for custom resolver implementations.
 * 
 * Usage in aiConfig:
 * ->entityField('customer_id', [
 *     'model' => Customer::class,
 *     'search_fields' => ['name', 'email', 'contact'],
 *     'create_fields' => ['name', 'email', 'phone'],
 *     'interactive' => true, // Ask user for missing data
 * ])
 */
class GenericEntityResolver
{
    /**
     * Resolve a single entity based on aiConfig definition
     * 
     * Can be overridden by specifying custom resolver in config:
     * 'resolver' => CustomResolver::class
     * 
     * Config options:
     * - 'check_duplicates' => true: Check for similar entities before creating
     * - 'ask_on_duplicate' => true: Ask user to choose existing or create new
     */
    public function resolveEntity(
        string $fieldName,
        array $config,
        $identifier,
        UnifiedActionContext $context
    ): ActionResult {
        // Check if custom resolver is specified
        if (isset($config['resolver']) && $config['resolver'] !== 'GenericEntityResolver') {
            return $this->delegateToCustomResolver($config['resolver'], $fieldName, $config, $identifier, $context, false);
        }
        
        $modelClass = $config['model'] ?? null;
        $searchFields = $config['search_fields'] ?? ['name'];
        $interactive = $config['interactive'] ?? true;
        $filters = $config['filters'] ?? null;
        $checkDuplicates = $config['check_duplicates'] ?? false;
        $askOnDuplicate = $config['ask_on_duplicate'] ?? false;
        
        if (!$modelClass) {
            return ActionResult::failure(error: "No model class specified for {$fieldName}");
        }
        
        // Check if entity model has a workflow defined in its aiConfig
        if (class_exists($modelClass)) {
            try {
                $reflection = new \ReflectionClass($modelClass);
                if ($reflection->hasMethod('initializeAI')) {
                    $method = $reflection->getMethod('initializeAI');
                    $entityAiConfig = $method->isStatic() 
                        ? $modelClass::initializeAI() 
                        : (new $modelClass())->initializeAI();
                    
                    // If entity has a workflow, delegate to it
                    if (isset($entityAiConfig['workflow']) && class_exists($entityAiConfig['workflow'])) {
                        $workflowClass = $entityAiConfig['workflow'];
                        
                        Log::channel('ai-engine')->info('Entity has workflow, delegating', [
                            'field' => $fieldName,
                            'model' => $modelClass,
                            'workflow' => $workflowClass,
                        ]);
                        
                        // Start the workflow with the identifier as initial data
                        $ai = app(\LaravelAIEngine\Services\AI\AIService::class);
                        $tools = app(\LaravelAIEngine\Services\Agent\ToolRegistry::class);
                        $workflow = new $workflowClass($ai, $tools);
                        
                        // Set initial data in context
                        $context->set('customer_name', $identifier);
                        
                        // Execute workflow
                        return $workflow->execute($context);
                    }
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to check entity workflow', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Handle duplicate choice if pending
        if ($context->get("{$fieldName}_duplicate_choice_pending")) {
            return $this->handleDuplicateChoice($fieldName, $config, $context);
        }
        
        // Check if we're in the middle of creation
        $creationStep = $context->get("{$fieldName}_creation_step");
        if ($creationStep) {
            return $this->continueEntityCreation($fieldName, $config, $context);
        }
        
        // Check for duplicates if configured (before exact search)
        if ($checkDuplicates && $askOnDuplicate) {
            // Store identifier for later use
            $context->set("{$fieldName}_identifier", $identifier);
            
            $duplicates = $this->findSimilarEntities($modelClass, $identifier, $searchFields, $filters);
            
            if ($duplicates->isNotEmpty()) {
                // Check if any is an exact match
                $exactMatch = $duplicates->first(function($entity) use ($searchFields, $identifier) {
                    foreach ($searchFields as $field) {
                        if (isset($entity->$field) && strcasecmp($entity->$field, $identifier) === 0) {
                            return true;
                        }
                    }
                    return false;
                });
                
                if ($exactMatch) {
                    // Exact match found - use it directly
                    Log::channel('ai-engine')->info('Exact entity match found', [
                        'field' => $fieldName,
                        'model' => $modelClass,
                        'id' => $exactMatch->id,
                    ]);
                    
                    return ActionResult::success(
                        message: "Found existing " . class_basename($modelClass),
                        data: [$fieldName => $exactMatch->id, 'entity' => $exactMatch]
                    );
                }
                
                // Similar matches found - ask user
                return $this->askAboutDuplicates($fieldName, $duplicates, $context);
            }
        } else {
            // No duplicate checking - do regular exact search
            $entity = $this->searchEntity($modelClass, $identifier, $searchFields, $filters);
            
            if ($entity) {
                Log::channel('ai-engine')->info('Entity found', [
                    'field' => $fieldName,
                    'model' => $modelClass,
                    'id' => $entity->id,
                ]);
                
                return ActionResult::success(
                    message: "Found existing " . class_basename($modelClass),
                    data: [$fieldName => $entity->id, 'entity' => $entity]
                );
            }
        }
        
        // No entity found and no duplicates - create if configured
        if ($interactive) {
            return $this->startInteractiveCreation($fieldName, $config, $identifier, $context);
        } else {
            return $this->createEntityAuto($fieldName, $config, $identifier, $context);
        }
    }
    
    /**
     * Delegate to custom resolver - auto-detects appropriate method
     */
    private function delegateToCustomResolver(
        string $resolverClass,
        string $fieldName,
        array $config,
        $data,
        UnifiedActionContext $context,
        bool $isMultiple
    ): ActionResult {
        if (!class_exists($resolverClass)) {
            return ActionResult::failure(error: "Custom resolver class not found: {$resolverClass}");
        }
        
        $resolver = app($resolverClass);
        
        // Try to find appropriate method
        $methods = [
            // Generic methods
            'resolve',
            'resolveEntity',
            'resolveEntities',
            // Specific methods based on field name
            'resolve' . ucfirst($fieldName),
            // Legacy methods
            'resolveCustomer',
            'resolveProducts',
        ];
        
        foreach ($methods as $method) {
            if (method_exists($resolver, $method)) {
                try {
                    if ($isMultiple) {
                        // For multiple entities, pass array
                        return $resolver->$method($data, $context);
                    } else {
                        // For single entity, check method signature
                        $reflection = new \ReflectionMethod($resolver, $method);
                        $params = $reflection->getParameters();
                        
                        if (count($params) >= 3) {
                            // Method expects (fieldName, config, identifier, context)
                            return $resolver->$method($fieldName, $config, $data, $context);
                        } else {
                            // Method expects (identifier, context)
                            return $resolver->$method($data, $context);
                        }
                    }
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->warning('Custom resolver method failed', [
                        'resolver' => $resolverClass,
                        'method' => $method,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }
        
        return ActionResult::failure(
            error: "No suitable method found in custom resolver: {$resolverClass}"
        );
    }
    
    /**
     * Resolve multiple entities (e.g., products in invoice)
     */
    public function resolveEntities(
        string $fieldName,
        array $config,
        array $items,
        UnifiedActionContext $context
    ): ActionResult {
        // Check if custom resolver is specified
        if (isset($config['resolver']) && $config['resolver'] !== 'GenericEntityResolver') {
            return $this->delegateToCustomResolver($config['resolver'], $fieldName, $config, $items, $context, true);
        }
        
        $modelClass = $config['model'] ?? null;
        $searchFields = $config['search_fields'] ?? ['name'];
        $interactive = $config['interactive'] ?? true;
        $filters = $config['filters'] ?? null;
        
        if (!$modelClass) {
            return ActionResult::failure(error: "No model class specified for {$fieldName}");
        }
        
        // Check if we're in the middle of creation
        $creationStep = $context->get("{$fieldName}_creation_step");
        if ($creationStep) {
            $missing = $context->get("{$fieldName}_missing", []);
            return $this->continueEntitiesCreation($fieldName, $config, $missing, $context);
        }
        
        $validated = [];
        $missing = [];
        
        foreach ($items as $item) {
            $identifier = $item['name'] ?? $item['item'] ?? '';
            $quantity = $item['quantity'] ?? 1;
            
            // Search for existing
            $entity = $this->searchEntity($modelClass, $identifier, $searchFields, $filters);
            
            if ($entity) {
                $validated[] = array_merge([
                    'id' => $entity->id,
                    'name' => $entity->name ?? $identifier,
                    'quantity' => $quantity,
                ], $item);
            } else {
                $missing[] = array_merge([
                    'name' => $identifier,
                    'quantity' => $quantity,
                ], $item);
            }
        }
        
        if (empty($missing)) {
            $context->set($fieldName, $validated);
            return ActionResult::success(
                message: 'All entities found',
                data: [$fieldName => $validated]
            );
        }
        
        // Some missing - create them
        $context->set("{$fieldName}_validated", $validated);
        $context->set("{$fieldName}_missing", $missing);
        
        if ($interactive) {
            return $this->startInteractiveEntitiesCreation($fieldName, $config, $missing, $context);
        } else {
            return $this->createEntitiesAuto($fieldName, $config, $missing, $context);
        }
    }
    
    /**
     * Search for entity in database with custom filters
     */
    private function searchEntity(string $modelClass, $identifier, array $searchFields, $filters = null)
    {
        $query = $modelClass::query();
        
        // Apply filters
        if ($filters) {
            if (is_callable($filters)) {
                $filters($query);
            } elseif (is_array($filters)) {
                foreach ($filters as $field => $value) {
                    $query->where($field, $value);
                }
            }
        }
        
        // Search across all search fields
        $query->where(function($q) use ($searchFields, $identifier) {
            foreach ($searchFields as $field) {
                $q->orWhere($field, 'LIKE', "%{$identifier}%");
            }
        });
        
        return $query->first();
    }
    
    /**
     * Find similar entities for duplicate detection
     */
    private function findSimilarEntities(string $modelClass, $identifier, array $searchFields, $filters = null)
    {
        $query = $modelClass::query();
        
        // Apply filters
        if ($filters) {
            if (is_callable($filters)) {
                $filters($query);
            } elseif (is_array($filters)) {
                foreach ($filters as $field => $value) {
                    $query->where($field, $value);
                }
            }
        }
        
        // Search for similar entities
        $query->where(function($q) use ($searchFields, $identifier) {
            foreach ($searchFields as $field) {
                $q->orWhere($field, 'LIKE', "%{$identifier}%");
            }
        });
        
        return $query->limit(5)->get();
    }
    
    /**
     * Ask user about duplicate entities
     */
    private function askAboutDuplicates(string $fieldName, $duplicates, UnifiedActionContext $context): ActionResult
    {
        $entityName = class_basename($duplicates->first());
        
        if ($duplicates->count() === 1) {
            $entity = $duplicates->first();
            $context->set("{$fieldName}_found_duplicate", $entity);
            
            $message = "Found existing {$entityName}: **{$entity->name}**";
            
            // Add additional identifying info if available
            if (isset($entity->email) && $entity->email) {
                $message .= " ({$entity->email})";
            } elseif (isset($entity->contact) && $entity->contact) {
                $message .= " ({$entity->contact})";
            }
            
            $message .= "\n\nWould you like to:\n";
            $message .= "1. Use this {$entityName} (reply 'use' or 'yes')\n";
            $message .= "2. Create a new {$entityName} (reply 'new' or 'create')";
            
            $context->set("{$fieldName}_duplicate_choice_pending", true);
            return ActionResult::needsUserInput(message: $message);
        }
        
        // Multiple matches
        $context->set("{$fieldName}_found_duplicates", $duplicates);
        
        $message = "Found {$duplicates->count()} similar {$entityName}s:\n\n";
        foreach ($duplicates as $index => $entity) {
            $message .= ($index + 1) . ". **{$entity->name}**";
            
            if (isset($entity->email) && $entity->email) {
                $message .= " ({$entity->email})";
            } elseif (isset($entity->contact) && $entity->contact) {
                $message .= " ({$entity->contact})";
            }
            
            $message .= "\n";
        }
        
        $message .= "\nWould you like to:\n";
        $message .= "- Use one of these (reply with number 1-{$duplicates->count()})\n";
        $message .= "- Create a new {$entityName} (reply 'new' or 'create')";
        
        $context->set("{$fieldName}_duplicate_choice_pending", true);
        return ActionResult::needsUserInput(message: $message);
    }
    
    /**
     * Handle user's choice about duplicate entities
     */
    private function handleDuplicateChoice(string $fieldName, array $config, UnifiedActionContext $context): ActionResult
    {
        $lastMessage = $context->lastUserMessage ?? '';
        $response = strtolower(trim($lastMessage));
        
        // User wants to use existing entity
        if (str_contains($response, 'use') || str_contains($response, 'yes') || $response === '1') {
            $entity = $context->get("{$fieldName}_found_duplicate");
            if ($entity) {
                $context->forget("{$fieldName}_duplicate_choice_pending");
                $context->forget("{$fieldName}_found_duplicate");
                $context->forget("{$fieldName}_found_duplicates");
                
                return ActionResult::success(
                    message: "Using existing " . class_basename($entity) . ": {$entity->name}",
                    data: [$fieldName => $entity->id, 'entity' => $entity]
                );
            }
        }
        
        // User selected a number from multiple options
        if (is_numeric($response)) {
            $duplicates = $context->get("{$fieldName}_found_duplicates");
            $index = (int)$response - 1;
            
            if ($duplicates && isset($duplicates[$index])) {
                $entity = $duplicates[$index];
                $context->forget("{$fieldName}_duplicate_choice_pending");
                $context->forget("{$fieldName}_found_duplicate");
                $context->forget("{$fieldName}_found_duplicates");
                
                return ActionResult::success(
                    message: "Using existing " . class_basename($entity) . ": {$entity->name}",
                    data: [$fieldName => $entity->id, 'entity' => $entity]
                );
            }
        }
        
        // User wants to create new
        if (str_contains($response, 'new') || str_contains($response, 'create')) {
            $context->forget("{$fieldName}_duplicate_choice_pending");
            $context->forget("{$fieldName}_found_duplicate");
            $context->forget("{$fieldName}_found_duplicates");
            
            // Continue with creation
            $identifier = $context->get("{$fieldName}_identifier", '');
            return $this->startInteractiveCreation($fieldName, $config, $identifier, $context);
        }
        
        // Invalid response
        return ActionResult::needsUserInput(
            message: "Please reply with 'use', 'new', or a number to select an entity."
        );
    }
    
    /**
     * Start interactive entity creation
     */
    private function startInteractiveCreation(
        string $fieldName,
        array $config,
        $identifier,
        UnifiedActionContext $context
    ): ActionResult {
        $entityName = class_basename($config['model']);
        $context->set("{$fieldName}_creation_step", 'ask_create');
        $context->set("{$fieldName}_identifier", $identifier);
        
        return ActionResult::needsUserInput(
            message: "{$entityName} '{$identifier}' doesn't exist. Would you like to create it? (yes/no)"
        );
    }
    
    /**
     * Continue interactive entity creation
     */
    private function continueEntityCreation(
        string $fieldName,
        array $config,
        UnifiedActionContext $context
    ): ActionResult {
        $step = $context->get("{$fieldName}_creation_step");
        $createFields = $config['create_fields'] ?? [];
        $modelClass = $config['model'];
        
        // Implementation would handle each field collection step
        // For now, return a simplified version
        
        return ActionResult::needsUserInput(
            message: "Please provide additional information for " . class_basename($modelClass)
        );
    }
    
    /**
     * Create entity automatically (non-interactive)
     */
    private function createEntityAuto(
        string $fieldName,
        array $config,
        $identifier,
        UnifiedActionContext $context
    ): ActionResult {
        $modelClass = $config['model'];
        $defaults = $config['defaults'] ?? [];
        
        try {
            $data = array_merge([
                'name' => $identifier,
                'workspace' => getActiveWorkSpace() ?: 1,
                'created_by' => auth()->id() ?? 1,
            ], $defaults);
            
            $entity = $modelClass::create($data);
            
            return ActionResult::success(
                message: class_basename($modelClass) . " created",
                data: [$fieldName => $entity->id, 'entity' => $entity]
            );
        } catch (\Exception $e) {
            return ActionResult::failure(
                error: "Failed to create " . class_basename($modelClass) . ": " . $e->getMessage()
            );
        }
    }
    
    /**
     * Start interactive entities creation (multiple)
     */
    private function startInteractiveEntitiesCreation(
        string $fieldName,
        array $config,
        array $missing,
        UnifiedActionContext $context
    ): ActionResult {
        $entityName = class_basename($config['model']);
        $context->set("{$fieldName}_creation_step", 'ask_create');
        $context->set("{$fieldName}_creation_index", 0);
        
        $message = "The following {$entityName}s don't exist:\n\n";
        foreach ($missing as $item) {
            $message .= "• {$item['name']}";
            if (isset($item['quantity'])) {
                $message .= " (qty: {$item['quantity']})";
            }
            $message .= "\n";
        }
        $message .= "\nWould you like to create them? (yes/no)";
        
        return ActionResult::needsUserInput(message: $message);
    }
    
    /**
     * Continue interactive entities creation
     */
    private function continueEntitiesCreation(
        string $fieldName,
        array $config,
        array $missing,
        UnifiedActionContext $context
    ): ActionResult {
        $step = $context->get("{$fieldName}_creation_step");
        $index = $context->get("{$fieldName}_creation_index", 0);
        
        // If user just confirmed to create all products
        if ($step === 'ask_create') {
            // Move to first product creation
            $context->set("{$fieldName}_creation_step", 'create_product');
            $context->set("{$fieldName}_creation_index", 0);
            return $this->askForProductDetails($fieldName, $config, $missing, 0, $context);
        }
        
        // If we're creating products, handle the response
        if ($step === 'create_product') {
            // Product details were provided, create it
            $currentProduct = $missing[$index] ?? null;
            if (!$currentProduct) {
                return ActionResult::failure(error: "Product not found at index {$index}");
            }
            
            // Create the product (this would be handled by ProductCreationService)
            // For now, just move to next product
            $nextIndex = $index + 1;
            
            if ($nextIndex < count($missing)) {
                // More products to create
                $context->set("{$fieldName}_creation_index", $nextIndex);
                return $this->askForProductDetails($fieldName, $config, $missing, $nextIndex, $context);
            } else {
                // All products created, merge with validated
                $validated = $context->get("{$fieldName}_validated", []);
                $allProducts = array_merge($validated, $missing);
                $context->set($fieldName, $allProducts);
                
                // Clear creation state
                $context->forget("{$fieldName}_creation_step");
                $context->forget("{$fieldName}_creation_index");
                $context->forget("{$fieldName}_missing");
                $context->forget("{$fieldName}_validated");
                
                return ActionResult::success(
                    message: "All products created",
                    data: [$fieldName => $allProducts]
                );
            }
        }
        
        return ActionResult::failure(error: "Unknown creation step: {$step}");
    }
    
    /**
     * Ask for product details during creation
     */
    private function askForProductDetails(
        string $fieldName,
        array $config,
        array $missing,
        int $index,
        UnifiedActionContext $context
    ): ActionResult {
        $product = $missing[$index];
        $entityName = class_basename($config['model']);
        
        $message = "Product: {$product['name']}\n";
        $message .= "Category: General\n\n"; // TODO: AI category suggestion
        $message .= "Please provide pricing:\n";
        $message .= "Format: 'sale price X, purchase price Y'\n";
        $message .= "Example: 'sale price 150, purchase price 100'";
        
        return ActionResult::needsUserInput(message: $message);
    }
    
    /**
     * Create entities automatically (non-interactive)
     */
    private function createEntitiesAuto(
        string $fieldName,
        array $config,
        array $missing,
        UnifiedActionContext $context
    ): ActionResult {
        $modelClass = $config['model'];
        $created = [];
        
        foreach ($missing as $item) {
            try {
                $data = array_merge([
                    'name' => $item['name'],
                    'workspace' => getActiveWorkSpace() ?: 1,
                    'created_by' => auth()->id() ?? 1,
                ], $config['defaults'] ?? []);
                
                $entity = $modelClass::create($data);
                $created[] = array_merge([
                    'id' => $entity->id,
                    'name' => $entity->name,
                ], $item);
            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('Failed to create entity', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $validated = $context->get("{$fieldName}_validated", []);
        $all = array_merge($validated, $created);
        $context->set($fieldName, $all);
        
        return ActionResult::success(
            message: count($created) . " " . class_basename($modelClass) . "(s) created",
            data: [$fieldName => $all]
        );
    }
}
