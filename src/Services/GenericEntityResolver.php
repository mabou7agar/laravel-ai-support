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
 * SUBWORKFLOW ISOLATION:
 * Subworkflows now use isolated contexts to prevent data pollution.
 * When a subworkflow is executed, it receives a clean context with only
 * the data it needs, and returns only the final result (not intermediate state).
 *
 * Example flow:
 * 1. Parent workflow needs an entity
 * 2. Creates isolated subcontext with entity identifier
 * 3. Executes entity creation subworkflow with isolated context
 * 4. Subworkflow collects required fields in its own context
 * 5. Returns only the created entity
 * 6. Parent merges only the result, not intermediate data
 *
 * This prevents issues like:
 * - Parent workflow seeing subworkflow's intermediate steps
 * - Subworkflow data leaking into parent context
 * - Context pollution between workflows
 *
 * Usage in aiConfig:
 * ->entityField('entity_field', [
 *     'model' => EntityModel::class,
 *     'search_fields' => ['name', 'identifier'],
 *     'subflow' => EntityWorkflow::class,
 *     'interactive' => true,
 * ])
 */
class GenericEntityResolver
{
    protected $ai;
    protected $intelligentService;
    protected $aiEnhanced;

    public function __construct(AIEngineService $ai, IntelligentEntityService $intelligentService)
    {
        $this->ai = $ai;
        $this->intelligentService = $intelligentService;
        $this->aiEnhanced = app(\LaravelAIEngine\Services\AIEnhancedWorkflowService::class);
    }

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
        try {
            Log::channel('ai-engine')->info('GenericEntityResolver::resolveEntity called', [
                'field' => $fieldName,
                'identifier' => $identifier,
                'has_creation_step' => !empty($context->get("{$fieldName}_creation_step")),
            ]);

            Log::channel('ai-engine')->info('resolveEntity: Inside try block', [
                'field' => $fieldName,
            ]);

            // Check if custom resolver is specified
            if (isset($config['resolver']) && $config['resolver'] !== 'GenericEntityResolver') {
                Log::channel('ai-engine')->info('resolveEntity: Delegating to custom resolver', [
                    'resolver' => $config['resolver'],
                ]);
                return $this->delegateToCustomResolver($config['resolver'], $fieldName, $config, $identifier, $context, false);
            }

        Log::channel('ai-engine')->info('resolveEntity: Getting model class', [
            'field' => $fieldName,
            'has_model' => isset($config['model']),
        ]);

        $modelClass = $config['model'] ?? null;

        Log::channel('ai-engine')->info('resolveEntity: Extracting config', [
            'field' => $fieldName,
            'model' => $modelClass,
        ]);

        $searchFields = $config['search_fields'] ?? ['name'];
        $interactive = $config['interactive'] ?? true;
        $confirmBeforeCreate = $config['confirm_before_create'] ?? false;
        $filters = $config['filters'] ?? null;
        $checkDuplicates = $config['check_duplicates'] ?? false;
        $askOnDuplicate = $config['ask_on_duplicate'] ?? false;

        Log::channel('ai-engine')->info('resolveEntity: Config extracted', [
            'field' => $fieldName,
            'has_filters' => !is_null($filters),
            'interactive' => $interactive,
        ]);

        if (!$modelClass) {
            return ActionResult::failure(error: "No model class specified for {$fieldName}");
        }

        // Handle identifier as object (e.g., {name: "John", email: "john@test.com"})
        $identifierData = [];
        if (is_array($identifier)) {
            $identifierData = $identifier;
            // Use search_fields from config to determine primary search field, fallback to first available
            $searchIdentifier = null;
            foreach ($searchFields as $searchField) {
                if (isset($identifier[$searchField]) && !empty($identifier[$searchField])) {
                    $searchIdentifier = $identifier[$searchField];
                    break;
                }
            }
            // If no search field matched, use first non-empty value
            if ($searchIdentifier === null) {
                $searchIdentifier = reset($identifier) ?: '';
            }
            $identifier = $searchIdentifier;
            
            Log::channel('ai-engine')->info('resolveEntity: Identifier is object, extracted search value', [
                'field' => $fieldName,
                'identifier_data' => $identifierData,
                'search_fields' => $searchFields,
                'search_identifier' => $identifier,
            ]);
            
            // Store full identifier data for later use in subflow
            // Only set if not already stored (preserve original extraction)
            $existingExtractedData = $context->get("{$fieldName}_extracted_data", []);
            if (empty($existingExtractedData)) {
                $context->set("{$fieldName}_extracted_data", $identifierData);
            } else {
                // Merge new data with existing, but don't overwrite existing values
                foreach ($identifierData as $key => $value) {
                    if (!isset($existingExtractedData[$key]) && !empty($value)) {
                        $existingExtractedData[$key] = $value;
                    }
                }
                $context->set("{$fieldName}_extracted_data", $existingExtractedData);
            }
        }

        Log::channel('ai-engine')->info('resolveEntity: Checking duplicate choice', [
            'field' => $fieldName,
        ]);

        // Handle duplicate choice if pending
        if ($context->get("{$fieldName}_duplicate_choice_pending")) {
            return $this->handleDuplicateChoice($fieldName, $config, $context);
        }

        Log::channel('ai-engine')->info('resolveEntity: Checking creation step', [
            'field' => $fieldName,
            'creation_step_key' => "{$fieldName}_creation_step",
            'all_context_keys' => array_keys($context->workflowState ?? []),
        ]);

        // Check if we're in the middle of creation
        $creationStep = $context->get("{$fieldName}_creation_step");

        Log::channel('ai-engine')->info('resolveEntity: Creation step check result', [
            'field' => $fieldName,
            'creation_step_value' => $creationStep,
            'has_creation_step' => !empty($creationStep),
        ]);

        if ($creationStep) {
            Log::channel('ai-engine')->info('🔄 Continuing entity creation', [
                'field' => $fieldName,
                'creation_step' => $creationStep,
            ]);
            return $this->continueEntityCreation($fieldName, $config, $context);
        }

        Log::channel('ai-engine')->info('resolveEntity: About to check duplicates', [
            'field' => $fieldName,
            'checkDuplicates' => $checkDuplicates,
            'askOnDuplicate' => $askOnDuplicate,
        ]);

        // Check for duplicates if configured (before exact search)
        if ($checkDuplicates && $askOnDuplicate) {
            // Store identifier for later use
            $context->set("{$fieldName}_identifier", $identifier);

            Log::channel('ai-engine')->info('Searching for similar entities', [
                'field' => $fieldName,
                'model' => $modelClass,
                'identifier' => $identifier,
                'search_fields' => $searchFields,
                'has_filters' => !empty($filters),
            ]);

            $duplicates = $this->findSimilarEntities($modelClass, $identifier, $searchFields, $filters);

            Log::channel('ai-engine')->info('Similar entities search result', [
                'field' => $fieldName,
                'count' => $duplicates->count(),
                'entities' => $duplicates->map(fn($e) => ['id' => $e->id, 'name' => $e->name ?? 'N/A'])->toArray(),
            ]);

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
                return $this->askAboutDuplicates($fieldName, $duplicates, $context, $config);
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

                // Store full entity data if includeFields is specified
                $includeFields = $config['include_fields'] ?? [];
                if (!empty($includeFields)) {
                    $collectedData = $context->get('collected_data', []);
                    $entityData = ['id' => $entity->id];
                    
                    foreach ($includeFields as $field) {
                        if (isset($entity->$field)) {
                            $entityData[$field] = $entity->$field;
                        }
                    }
                    
                    $collectedData[$fieldName] = $entityData;
                    $context->set('collected_data', $collectedData);
                    
                    Log::channel('ai-engine')->info('Stored full entity data in GenericEntityResolver', [
                        'field' => $fieldName,
                        'entity_id' => $entity->id,
                        'include_fields' => $includeFields,
                        'stored_data' => $entityData,
                    ]);
                }

                return ActionResult::success(
                    message: "Found existing " . class_basename($modelClass),
                    data: [$fieldName => $entity->id, 'entity' => $entity]
                );
            }
        }

        // No entity found and no duplicates - create if configured
        // If confirmBeforeCreate is true, always ask for confirmation
        Log::channel('ai-engine')->info('Entity not found, starting creation flow', [
            'field' => $fieldName,
            'identifier' => $identifier,
            'confirmBeforeCreate' => $confirmBeforeCreate,
            'interactive' => $interactive,
            'has_subflow' => !empty($config['subflow']),
        ]);

        if ($confirmBeforeCreate || $interactive) {
            $result = $this->startInteractiveCreation($fieldName, $config, $identifier, $context);

            Log::channel('ai-engine')->info('startInteractiveCreation returned', [
                'field' => $fieldName,
                'success' => $result->success,
                'needs_user_input' => $result->metadata['needs_user_input'] ?? false,
                'message' => substr($result->message ?? '', 0, 100),
            ]);

            return $result;
        } else {
            return $this->createEntityAuto($fieldName, $config, $identifier, $context);
        }

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Exception in resolveEntity', [
                'field' => $fieldName,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return user input request instead of failure to prevent workflow termination
            return ActionResult::needsUserInput(
                message: "Unable to resolve {$fieldName}. Error: {$e->getMessage()}\n\nWould you like to try again?",
                metadata: ['error' => $e->getMessage(), 'field' => $fieldName]
            );
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
        // IMPORTANT: Restore parent collected_data if it was preserved during subflow
        // This ensures the items array is available after a product subflow completes
        $parentCollectedData = $context->get('parent_collected_data');
        if ($parentCollectedData && (!$items || count($items) === 0)) {
            $context->set('collected_data', $parentCollectedData);
            Log::channel('ai-engine')->info('Restored parent collected_data at start of resolveEntities', [
                'field' => $fieldName,
                'has_items' => isset($parentCollectedData['items']),
                'parent_items_count' => isset($parentCollectedData['items']) ? count($parentCollectedData['items']) : 0,
            ]);

            // Re-extract items from restored data
            $identifierField = $config['identifier_field'] ?? $fieldName;
            $collectedData = $context->get('collected_data', []);
            $items = $collectedData[$identifierField] ?? [];

            Log::channel('ai-engine')->info('Re-extracted items from parent data', [
                'field' => $fieldName,
                'items_count' => count($items),
            ]);
        }

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

        // Check if a subflow just completed for this field
        $activeSubflow = $context->get('active_subflow');

        Log::channel('ai-engine')->info('Checking for active subflow', [
            'field' => $fieldName,
            'has_active_subflow' => !empty($activeSubflow),
            'active_subflow_field' => $activeSubflow['field_name'] ?? null,
            'matches_field' => $activeSubflow && ($activeSubflow['field_name'] ?? null) === $fieldName,
        ]);

        if ($activeSubflow && $activeSubflow['field_name'] === $fieldName) {
            $stepPrefix = $activeSubflow['step_prefix'] ?? '';
            $currentStep = $context->currentStep ?? '';

            Log::channel('ai-engine')->info('Active subflow detected, checking completion', [
                'field' => $fieldName,
                'step_prefix' => $stepPrefix,
                'current_step' => $currentStep,
                'has_prefix' => $stepPrefix && $currentStep && str_starts_with($currentStep, $stepPrefix),
                'subflow_completed' => $stepPrefix && $currentStep && !str_starts_with($currentStep, $stepPrefix),
            ]);

            // If current step doesn't have the prefix, subflow completed
            if ($stepPrefix && $currentStep && !str_starts_with($currentStep, $stepPrefix)) {
                Log::channel('ai-engine')->info('Product subflow completed, checking for next product', [
                    'field' => $fieldName,
                    'current_step' => $currentStep,
                ]);

                // Get the created entity ID
                $entityId = $context->get('product_id') ?? $context->get('entity_id');

                if ($entityId) {
                    // Clear subflow state
                    $context->forget('active_subflow');

                    // Restore parent workflow's collected_data (contains items array)
                    $parentCollectedData = $context->get('parent_collected_data', []);
                    if (!empty($parentCollectedData)) {
                        $context->set('collected_data', $parentCollectedData);
                        Log::channel('ai-engine')->info('Restored parent collected_data after subflow', [
                            'field' => $fieldName,
                            'has_items' => isset($parentCollectedData['items']),
                        ]);
                    }

                    // Get current creation index and missing items
                    $index = $context->get("{$fieldName}_creation_index", 0);
                    $missing = $context->get("{$fieldName}_missing", []);
                    $validated = $context->get("{$fieldName}_validated", []);

                    // Add created entity to validated list
                    if (!empty($missing[$index])) {
                        // Fetch the complete entity from database to get all fields (including prices)
                        $modelClass = $config['model'] ?? null;
                        $createdEntity = null;

                        if ($modelClass && class_exists($modelClass)) {
                            try {
                                $createdEntity = $modelClass::find($entityId);
                            } catch (\Exception $e) {
                                Log::channel('ai-engine')->warning('Failed to fetch created entity', [
                                    'model' => $modelClass,
                                    'id' => $entityId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // Get collected data to preserve fields from subflow
                        $collectedData = $context->get('collected_data', []);
                        
                        Log::channel('ai-engine')->info('GenericEntityResolver: BEFORE merging subflow data', [
                            'field' => $fieldName,
                            'index' => $index,
                            'missing_item_before' => $missing[$index],
                            'collected_data' => $collectedData,
                            'required_fields' => $config['required_item_fields'] ?? [],
                            'include_fields' => $config['include_fields'] ?? [],
                        ]);
                        
                        // CRITICAL: Merge subflow collected data into missing item
                        // When subflow completes, collected fields need to be merged into the item
                        // Use requiredItemFields from config to know which fields to merge
                        $requiredFields = $config['required_item_fields'] ?? [];
                        $mergedFields = [];
                        foreach ($requiredFields as $field) {
                            if (isset($collectedData[$field]) && !isset($missing[$index][$field])) {
                                $missing[$index][$field] = $collectedData[$field];
                                $mergedFields[] = $field;
                            }
                        }
                        
                        // Also merge any includeFields that exist in collected_data
                        $includeFields = $config['include_fields'] ?? [];
                        foreach ($includeFields as $field) {
                            if (isset($collectedData[$field]) && !isset($missing[$index][$field])) {
                                $missing[$index][$field] = $collectedData[$field];
                                $mergedFields[] = $field;
                            }
                        }
                        
                        Log::channel('ai-engine')->info('GenericEntityResolver: AFTER merging subflow data', [
                            'field' => $fieldName,
                            'missing_item_after' => $missing[$index],
                            'merged_fields' => $mergedFields,
                            'has_sale_price' => isset($missing[$index]['sale_price']),
                        ]);
                        
                        if ($createdEntity) {
                            // Build entity data dynamically from includeFields config
                            $entityData = ['id' => $entityId];
                            $includeFields = $config['include_fields'] ?? [];
                            
                            // If includeFields specified, use those fields
                            if (!empty($includeFields)) {
                                foreach ($includeFields as $field) {
                                    if (isset($createdEntity->$field)) {
                                        $entityData[$field] = $createdEntity->$field;
                                    }
                                }
                            } else {
                                // Fallback: include all non-system attributes from entity
                                $attributes = $createdEntity->getAttributes();
                                foreach ($attributes as $key => $value) {
                                    // Skip system fields using pattern matching
                                    if (preg_match('/(_at|^id)$/', $key)) {
                                        continue;
                                    }
                                    $entityData[$key] = $value;
                                }
                            }
                            
                            // CRITICAL: User-entered prices should take precedence over database prices
                            // First merge entity data into missing item (database fills gaps)
                            // Then user input overwrites database values
                            $createdItem = array_merge($entityData, $missing[$index]);
                            
                            // Ensure ID is always from the created/found entity
                            $createdItem['id'] = $entityId;
                        } else {
                            // Fallback to original behavior if entity fetch fails
                            $createdItem = array_merge($missing[$index], ['id' => $entityId]);
                        }

                        $validated[] = $createdItem;
                        $context->set("{$fieldName}_validated", $validated);
                    }

                    // Move to next product
                    $nextIndex = $index + 1;

                    if ($nextIndex < count($missing)) {
                        // More products to create
                        $context->set("{$fieldName}_creation_index", $nextIndex);
                        $context->set("{$fieldName}_creation_step", 'create_entity');

                        Log::channel('ai-engine')->info('Moving to next product', [
                            'next_index' => $nextIndex,
                            'total_missing' => count($missing),
                        ]);

                        return $this->askForEntityDetails($fieldName, $config, $missing, $nextIndex, $context);
                    } else {
                        // All products created
                        $context->set($fieldName, $validated);
                        
                        // Store only under entity name (e.g., 'products') - single source of truth
                        $collectedData = $context->get('collected_data', []);
                        $identifierField = $config['identifier_field'] ?? $fieldName;
                        
                        // Remove old identifier field data to avoid duplication
                        if ($identifierField !== $fieldName && isset($collectedData[$identifierField])) {
                            unset($collectedData[$identifierField]);
                        }
                        
                        // Store only under entity name (single source of truth)
                        $collectedData[$fieldName] = $validated;
                        $context->set('collected_data', $collectedData);
                        
                        Log::channel('ai-engine')->info('Updated collected_data with validated items', [
                            'field' => $fieldName,
                            'identifier_field' => $identifierField,
                            'validated_count' => count($validated),
                            'has_prices' => isset($validated[0]['sale_price']) || isset($validated[0]['price']),
                        ]);

                        // Clear creation state
                        $context->forget("{$fieldName}_creation_step");
                        $context->forget("{$fieldName}_creation_index");
                        $context->forget("{$fieldName}_missing");
                        $context->forget("{$fieldName}_validated");

                        $entityName = $config['display_name'] ?? $this->getFriendlyEntityName($fieldName, $config);
                        return ActionResult::success(
                            message: "All {$entityName} created successfully",
                            data: [$fieldName => $validated]
                        );
                    }
                }
            }
        }

        $validated = [];
        $missing = [];

        $searchFields = $config['search_fields'] ?? [];
        
        foreach ($items as $item) {
            // Handle case where item is a string instead of array
            if (is_string($item)) {
                $item = \LaravelAIEngine\Services\AI\FieldDetector::stringToArrayItem($item, $config);
            }

            // Ensure $item is an array
            if (!is_array($item)) {
                continue;
            }

            // Use config-driven field detection
            $fieldNames = \LaravelAIEngine\Services\AI\FieldDetector::getFieldNames($config);
            
            // Get identifier from config field or detect dynamically
            $identifier = $item[$fieldNames['identifier']] ?? null;
            if (empty($identifier)) {
                $identifier = \LaravelAIEngine\Services\AI\FieldDetector::detectIdentifier($item, $searchFields) ?? '';
            }
            
            // Get quantity from config field, default to 1
            $quantity = $item[$fieldNames['quantity']] ?? 1;

            // Skip if no identifier found
            if (empty($identifier)) {
                $fieldNames = \LaravelAIEngine\Services\AI\FieldDetector::getFieldNames($config);
                $missing[] = array_merge([
                    $fieldNames['identifier'] => 'Unknown',
                    $fieldNames['quantity'] => $quantity,
                ], $item);
                continue;
            }

            // Search for existing
            $entity = $this->searchEntity($modelClass, $identifier, $searchFields, $filters);

            if ($entity) {
                // Merge entity data from database with user input
                // User input takes precedence, but include entity fields if not provided

                // Get base fields from config (fields that are always included)
                // Default: id, name (from entity), quantity (from user input)
                $baseFields = $config['base_fields'] ?? ['id', 'name'];

                $entityData = [];

                // Add base fields from entity
                foreach ($baseFields as $field) {
                    if ($field === 'id') {
                        $entityData['id'] = $entity->id;
                    } elseif ($field === 'name') {
                        $entityData['name'] = $entity->name ?? $identifier;
                    } elseif (isset($entity->$field)) {
                        $entityData[$field] = $entity->$field;
                    }
                }

                // Always include quantity from user input if provided
                if (isset($quantity)) {
                    $entityData['quantity'] = $quantity;
                }

                // Include additional fields from entity based on config
                // Only include if not already set (don't overwrite collected values)
                $includeFields = $config['include_fields'] ?? [];

                foreach ($includeFields as $field) {
                    if (!isset($item[$field]) && !isset($entityData[$field]) && isset($entity->$field)) {
                        $entityData[$field] = $entity->$field;
                    }
                }

                // Merge with user input (user input takes precedence)
                $validated[] = array_merge($entityData, $item);
            } else {
                $fieldNames = \LaravelAIEngine\Services\AI\FieldDetector::getFieldNames($config);
                $missing[] = array_merge([
                    $fieldNames['identifier'] => $identifier,
                    $fieldNames['quantity'] => $quantity,
                ], $item);
            }
        }

        if (empty($missing)) {
            $context->set($fieldName, $validated);
            
            // Store in collected_data under entity name (single source of truth)
            $collectedData = $context->get('collected_data', []);
            $identifierField = $config['identifier_field'] ?? $fieldName;
            
            // Remove old identifier field data to avoid duplication
            if ($identifierField !== $fieldName && isset($collectedData[$identifierField])) {
                unset($collectedData[$identifierField]);
            }
            
            $collectedData[$fieldName] = $validated;
            $context->set('collected_data', $collectedData);
            
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
     * Uses exact match first, then falls back to fuzzy match
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

        // First, try exact match (case-insensitive)
        $exactQuery = clone $query;
        $exactQuery->where(function($q) use ($searchFields, $identifier) {
            foreach ($searchFields as $field) {
                $q->orWhereRaw("LOWER({$field}) = ?", [strtolower($identifier)]);
            }
        });

        $exactMatch = $exactQuery->first();
        if ($exactMatch) {
            Log::channel('ai-engine')->info('Exact match found', [
                'model' => class_basename($modelClass),
                'identifier' => $identifier,
                'found_id' => $exactMatch->id,
            ]);
            return $exactMatch;
        }

        // No exact match found - return null to trigger creation flow
        // Don't do fuzzy matching for products to avoid wrong matches
        Log::channel('ai-engine')->info('No exact match found, will trigger creation', [
            'model' => class_basename($modelClass),
            'identifier' => $identifier,
        ]);

        return null;
    }

    /**
     * Find similar entities for duplicate detection using AI-powered matching
     */
    private function findSimilarEntities(string $modelClass, $identifier, array $searchFields, $filters = null)
    {
        $query = $modelClass::query();

        Log::channel('ai-engine')->info('🔍 findSimilarEntities: Building query', [
            'model' => $modelClass,
            'identifier' => $identifier,
            'search_fields' => $searchFields,
            'has_filters' => !empty($filters),
        ]);

        // Apply filters
        if ($filters) {
            if (is_callable($filters)) {
                try {
                    $filters($query);
                    Log::channel('ai-engine')->info('🔍 Applied callable filters successfully');
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->error('🔍 Filter application failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif (is_array($filters)) {
                foreach ($filters as $field => $value) {
                    $query->where($field, $value);
                }
                Log::channel('ai-engine')->info('🔍 Applied array filters', ['filters' => $filters]);
            }
        }

        // Get broader set of candidates (cast wider net)
        $query->where(function($q) use ($searchFields, $identifier) {
            foreach ($searchFields as $field) {
                $q->orWhere($field, 'LIKE', "%{$identifier}%");
            }
        });

        $candidates = $query->limit(20)->get(); // Get more candidates for AI filtering

        Log::channel('ai-engine')->info('🔍 Initial candidates found', [
            'count' => $candidates->count(),
        ]);

        // If no candidates, return empty
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        // Use AI to intelligently rank and filter candidates
        $rankedResults = $this->rankDuplicatesWithAI($identifier, $candidates, $searchFields);

        Log::channel('ai-engine')->info('🔍 AI-ranked results', [
            'count' => $rankedResults->count(),
            'results' => $rankedResults->map(fn($e) => [
                'id' => $e->id,
                'name' => $e->name ?? 'N/A',
                'similarity_score' => $e->similarity_score ?? 'N/A',
            ])->toArray(),
        ]);

        return $rankedResults;
    }

    /**
     * Use AI to rank duplicate candidates by similarity
     * Returns top 5 most relevant matches with similarity scores
     */
    private function rankDuplicatesWithAI($identifier, $candidates, array $searchFields)
    {
        try {
            // Build context for AI
            $candidatesData = $candidates->map(function($entity) use ($searchFields) {
                $data = ['id' => $entity->id];
                foreach ($searchFields as $field) {
                    if (isset($entity->$field)) {
                        $data[$field] = $entity->$field;
                    }
                }
                return $data;
            })->toArray();

            $prompt = "You are a duplicate detection system. Analyze the following candidates and rank them by similarity to the search query.\n\n";
            $prompt .= "Search Query: \"{$identifier}\"\n\n";
            $prompt .= "Candidates:\n";
            $prompt .= json_encode($candidatesData, JSON_PRETTY_PRINT) . "\n\n";
            $prompt .= "Instructions:\n";
            $prompt .= "- Consider fuzzy matching (typos, abbreviations, variations)\n";
            $prompt .= "- Consider semantic similarity (different formats of same entity)\n";
            $prompt .= "- Consider partial matches\n";
            $prompt .= "- Return ONLY the top 5 most similar candidates\n";
            $prompt .= "- Assign a similarity score (0-100) to each\n";
            $prompt .= "- Return as JSON array: [{\"id\": 1, \"score\": 95, \"reason\": \"exact match\"}, ...]\n";
            $prompt .= "- Order by score descending\n\n";
            $prompt .= "Response (JSON only):";

            // TODO: Enable AI ranking once caching issues resolved
            // For now, use intelligent fallback ranking
            return $this->rankDuplicatesFallback($identifier, $candidates, $searchFields);

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI duplicate ranking failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return $this->rankDuplicatesFallback($identifier, $candidates, $searchFields);
        }
    }

    /**
     * Intelligent fallback ranking when AI is unavailable
     * Uses multiple scoring factors for better accuracy than simple LIKE
     */
    private function rankDuplicatesFallback($identifier, $candidates, array $searchFields)
    {
        $identifier = strtolower(trim($identifier));

        $scored = $candidates->map(function($entity) use ($identifier, $searchFields) {
            $maxScore = 0;
            $bestField = null;

            foreach ($searchFields as $field) {
                if (!isset($entity->$field)) continue;

                $value = strtolower(trim($entity->$field));
                $score = $this->calculateSimilarityScore($identifier, $value);

                if ($score > $maxScore) {
                    $maxScore = $score;
                    $bestField = $field;
                }
            }

            // Attach score to entity
            $entity->similarity_score = $maxScore;
            $entity->matched_field = $bestField;

            return $entity;
        });

        // Filter out low scores (below 30%) and sort by score
        $filtered = $scored->filter(function($entity) {
            return $entity->similarity_score >= 30;
        })->sortByDesc('similarity_score')->take(5);

        return $filtered->values();
    }

    /**
     * Calculate similarity score between two strings
     * Uses multiple algorithms for better accuracy
     */
    private function calculateSimilarityScore(string $search, string $target): int
    {
        // Exact match
        if ($search === $target) {
            return 100;
        }

        // Case-insensitive exact match
        if (strcasecmp($search, $target) === 0) {
            return 95;
        }

        // Contains exact substring
        if (str_contains($target, $search)) {
            return 85;
        }

        // Levenshtein distance (typo tolerance)
        $levenshtein = levenshtein($search, $target);
        $maxLength = max(strlen($search), strlen($target));
        $levenshteinScore = (1 - ($levenshtein / $maxLength)) * 100;

        // Similar text percentage
        similar_text($search, $target, $similarityPercent);

        // Word-based matching (handles reordering)
        $searchWords = explode(' ', $search);
        $targetWords = explode(' ', $target);
        $matchingWords = count(array_intersect($searchWords, $targetWords));
        $wordScore = ($matchingWords / max(count($searchWords), count($targetWords))) * 100;

        // Take the best score from all algorithms
        return (int) max($levenshteinScore, $similarityPercent, $wordScore);
    }

    /**
     * Ask user about duplicate entities
     */
    private function askAboutDuplicates(string $fieldName, $duplicates, UnifiedActionContext $context, array $config = []): ActionResult
    {
        $entityName = class_basename($duplicates->first());

        if ($duplicates->count() === 1) {
            $entity = $duplicates->first();
            $context->set("{$fieldName}_found_duplicate", $entity);

            $message = "Found existing {$entityName}: **{$entity->name}**";

            // Add similarity score if available
            if (isset($entity->similarity_score)) {
                $message .= " (Match: {$entity->similarity_score}%)";
            }

            // Add additional identifying info from display_fields config
            $displayFields = $config['display_fields'] ?? [];
            foreach ($displayFields as $field) {
                if (isset($entity->$field) && $entity->$field) {
                    $message .= " - {$entity->$field}";
                    break; // Only show first available field
                }
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

            // Add similarity score if available
            if (isset($entity->similarity_score)) {
                $message .= " ({$entity->similarity_score}% match)";
            }

            // Add additional identifying info from display_fields config
            $displayFields = $config['display_fields'] ?? [];
            foreach ($displayFields as $field) {
                if (isset($entity->$field) && $entity->$field) {
                    $message .= " - {$entity->$field}";
                    break; // Only show first available field
                }
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
     * Handle user's choice about duplicate entities with AI interpretation
     */
    private function handleDuplicateChoice(string $fieldName, array $config, UnifiedActionContext $context): ActionResult
    {
        // Extract last user message from conversation history
        $conversationHistory = $context->conversationHistory ?? [];
        $lastMessage = '';
        if (!empty($conversationHistory)) {
            $lastUserMessages = array_filter($conversationHistory, fn($msg) => ($msg['role'] ?? '') === 'user');
            if (!empty($lastUserMessages)) {
                $lastMsg = end($lastUserMessages);
                $lastMessage = $lastMsg['content'] ?? '';
            }
        }

        $duplicates = $context->get("{$fieldName}_found_duplicates");
        $singleDuplicate = $context->get("{$fieldName}_found_duplicate");

        $maxOptions = $duplicates ? (is_array($duplicates) ? count($duplicates) : $duplicates->count()) : 1;

        // Use AI to interpret user's choice
        $interpretation = $this->intelligentService->interpretDuplicateChoice($lastMessage, $maxOptions);

        if ($interpretation) {
            if ($interpretation['action'] === 'use') {
                // User wants to use an existing entity
                $entity = null;

                if ($duplicates && isset($interpretation['index'])) {
                    $entity = $duplicates[$interpretation['index']] ?? null;
                } elseif ($singleDuplicate) {
                    $entity = $singleDuplicate;
                }

                if ($entity) {
                    $context->forget("{$fieldName}_duplicate_choice_pending");
                    $context->forget("{$fieldName}_found_duplicate");
                    $context->forget("{$fieldName}_found_duplicates");

                    return ActionResult::success(
                        message: "Using existing " . class_basename($entity) . ": {$entity->name}",
                        data: [$fieldName => $entity->id, 'entity' => $entity]
                    );
                }
            } elseif ($interpretation['action'] === 'create') {
                // User wants to create new
                $context->forget("{$fieldName}_duplicate_choice_pending");
                $context->forget("{$fieldName}_found_duplicate");
                $context->forget("{$fieldName}_found_duplicates");

                $identifier = $context->get("{$fieldName}_identifier", '');
                return $this->startInteractiveCreation($fieldName, $config, $identifier, $context);
            }
        }

        // Could not interpret - ask for clarification
        return ActionResult::needsUserInput(
            message: "I didn't understand that. Please reply with:\n- 'use' or 'yes' to use the existing entity\n- A number (1-{$maxOptions}) to select a specific one\n- 'new' or 'create' to create a new one"
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

        // Check if we should use subflow with step prefixing
        if (!empty($config['subflow']) && class_exists($config['subflow'])) {
            Log::channel('ai-engine')->info('Starting entity creation via subflow with step prefixing', [
                'field' => $fieldName,
                'subflow' => $config['subflow'],
                'identifier' => $identifier,
            ]);

            // This will be handled by continueEntityCreation after user confirms
            $context->set("{$fieldName}_creation_step", 'ask_create');
            $context->set("{$fieldName}_identifier", $identifier);
            $context->set("{$fieldName}_use_subflow", true);

            Log::channel('ai-engine')->info('Context variables set for creation', [
                'field' => $fieldName,
                'creation_step_key' => "{$fieldName}_creation_step",
                'creation_step_value' => 'ask_create',
                'identifier_key' => "{$fieldName}_identifier",
                'identifier_value' => $identifier,
                'context_has_creation_step' => !empty($context->get("{$fieldName}_creation_step")),
            ]);

            return ActionResult::needsUserInput(
                message: "{$entityName} '{$identifier}' doesn't exist. Would you like to create it? (yes/no)"
            );
        }

        // Regular interactive creation without subflow
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

        // If user is being asked to create, check their response
        if ($step === 'ask_create') {
            // Get the last message from conversation history
            $conversationHistory = $context->conversationHistory ?? [];
            $lastMessage = '';
            if (!empty($conversationHistory)) {
                $lastUserMessage = array_filter($conversationHistory, fn($msg) => ($msg['role'] ?? '') === 'user');
                if (!empty($lastUserMessage)) {
                    $lastMsg = end($lastUserMessage);
                    $lastMessage = $lastMsg['content'] ?? '';
                }
            }

            $identifier = $context->get("{$fieldName}_identifier", '');
            
            // Use IntentAnalysisService to determine user intent
            $intentAnalysis = app(\LaravelAIEngine\Services\IntentAnalysisService::class)->analyzeMessageIntent($lastMessage);
            $isConfirmation = $intentAnalysis['intent'] === 'confirm';
            $isDecline = $intentAnalysis['intent'] === 'decline';

            Log::channel('ai-engine')->info('Checking user confirmation response', [
                'step' => $step,
                'response' => $lastMessage,
                'intent' => $intentAnalysis['intent'],
                'field' => $fieldName,
                'identifier' => $identifier,
                'has_conversation_history' => !empty($conversationHistory),
            ]);

            if ($isConfirmation) {
                // User confirmed - proceed with entity creation

                Log::channel('ai-engine')->info('User confirmed entity creation', [
                    'field' => $fieldName,
                    'identifier' => $identifier,
                ]);

                // Validate identifier before creating
                if (empty($identifier)) {
                    Log::channel('ai-engine')->error('Cannot create entity - identifier is empty', [
                        'field' => $fieldName,
                    ]);

                    // Clear creation step
                    $context->forget("{$fieldName}_creation_step");
                    $context->forget("{$fieldName}_identifier");

                    return ActionResult::failure(
                        error: "Cannot create entity - identifier is missing"
                    );
                }

                // Clear creation step AFTER getting the identifier
                $context->forget("{$fieldName}_creation_step");
                $context->forget("{$fieldName}_identifier");
                $useSubflow = $context->get("{$fieldName}_use_subflow", false);
                $context->forget("{$fieldName}_use_subflow");

                // Check if we should use subflow with step prefixing
                if ($useSubflow && !empty($config['subflow']) && class_exists($config['subflow'])) {
                    return $this->startEntitySubflow($fieldName, $config, $identifier, $context);
                }

                // No subflow - use auto creation
                return $this->createEntityAuto($fieldName, $config, $identifier, $context);
            } elseif ($isDecline) {
                // User declined
                $context->forget("{$fieldName}_creation_step");
                $context->forget("{$fieldName}_identifier");

                return ActionResult::failure(
                    error: "Entity creation cancelled by user"
                );
            }
        }

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
            // Get model instance to inspect fillable fields
            $model = new $modelClass();
            $fillable = $model->getFillable();

            // Build data array dynamically based on model's fillable fields
            $data = [];

            // Add identifier - check common field names
            $identifierField = $config['identifier_field'] ?? null;
            if (!$identifierField) {
                // Auto-detect identifier field from fillable
                $possibleNames = ['name', 'title', 'label', 'identifier'];
                foreach ($possibleNames as $possibleName) {
                    if (in_array($possibleName, $fillable)) {
                        $identifierField = $possibleName;
                        break;
                    }
                }
            }

            if ($identifierField && in_array($identifierField, $fillable)) {
                $data[$identifierField] = $identifier;
            }

            // Add workspace field if model has one
            $workspaceFields = ['workspace_id', 'workspace'];
            foreach ($workspaceFields as $workspaceField) {
                if (in_array($workspaceField, $fillable)) {
                    $data[$workspaceField] = getActiveWorkSpace() ?: 1;
                    break;
                }
            }

            // Add creator field if model has one
            $creatorFields = ['created_by', 'creator_id', 'user_id'];
            foreach ($creatorFields as $creatorField) {
                if (in_array($creatorField, $fillable)) {
                    $data[$creatorField] = creatorId();
                    break;
                }
            }

            // Merge with defaults (defaults can override auto-detected values)
            $data = array_merge($data, $defaults);

            Log::channel('ai-engine')->info('Creating entity automatically', [
                'model' => $modelClass,
                'data' => $data,
                'fillable' => $fillable,
            ]);

            $entity = $modelClass::create($data);

            Log::channel('ai-engine')->info('Entity created successfully', [
                'model' => $modelClass,
                'id' => $entity->id,
            ]);

            return ActionResult::success(
                message: class_basename($modelClass) . " created",
                data: [$fieldName => $entity->id, 'entity' => $entity]
            );
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Entity creation failed', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);

            return ActionResult::failure(
                error: "Failed to create " . class_basename($modelClass) . ": " . $e->getMessage()
            );
        }
    }

    /**
     * Get friendly entity name for display using rule-based transformation
     * No AI calls - fast and deterministic
     */
    private function getFriendlyEntityName(string $fieldName, array $config): string
    {
        // Check static cache first (in-memory, no expiration)
        static $cache = [];
        if (isset($cache[$fieldName])) {
            return $cache[$fieldName];
        }

        // Check if custom friendly name is provided in config
        if (isset($config['friendly_name'])) {
            $cache[$fieldName] = $config['friendly_name'];
            return $config['friendly_name'];
        }

        // Rule-based transformation (no AI needed)
        $friendly = $this->transformFieldNameToFriendly($fieldName);

        // Cache in memory
        $cache[$fieldName] = $friendly;

        return $friendly;
    }

    /**
     * Transform field name to friendly plural form using configurable rules
     */
    private function transformFieldNameToFriendly(string $fieldName): string
    {
        // Remove common suffixes like '_id', '_ids'
        $friendly = preg_replace('/_ids?$/', '', $fieldName);

        // Convert underscores to spaces
        $friendly = str_replace('_', ' ', $friendly);

        // If already ends with 's', it's likely already plural
        if (str_ends_with($friendly, 's')) {
            return $friendly;
        }

        // Get custom plural rules from config
        $customRules = config('ai-engine.plural_rules', []);

        // Check custom rules first
        foreach ($customRules as $singular => $plural) {
            if (str_ends_with($friendly, $singular)) {
                return substr($friendly, 0, -strlen($singular)) . $plural;
            }
        }

        // Apply standard English pluralization rules
        return $this->applyStandardPluralizationRules($friendly);
    }

    /**
     * Apply standard English pluralization rules
     */
    private function applyStandardPluralizationRules(string $word): string
    {
        // Words ending in 'y' (preceded by consonant) -> 'ies'
        if (preg_match('/[^aeiou]y$/', $word)) {
            return substr($word, 0, -1) . 'ies';
        }

        // Words ending in 'ss', 'sh', 'ch', 'x', 'z' -> add 'es'
        if (preg_match('/(ss|sh|ch|x|z)$/', $word)) {
            return $word . 'es';
        }

        // Words ending in 'f' or 'fe' -> 'ves'
        if (preg_match('/fe?$/', $word)) {
            return preg_replace('/fe?$/', 'ves', $word);
        }

        // Simple plural: add 's'
        return $word . 's';
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
        // Use display_name from config, or convert field name to friendly name
        $entityName = $config['display_name'] ?? $this->getFriendlyEntityName($fieldName, $config);
        $context->set("{$fieldName}_creation_step", 'ask_create');
        $context->set("{$fieldName}_creation_index", 0);

        // Deduplicate missing items by name to avoid showing duplicates
        $uniqueMissing = [];
        $seenNames = [];
        foreach ($missing as $item) {
            $itemName = $this->extractEntityNameWithAI($item, $entityName);
            if (!in_array($itemName, $seenNames)) {
                $seenNames[] = $itemName;
                $uniqueMissing[] = $item;
            }
        }

        $message = "The following {$entityName} don't exist:\n\n";
        foreach ($uniqueMissing as $item) {
            $itemName = $this->extractEntityNameWithAI($item, $entityName);

            $message .= "• {$itemName}";
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

        // If user just confirmed to create all entities
        if ($step === 'ask_create') {
            // Check user's response using AI to understand intent
            $conversationHistory = $context->conversationHistory ?? [];
            $lastUserMessage = array_filter($conversationHistory, fn($msg) => ($msg['role'] ?? '') === 'user');

            if (!empty($lastUserMessage)) {
                $lastMsg = end($lastUserMessage);
                $userResponse = trim($lastMsg['content'] ?? '');

                // Use AI to determine if user wants to proceed, decline, or modify
                $entityName = $config['display_name'] ?? $this->getFriendlyEntityName($fieldName, $config);
                $userIntent = $this->determineUserIntent($userResponse, "create {$entityName}");

                if ($userIntent === 'decline') {
                    // Clear creation state
                    $context->forget("{$fieldName}_creation_step");
                    $context->forget("{$fieldName}_creation_index");
                    $context->forget("{$fieldName}_missing");
                    $context->forget("{$fieldName}_validated");

                    return ActionResult::failure(
                        error: "User declined to create {$entityName}"
                    );
                }
                
                if ($userIntent === 'modify') {
                    // User wants to replace/change the items - extract new items from their response
                    Log::channel('ai-engine')->info('User wants to modify items', [
                        'field' => $fieldName,
                        'user_response' => $userResponse,
                    ]);
                    
                    // Clear ALL creation and entity state to allow fresh re-resolution
                    $context->forget("{$fieldName}_creation_step");
                    $context->forget("{$fieldName}_creation_index");
                    $context->forget("{$fieldName}_missing");
                    $context->forget("{$fieldName}_validated");
                    $context->forget("{$fieldName}_id");
                    $context->forget($fieldName);
                    
                    // Extract new items from user response using AI
                    $newItems = $this->extractItemsFromModification($userResponse, $fieldName, $config);
                    
                    if (!empty($newItems)) {
                        // REPLACE collected_data items completely (not merge)
                        $collectedData = $context->get('collected_data', []);
                        $identifierField = $config['identifier_field'] ?? $fieldName;
                        
                        // Clear old items completely
                        unset($collectedData[$identifierField]);
                        unset($collectedData[$fieldName]);
                        
                        // Set new items as the only items
                        $collectedData[$fieldName] = $newItems;
                        $context->set('collected_data', $collectedData);
                        
                        Log::channel('ai-engine')->info('Replaced items with new items from modification', [
                            'field' => $fieldName,
                            'new_items' => $newItems,
                            'identifier_field' => $identifierField,
                        ]);
                        
                        // Re-resolve entities with new items
                        return $this->resolveEntities($fieldName, $config, $newItems, $context);
                    }
                    
                    // If extraction failed, ask user for specific products
                    return ActionResult::needsUserInput(
                        message: "Please specify which {$entityName} you'd like to use:"
                    );
                }
            }

            // Move to first entity creation
            $context->set("{$fieldName}_creation_step", 'create_entity');
            $context->set("{$fieldName}_creation_index", 0);
            return $this->askForEntityDetails($fieldName, $config, $missing, 0, $context);
        }

        // If we're creating entities, handle the response
        if ($step === 'create_entity') {
            // Entity details were provided, create it
            $currentItem = $missing[$index] ?? null;
            if (!$currentItem) {
                return ActionResult::failure(error: "Entity not found at index {$index}");
            }

            // Create the entity (this would be handled by entity creation service)
            // For now, just move to next entity
            $nextIndex = $index + 1;

            if ($nextIndex < count($missing)) {
                // More entities to create
                $context->set("{$fieldName}_creation_index", $nextIndex);
                return $this->askForEntityDetails($fieldName, $config, $missing, $nextIndex, $context);
            } else {
                // All entities created, merge with validated
                $validated = $context->get("{$fieldName}_validated", []);
                $allEntities = array_merge($validated, $missing);
                $context->set($fieldName, $allEntities);

                // Clear creation state
                $context->forget("{$fieldName}_creation_step");
                $context->forget("{$fieldName}_creation_index");
                $context->forget("{$fieldName}_missing");
                $context->forget("{$fieldName}_validated");

                $entityName = $config['display_name'] ?? $this->getFriendlyEntityName($fieldName, $config);
                return ActionResult::success(
                    message: "All {$entityName} created",
                    data: [$fieldName => $allEntities]
                );
            }
        }

        return ActionResult::failure(error: "Unknown creation step: {$step}");
    }

    /**
     * Ask for entity details during creation
     */
    private function askForEntityDetails(
        string $fieldName,
        array $config,
        array $missing,
        int $index,
        UnifiedActionContext $context
    ): ActionResult {
        $item = $missing[$index];
        $entityName = class_basename($config['model']);

        // Use AI to intelligently extract entity name
        $itemName = $this->extractEntityNameWithAI($item, $entityName);

        // Check if we should use subflow for this entity
        if (!empty($config['subflow']) && class_exists($config['subflow'])) {
            // Store the item being created
            $context->set("{$fieldName}_current_item", $item);
            $context->set("{$fieldName}_current_item_name", $itemName);

            // Use the same subflow logic as single entity creation
            return $this->startEntitySubflow($fieldName, $config, $itemName, $context, $item);
        }

        // No subflow - show creation prompt
        // Build message - check if there's a custom prompt callback
        if (isset($config['creation_prompt']) && is_callable($config['creation_prompt'])) {
            $message = $config['creation_prompt']($item, $itemName, $entityName);
        } else {
            // Default generic prompt
            $message = "{$entityName}: {$itemName}\n\n";
            $message .= "Please provide additional details for this {$entityName}.";
        }

        return ActionResult::needsUserInput(message: $message);
    }

    /**
     * Start entity creation subflow
     */
    private function startEntitySubflow(
        string $fieldName,
        array $config,
        string $identifier,
        UnifiedActionContext $context,
        array $itemData = []
    ): ActionResult {
        $entityName = class_basename($config['model']);

        try {
            // Get parent workflow name for step prefix
            $parentWorkflowName = class_basename($context->get('current_workflow', 'Workflow'));

            // Create prefix: customer_invoice_flow or product_invoice_flow
            $stepPrefix = strtolower($entityName) . '_' . strtolower(str_replace('Workflow', '', $parentWorkflowName));

            Log::channel('ai-engine')->info('Starting entity subflow', [
                'subflow' => $config['subflow'],
                'step_prefix' => $stepPrefix,
                'entity' => $entityName,
                'identifier' => $identifier,
            ]);

            // Push current workflow context onto stack before starting subflow
            $workflowStack = $context->get('workflow_stack', []);
            $workflowStack[] = [
                'workflow' => $context->currentWorkflow ?? $context->get('current_workflow'),
                'step' => $context->currentStep,
                'active_subflow' => $context->get('active_subflow'),
                'collected_data' => $context->get('collected_data', []),
            ];
            $context->set('workflow_stack', $workflowStack);

            Log::channel('ai-engine')->info('Pushed workflow onto stack', [
                'stack_depth' => count($workflowStack),
                'current_step' => $context->currentStep,
                'current_workflow' => $context->currentWorkflow ?? $context->get('current_workflow'),
                'starting_subflow' => $config['subflow'],
            ]);

            // Pre-populate context with entity-specific data
            // IMPORTANT: Preserve parent workflow's collected_data by storing it
            $parentCollectedData = $context->get('collected_data', []);

            // CRITICAL: When starting a subflow for multiple entities (like products in an invoice),
            // clear stale entity-specific fields while preserving parent workflow's data.
            if ($config['multiple'] ?? false) {
                // Get entity fields to clear from the subflow (static call - no instantiation needed)
                $entityFieldsToClear = ['asking_for']; // Always clear asking_for

                $subflowClass = $config['subflow'];
                if (class_exists($subflowClass) && method_exists($subflowClass, 'getEntityFields')) {
                    $declaredFields = $subflowClass::getEntityFields();

                    // Add both field name and _id variant for each declared field
                    foreach ($declaredFields as $fieldName) {
                        $entityFieldsToClear[] = $fieldName;
                        $entityFieldsToClear[] = $fieldName . '_id';
                    }
                }

                // Clear entity-specific context keys
                foreach ($entityFieldsToClear as $field) {
                    $context->forget($field);
                }

                // Clear entity fields from collected_data while preserving parent fields
                foreach ($entityFieldsToClear as $field) {
                    unset($parentCollectedData[$field]);
                }
            }

            $context->set('parent_collected_data', $parentCollectedData);

            // Start with fresh collected_data for this specific entity
            // This ensures each product/entity gets its own data collection
            $newCollectedData = [];

            // Set the identifier in collected_data for the subflow
            // Use the identifier field name from config, or default to 'name'
            $identifierField = $config['identifier_field'] ?? 'name';
            
            // Check if we have extracted data from an object identifier
            $extractedData = $context->get("{$fieldName}_extracted_data", []);
            
            // If we have extracted data (identifier was an object), use all its fields
            if (!empty($extractedData)) {
                foreach ($extractedData as $key => $value) {
                    if (!empty($value)) {
                        $newCollectedData[$key] = $value;
                    }
                }
                // Clear the extracted data after using it
                $context->forget("{$fieldName}_extracted_data");
            } else {
                // For subflows, only set 'name' field, not the entity field itself
                // The entity field will be populated with full data when entity is resolved
                $newCollectedData['name'] = $identifier;
            }

            // Map item data fields to collected_data
            // This allows passing through data like price, quantity, etc. from parent workflow
            if (!empty($itemData) && is_array($itemData)) {
                foreach ($itemData as $key => $value) {
                    // Skip the identifier field itself to avoid duplication
                    if ($key !== $identifierField && !empty($value)) {
                        $newCollectedData[$key] = $value;
                    }
                }
            }

            // Set the fresh collected_data (replacing any previous data temporarily)
            $context->set('collected_data', $newCollectedData);

            Log::channel('ai-engine')->info('Starting subflow with fresh collected_data', [
                'entity' => $entityName,
                'identifier' => $identifier,
                'collected_data' => $newCollectedData,
                'parent_data_preserved' => !empty($parentCollectedData),
            ]);

            // Mark that we're in a subflow (for autoResolveEntity to detect)
            // Store parent's field name so merge knows where to put the data
            $context->set('active_subflow', [
                'workflow_class' => $config['subflow'],
                'field_name' => $fieldName,  // Parent workflow's field name (e.g., 'products')
                'parent_field_name' => $fieldName,  // Explicitly store for merge
                'entity_name' => $entityName,
                'step_prefix' => $stepPrefix,
            ]);

            // Instantiate subworkflow with step prefix (use injected AI service)
            $tools = null; // ToolRegistry is optional
            $subflowClass = $config['subflow'];
            $subworkflow = new $subflowClass($this->ai, $tools, $stepPrefix);

            // Get first step of subworkflow (with prefix applied)
            $firstStep = $subworkflow->getFirstStep();
            if (!$firstStep) {
                throw new \Exception("Subworkflow has no steps");
            }

            // Update context to point to subworkflow's first step
            $context->currentStep = $firstStep->getName();
            $context->set('current_workflow', $subflowClass);

            Log::channel('ai-engine')->info('Subflow started, transitioning to first step', [
                'first_step' => $firstStep->getName(),
                'step_prefix' => $stepPrefix,
            ]);

            // Execute the first step
            $result = $firstStep->run($context);

            // Return the result - this keeps us in the subflow
            // The parent step will complete with this result, but currentStep
            // now points to the subflow, so next execution will continue in subflow
            return $result;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Subflow execution failed', [
                'subflow' => $config['subflow'],
                'entity' => $entityName,
                'field' => $fieldName,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Instead of failing the entire workflow, return a user input request
            // This allows the workflow to continue and ask the user what to do
            return ActionResult::needsUserInput(
                message: "Unable to automatically create {$entityName}. Error: {$e->getMessage()}\n\nWould you like to try again or skip this step?",
                metadata: ['error' => $e->getMessage(), 'field' => $fieldName]
            );
        }
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
                // Use AI to intelligently extract entity name
                $entityName = class_basename($modelClass);
                $itemName = $this->extractEntityNameWithAI($item, $entityName);

                $data = array_merge([
                    'name' => $itemName,
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

    /**
     * Use AI to intelligently extract entity name from item data
     * This replaces hardcoded key fallbacks with intelligent extraction
     */
    private function extractEntityNameWithAI(array $item, string $entityType): string
    {
        // Quick check: if there's a clear 'name' field that's not empty, use it
        if (!empty($item['name'])) {
            return $item['name'];
        }

        // TODO: Enable AI extraction once caching issues are resolved
        // For now, use intelligent fallback that's still better than hardcoded keys
        return $this->intelligentFallbackExtraction($item, $entityType);
    }

    /**
     * Intelligent fallback extraction - smarter than hardcoded keys
     * Analyzes the data structure to find the most likely name field
     */
    private function intelligentFallbackExtraction(array $item, string $entityType): string
    {
        // Priority 1: Common name fields
        $nameKeys = ['name', 'title', 'label', 'identifier'];
        foreach ($nameKeys as $key) {
            if (!empty($item[$key])) {
                return $this->normalizeEntityName($item[$key]);
            }
        }

        // Priority 2: Entity-type specific keys (e.g., 'product' for products)
        $entitySpecificKey = strtolower($entityType);
        if (!empty($item[$entitySpecificKey])) {
            return $this->normalizeEntityName($item[$entitySpecificKey]);
        }

        // Priority 3: Description field (extract first meaningful part)
        if (!empty($item['description'])) {
            $desc = $item['description'];
            // Take first sentence or first 50 chars
            $firstPart = explode('.', $desc)[0];
            $firstPart = explode(',', $firstPart)[0];
            return $this->normalizeEntityName(substr($firstPart, 0, 50));
        }

        // Priority 4: Any non-empty string value (excluding metadata fields)
        $excludeKeys = ['id', 'created_at', 'updated_at', 'workspace', 'created_by', 'quantity', 'price'];
        foreach ($item as $key => $value) {
            if (is_string($value) && !empty($value) && !in_array($key, $excludeKeys)) {
                return $this->normalizeEntityName($value);
            }
        }

        return "Unknown {$entityType}";
    }

    /**
     * Determine user intent using AI (accept/decline/modify)
     */
    private function determineUserIntent(string $userResponse, string $action): string
    {
        try {
            $userId = auth()->check() ? (string) auth()->id() : null;

            $prompt = "User was asked: 'Would you like to {$action}?'\n";
            $prompt .= "User responded: \"{$userResponse}\"\n\n";
            $prompt .= "Determine the user's intent:\n";
            $prompt .= "- 'accept' = user wants to proceed (yes, ok, sure, etc.)\n";
            $prompt .= "- 'decline' = user wants to cancel/stop (no, cancel, nevermind, etc.)\n";
            $prompt .= "- 'modify' = user wants to change/replace/update something (replace X with Y, change to Z, use A instead, etc.)\n\n";
            $prompt .= "Respond with ONLY one word: 'accept', 'decline', or 'modify'";

            $response = $this->ai->generate(new \LaravelAIEngine\DTOs\AIRequest(
                prompt:                                      $prompt,
                                                userId:      $userId,
                                                maxTokens:   10,
                                                temperature: 0
            ));

            $intent = strtolower(trim($response->getContent()));

            if (str_contains($intent, 'accept')) {
                return 'accept';
            } elseif (str_contains($intent, 'modify')) {
                return 'modify';
            }
            return 'decline';

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to determine user intent, using fallback', [
                'error' => $e->getMessage(),
            ]);

            // Fallback: simple heuristic if AI fails
            $lower = strtolower($userResponse);
            if (strlen($lower) <= 3 && (str_contains($lower, 'yes') || str_contains($lower, 'ok'))) {
                return 'accept';
            }
            // Check for modification keywords
            if (str_contains($lower, 'replace') || str_contains($lower, 'change') || str_contains($lower, 'use') || str_contains($lower, 'instead')) {
                return 'modify';
            }
            return 'decline';
        }
    }

    /**
     * Extract items from a modification request like "replace phones with Macbook"
     */
    private function extractItemsFromModification(string $userResponse, string $fieldName, array $config): array
    {
        try {
            $userId = auth()->check() ? (string) auth()->id() : null;
            $entityName = $config['display_name'] ?? $this->getFriendlyEntityName($fieldName, $config);

            $prompt = "User wants to modify their order. They said: \"{$userResponse}\"\n\n";
            $prompt .= "Extract the new {$entityName} they want to use.\n";
            $prompt .= "Return a JSON array of items with 'name' and 'quantity' fields.\n";
            $prompt .= "If quantity is not specified, use 1.\n";
            $prompt .= "Example: [{\"name\": \"Macbook Pro\", \"quantity\": 2}]\n\n";
            $prompt .= "Return ONLY the JSON array, no other text.";

            $response = $this->ai->generate(new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                userId: $userId,
                maxTokens: 200,
                temperature: 0
            ));

            $content = trim($response->getContent());
            
            // Clean up response - remove markdown code blocks if present
            $content = preg_replace('/^```json?\s*/i', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
            
            $items = json_decode($content, true);
            
            if (is_array($items) && !empty($items)) {
                // Normalize items
                return array_map(function($item) {
                    return [
                        'name' => $item['name'] ?? $item['product'] ?? 'Unknown',
                        'quantity' => (int) ($item['quantity'] ?? $item['qty'] ?? 1),
                    ];
                }, $items);
            }
            
            return [];

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to extract items from modification', [
                'error' => $e->getMessage(),
                'user_response' => $userResponse,
            ]);
            return [];
        }
    }

    /**
     * Normalize entity name - proper capitalization, trim, clean
     */
    private function normalizeEntityName(string $name): string
    {
        $name = trim($name);

        // Remove extra whitespace
        $name = preg_replace('/\s+/', ' ', $name);

        // Capitalize first letter of each word if all lowercase or all uppercase
        if ($name === strtolower($name) || $name === strtoupper($name)) {
            $name = ucwords(strtolower($name));
        }

        return $name;
    }
}
